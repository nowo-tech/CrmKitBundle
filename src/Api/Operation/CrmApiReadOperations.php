<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Operation;

use Nowo\CrmKitBundle\Api\CrmApiCapability;
use Nowo\CrmKitBundle\Api\CrmApiOperation;
use Nowo\CrmKitBundle\Api\CrmApiPrincipal;
use Nowo\CrmKitBundle\Api\CrmApiRefusal;
use Nowo\CrmKitBundle\Api\Input\CrmApiPageRequest;
use Nowo\CrmKitBundle\Api\Input\CrmApiSearchQuery;
use Nowo\CrmKitBundle\Api\Input\CrmApiSort;
use Nowo\CrmKitBundle\Api\Read\CrmApiItem;
use Nowo\CrmKitBundle\Api\Read\CrmApiList;
use Nowo\CrmKitBundle\Api\Read\CrmApiPage;
use Nowo\CrmKitBundle\Api\Read\CrmApiPresenter;
use Nowo\CrmKitBundle\Application\CrmReader;
use Nowo\CrmKitBundle\Application\Pipeline\Decimal;
use Nowo\CrmKitBundle\Application\RecordViews;
use Nowo\CrmKitBundle\Application\WriteSupport;
use Nowo\CrmKitBundle\Domain\Enum\ActivityKind;
use Nowo\CrmKitBundle\Domain\Enum\DealStatus;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Model\Activity;
use Nowo\CrmKitBundle\Domain\Model\Note;
use Nowo\CrmKitBundle\Domain\Read\ActivityView;
use Nowo\CrmKitBundle\Domain\Read\AffiliationView;
use Nowo\CrmKitBundle\Domain\Read\NoteView;
use Nowo\CrmKitBundle\Domain\Read\Pipeline\DealView;
use Nowo\CrmKitBundle\Domain\Service\ListQuery;
use Nowo\CrmKitBundle\Domain\Value\RecordId;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

use function array_map;
use function array_slice;
use function count;
use function in_array;
use function is_bool;
use function is_int;
use function is_string;
use function mb_strtolower;
use function strcmp;
use function usort;

/**
 * The read operations of the API (0.3.0, design §6.2): search, one company or contact, timeline, notes, consents,
 * tags, links, affiliations and identity. Every call runs the guard, reads through the same store queries and
 * visibility filter as the pages, and pages its lists with sealed keyset cursors. Stateless.
 */
final readonly class CrmApiReadOperations
{
    /**
     * @param list<string> $consentPurposes `gdpr.purposes`
     */
    public function __construct(
        private CrmApiGuard $guard,
        private CrmApiRecords $records,
        private WriteSupport $support,
        private CrmReader $reader,
        private RecordViews $views,
        private CrmApiCursorCodec $cursors,
        private CrmApiOperations $operations,
        private array $consentPurposes = [],
        private ?CrmApiPipeline $pipeline = null,
    ) {
    }

    /**
     * `crm_search`: active companies and contacts (companies first), keyset-paged.
     */
    public function search(CrmApiPrincipal $principal, TenantRef $tenant, CrmApiSearchQuery $query): CrmApiPage
    {
        $call = $this->guard->call($principal, $tenant, CrmApiOperation::Search);
        $call->require(CrmApiCapability::Read);
        if ($query->stage !== null && $this->pipeline === null) {
            throw CrmApiRefusal::malformed('stage');
        }
        $filter = CrmApiCursorCodec::filterHash($query->filters());
        $key    = $query->page->cursor === null ? null : $this->cursors->open($query->page->cursor, CrmApiOperation::Search, $filter);
        $phase  = is_int($key['p'] ?? null) ? $key['p'] : 0;
        $after  = is_string($key['v'] ?? null) && is_string($key['id'] ?? null) ? ['v' => $key['v'], 'id' => $key['id']] : null;
        if ($key !== null && ($phase < 0 || $phase >= count($query->types) || $after === null)) {
            throw CrmApiRefusal::of('INPUT_003');
        }
        $support   = $this->support->as($call->caller);
        $byUpdated = $query->sort === CrmApiSort::Updated;
        $limit     = $query->page->limit;
        $owners    = $call->access->visibleOwners($call->context);
        $found     = [];
        $next      = null;
        for ($p = $phase; $p < count($query->types) && $next === null; ++$p) {
            $type = $query->types[$p];
            $list = new ListQuery($type, $query->text, $query->tagId?->value, $query->ownerId?->value, role: $query->role?->value);
            $rows = $support->read(static fn (): array => $support->store->keyset($call->context->tenant, $list, $byUpdated, $p === $phase ? $after : null, $limit - count($found) + 1, $owners, $query->stage?->canonical()));
            foreach ($rows as $row) {
                if (count($found) === $limit) {
                    $last = $found[$limit - 1];
                    $next = $this->cursors->seal(CrmApiOperation::Search, $filter, ['p' => $last[0], 'v' => $support->store->keyOf($last[1], $byUpdated), 'id' => $last[1]->getId()]);

                    break;
                }
                $found[] = [$p, $row];
            }
        }
        $items = [];
        foreach ($found as [$p, $row]) {
            $items[] = CrmApiPresenter::hit($this->views->many($call->context->tenant, $row::type(), [$row])[0]);
        }

        return new CrmApiPage($items, $next);
    }

    /**
     * `crm_company_get`: one company (a merged id ⇒ its tombstone).
     */
    public function company(CrmApiPrincipal $principal, TenantRef $tenant, RecordId $id): CrmApiItem
    {
        return $this->record(CrmApiOperation::CompanyGet, SubjectType::Company, $principal, $tenant, $id);
    }

    /**
     * `crm_contact_get`: one contact (never its consents).
     */
    public function contact(CrmApiPrincipal $principal, TenantRef $tenant, RecordId $id): CrmApiItem
    {
        return $this->record(CrmApiOperation::ContactGet, SubjectType::Contact, $principal, $tenant, $id);
    }

    /**
     * A record as {@see CrmApiPresenter::record()}, for the operations that answer one.
     *
     * @internal
     */
    public function present(CrmApiCall $call, SubjectType $type, string $id): CrmApiItem
    {
        $view      = CrmApiErrors::run(fn () => $this->reader->as($call->caller)->record($type, $id));
        $lifecycle = [];
        $actions   = [];
        $amounts   = null;
        $hidden    = false;
        if ($this->pipeline !== null) {
            $lifecycle = CrmApiErrors::run(fn (): array => $this->pipeline->partyStages->as($call->caller)->stages($type, $id));
            $actions   = CrmApiErrors::run(fn (): array => $this->pipeline->nextActions->as($call->caller)->list($type, $id, true));
            if ($call->can(CrmApiCapability::ReadAmounts)) {
                $amounts = self::openAmounts(CrmApiErrors::run(fn (): array => $this->pipeline->deals->as($call->caller)->ofRecord($type, $id)));
            } else {
                $hidden = true;
            }
        }

        return CrmApiPresenter::record($view, $lifecycle, $actions, $amounts, $hidden);
    }

    /**
     * `crm_timeline`: the record's activity, newest first (a company's includes its visible contacts'; a deal's is its
     * own); note entries carry the note's current body.
     */
    public function timeline(CrmApiPrincipal $principal, TenantRef $tenant, SubjectType $type, RecordId $id, CrmApiPageRequest $page): CrmApiPage
    {
        $call   = $this->guard->call($principal, $tenant, CrmApiOperation::Timeline);
        $filter = CrmApiCursorCodec::filterHash(['type' => $type->value, 'id' => $id->value]);
        $key    = $page->cursor === null ? null : $this->cursors->open($page->cursor, CrmApiOperation::Timeline, $filter);
        $before = is_string($key['id'] ?? null) && RecordId::isValid($key['id']) ? $key['id'] : ($key === null ? null : throw CrmApiRefusal::of('INPUT_003'));
        if ($type === SubjectType::Deal) {
            if ($this->pipeline === null) {
                throw CrmApiRefusal::notFound();
            }
            CrmApiErrors::run(fn (): DealView => $this->pipeline->deals->as($call->caller)->deal($id->value));
            $call->require(CrmApiCapability::Read);
            $entries = $this->dealActivities($call, $id->value, $page->limit + 1, $before);
        } else {
            $this->records->probe($call, $type, $id);
            $call->require(CrmApiCapability::Read);
            $entries = CrmApiErrors::run(fn (): array => $this->reader->as($call->caller)->timeline($type, $id->value, $page->limit + 1, $before));
        }
        $next = null;
        if (count($entries) > $page->limit) {
            $entries = array_slice($entries, 0, $page->limit);
            $next    = $this->cursors->seal(CrmApiOperation::Timeline, $filter, ['id' => $entries[$page->limit - 1]->id]);
        }
        $support = $this->support->as($call->caller);
        $items   = [];
        foreach ($entries as $entry) {
            $noteId = $entry->params['note'] ?? null;
            $body   = in_array($entry->kind, [ActivityKind::NoteAdded, ActivityKind::NoteEdited], true) && is_string($noteId) && RecordId::isValid($noteId)
                ? $support->read(static fn (): ?string => $support->store->note($call->context->tenant, $noteId)?->getBody())
                : null;
            $items[] = CrmApiPresenter::activity($entry, $body);
        }

        return new CrmApiPage($items, $next);
    }

    /**
     * `crm_notes_list`: pinned first, then newest first.
     */
    public function notes(CrmApiPrincipal $principal, TenantRef $tenant, SubjectType $type, RecordId $id, CrmApiPageRequest $page): CrmApiPage
    {
        $call = $this->guard->call($principal, $tenant, CrmApiOperation::NotesList);
        $this->records->probe($call, $type, $id);
        $call->require(CrmApiCapability::Read);
        $filter  = CrmApiCursorCodec::filterHash(['type' => $type->value, 'id' => $id->value]);
        $key     = $page->cursor === null ? null : $this->cursors->open($page->cursor, CrmApiOperation::NotesList, $filter);
        $after   = $key === null ? null : (is_bool($key['pinned'] ?? null) && is_string($key['id'] ?? null) ? ['pinned' => $key['pinned'], 'id' => $key['id']] : throw CrmApiRefusal::of('INPUT_003'));
        $support = $this->support->as($call->caller);
        $rows    = $support->read(static fn (): array => $support->store->notesAfter($call->context->tenant, $type, $id->value, $after, $page->limit + 1));
        $next    = null;
        if (count($rows) > $page->limit) {
            $rows = array_slice($rows, 0, $page->limit);
            $last = $rows[$page->limit - 1];
            $next = $this->cursors->seal(CrmApiOperation::NotesList, $filter, ['pinned' => $last->isPinned(), 'id' => $last->getId()]);
        }
        $names = $this->views->actorNames($call->context->tenant, array_map(static fn (Note $n): ?string => $n->getAuthorId(), $rows));

        return new CrmApiPage(array_map(static function (Note $n) use ($names): CrmApiItem {
            $author = $n->getAuthorId();

            return CrmApiPresenter::note(new NoteView($n->getId(), $n->getBody(), $n->isPinned(), $author, $author === null ? null : ($names[$author] ?? null), $n->getVersion(), $n->getCreatedAt(), $n->getUpdatedAt()));
        }, $rows), $next);
    }

    /**
     * `crm_consents_get`: read-only; needs the consents tick.
     */
    public function consents(CrmApiPrincipal $principal, TenantRef $tenant, RecordId $contactId, bool $history = false): CrmApiItem
    {
        $call = $this->guard->call($principal, $tenant, CrmApiOperation::ConsentsGet);
        $this->records->probe($call, SubjectType::Contact, $contactId);
        $call->require(CrmApiCapability::ReadConsents);
        $rows = CrmApiErrors::run(fn (): array => $this->reader->as($call->caller)->consents($contactId->value));

        return CrmApiPresenter::consents($contactId->value, $rows, $this->consentPurposes, $history);
    }

    /**
     * REST `GET /tags`, MCP `crm_tag_list`.
     */
    public function tags(CrmApiPrincipal $principal, TenantRef $tenant): CrmApiList
    {
        $call = $this->guard->call($principal, $tenant, CrmApiOperation::TagsList);
        $call->require(CrmApiCapability::Read);

        return new CrmApiList(array_map(CrmApiPresenter::tag(...), CrmApiErrors::run(fn (): array => $this->reader->as($call->caller)->tags())));
    }

    /**
     * REST `GET /{type}/{id}/links`: resolved by the host; unresolved links hidden.
     */
    public function links(CrmApiPrincipal $principal, TenantRef $tenant, SubjectType $type, RecordId $id): CrmApiList
    {
        $call = $this->guard->call($principal, $tenant, CrmApiOperation::LinksList);
        $this->records->probe($call, $type, $id);
        $call->require(CrmApiCapability::Read);

        return new CrmApiList(array_map(CrmApiPresenter::link(...), CrmApiErrors::run(fn (): array => $this->reader->as($call->caller)->links($type, $id->value))));
    }

    /**
     * REST `GET /companies/{id}/contacts` and `GET /contacts/{id}/companies`: the visible other sides, by name.
     */
    public function affiliations(CrmApiPrincipal $principal, TenantRef $tenant, SubjectType $side, RecordId $id, CrmApiPageRequest $page): CrmApiPage
    {
        $call = $this->guard->call($principal, $tenant, CrmApiOperation::AffiliationsList);
        $this->records->probe($call, $side, $id);
        $call->require(CrmApiCapability::Read);
        $filter = CrmApiCursorCodec::filterHash(['side' => $side->value, 'id' => $id->value]);
        $key    = $page->cursor === null ? null : $this->cursors->open($page->cursor, CrmApiOperation::AffiliationsList, $filter);
        if ($key !== null && (!is_string($key['v'] ?? null) || !is_string($key['id'] ?? null))) {
            throw CrmApiRefusal::of('INPUT_003');
        }
        $support = $this->support->as($call->caller);
        $views   = $support->read(fn (): array => $this->views->affiliations($call->context->tenant, $side, $id->value, $call->access->visibleOwners($call->context)));
        $sortKey = static fn (AffiliationView $a): string => mb_strtolower($side === SubjectType::Contact ? $a->companyName : $a->contactName, 'UTF-8');
        usort($views, static fn (AffiliationView $a, AffiliationView $b): int => strcmp($sortKey($a), $sortKey($b)) ?: strcmp($a->id, $b->id));
        $rows = [];
        foreach ($views as $view) {
            if ($key === null || strcmp($sortKey($view), $key['v']) > 0 || ($sortKey($view) === $key['v'] && strcmp($view->id, $key['id']) > 0)) {
                $rows[] = $view;
            }
        }
        $next = null;
        if (count($rows) > $page->limit) {
            $rows = array_slice($rows, 0, $page->limit);
            $last = $rows[$page->limit - 1];
            $next = $this->cursors->seal(CrmApiOperation::AffiliationsList, $filter, ['v' => $sortKey($last), 'id' => $last->id]);
        }

        return new CrmApiPage(array_map(static fn (AffiliationView $a): CrmApiItem => CrmApiPresenter::affiliation($a, $side), $rows), $next);
    }

    /**
     * REST `GET /me`: the actor, the grant, whether the tenant is reached and the operations available.
     */
    public function identity(CrmApiPrincipal $principal, TenantRef $tenant): CrmApiItem
    {
        $call = $this->guard->call($principal, $tenant, CrmApiOperation::Identity);
        $call->require(CrmApiCapability::Read);
        $grant = $principal->grant;

        return new CrmApiItem('identity', [
            'actor_id'   => $principal->actor->value,
            'tenant'     => $call->context->tenant->value,
            'channel'    => $principal->channel,
            'grant'      => ['level' => $grant->level->value, 'amounts' => $grant->amounts, 'consents' => $grant->consents, 'destructive' => $grant->destructive],
            'operations' => array_map(static fn (CrmApiOperation $o): string => $o->value, $this->operations->all()),
        ], ['token' => $call->context->origin?->tokenName]);
    }

    /**
     * One record, or its tombstone.
     */
    private function record(CrmApiOperation $operation, SubjectType $type, CrmApiPrincipal $principal, TenantRef $tenant, RecordId $id): CrmApiItem
    {
        $call    = $this->guard->call($principal, $tenant, $operation);
        $merged  = null;
        $subject = $this->records->probe($call, $type, $id, true, $merged);
        $call->require(CrmApiCapability::Read);
        if ($subject === null) {
            return CrmApiPresenter::tombstone($type, $id->value, $merged['version'] ?? 0, $merged['id'] ?? null);
        }

        return $this->present($call, $type, $id->value);
    }

    /**
     * The activity of one deal, newest first.
     *
     * @return list<ActivityView>
     */
    private function dealActivities(CrmApiCall $call, string $id, int $limit, ?string $before): array
    {
        $support = $this->support->as($call->caller);
        $rows    = $support->read(static fn (): array => $support->store->activities($call->context->tenant, [[SubjectType::Deal, $id]], $limit, $before));
        $names   = $this->views->actorNames($call->context->tenant, array_map(static fn (Activity $a): ?string => $a->getActorId(), $rows));

        return array_map(static fn (Activity $a): ActivityView => new ActivityView($a->getId(), $a->getSubjectType(), $a->getSubjectId(), null, $a->getKind(), $a->getActorId() === null ? null : ($names[$a->getActorId()] ?? null), $a->getParams(), $a->getOccurredAt()), $rows);
    }

    /**
     * Open deal values per currency (never summed across currencies).
     *
     * @param list<DealView> $deals
     *
     * @return list<array{currency: string, open: string}>
     */
    private static function openAmounts(array $deals): array
    {
        $sums = [];
        foreach ($deals as $deal) {
            if ($deal->status === DealStatus::Open && $deal->amount !== null) {
                $sums[$deal->amount->currency] = Decimal::add($sums[$deal->amount->currency] ?? '0', $deal->amount->amount);
            }
        }
        $out = [];
        foreach ($sums as $currency => $sum) {
            $out[] = ['currency' => (string) $currency, 'open' => $sum];
        }

        return $out;
    }
}
