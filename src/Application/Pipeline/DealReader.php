<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application\Pipeline;

use DateInterval;
use DateTimeImmutable;
use Nowo\CrmKitBundle\Application\Access\CrmCallerInterface;
use Nowo\CrmKitBundle\Application\CrmContext;
use Nowo\CrmKitBundle\Application\RecordViews;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\DealStatus;
use Nowo\CrmKitBundle\Domain\Enum\StageKind;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Model\CrmRecord;
use Nowo\CrmKitBundle\Domain\Model\Pipeline\Deal;
use Nowo\CrmKitBundle\Domain\Model\Pipeline\Funnel;
use Nowo\CrmKitBundle\Domain\Model\Pipeline\Stage;
use Nowo\CrmKitBundle\Domain\Model\Pipeline\StageChange;
use Nowo\CrmKitBundle\Domain\Read\Pipeline\DealListResult;
use Nowo\CrmKitBundle\Domain\Read\Pipeline\DealPage;
use Nowo\CrmKitBundle\Domain\Read\Pipeline\DealView;
use Nowo\CrmKitBundle\Domain\Read\Pipeline\NextActionView;
use Nowo\CrmKitBundle\Domain\Read\Pipeline\StageChangeView;
use Nowo\CrmKitBundle\Domain\Value\RecordId;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;

use function array_filter;
use function array_map;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function in_array;
use function is_string;

/**
 * Deal reads (0.3.0, `view`): one deal with its stage history (≤ 50) and open next actions (≤ 10), and keyset pages of
 * deals with the same visibility as the lists (owner-based in SQL, then `view` on every row, so a host's
 * `nowo_crm_kit.record_access_check` deny hides it). Amounts only with `amounts_view`; the company and the contact only
 * when visible. Stateless.
 */
final readonly class DealReader
{
    /** Stage changes in one deal's history. */
    public const HISTORY = 50;

    /** Open next actions in one deal. */
    public const NEXT_ACTIONS = 10;

    /** Most keyset batches one page scans past deals a record listener denies (0.3.0). */
    public const MAX_SCAN_ROUNDS = 10;

    /** Largest page. */
    public const MAX_PAGE = 100;

    /**
     * Wires the collaborators.
     */
    public function __construct(private PipelineSupport $pipeline, private RecordViews $views, private NextActionViews $actions)
    {
    }

    /**
     * A copy bound to `$caller` for one call (an API principal); never assigned to a property.
     *
     * @internal
     */
    public function as(CrmCallerInterface $caller): self
    {
        return new self($this->pipeline->as($caller), $this->views, $this->actions);
    }

    /**
     * One deal (`$trashed`: also from the trash).
     *
     * @throws NotAvailableException missing, other tenant, trashed, or not visible (a refused view is a 404)
     */
    public function deal(string $id, bool $trashed = false): DealView
    {
        $context = $this->pipeline->support->access->base();

        return $this->pipeline->support->read(function () use ($context, $id, $trashed): DealView { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $deal    = $this->pipeline->deal($context, $id, CrmPermission::View, false, $trashed);
            $history = array_map(static fn (StageChange $c): StageChangeView => new StageChangeView($c->getFromStageId(), $c->getToStageId(), $c->getActorId(), $c->getVia(), $c->getOccurredAt(), $c->getSecondsInFrom()), $this->pipeline->store->stageChanges($context->tenant, SubjectType::Deal, $id, self::HISTORY));
            $actions = $this->actions->of($context, $this->pipeline->store->nextActions($context->tenant, SubjectType::Deal, $id, true, self::NEXT_ACTIONS));

            return $this->views($context, [$deal], $history, $actions)[0];
        });
    }

    /**
     * A keyset page of the deals the actor may view: `$after` is the key of the previous page's last deal
     * ({@see DealPage::$next}). Sorting by amount needs `amounts_view`.
     *
     * @param array{v: string|null, id: string}|null $after
     *
     * @throws AccessDeniedException|NotAvailableException
     */
    public function deals(DealQuery $query, ?array $after = null, int $limit = 25): DealPage
    {
        $context = $this->pipeline->support->access->context(CrmPermission::View);
        if ($query->sort === DealQuery::SORT_AMOUNT) {
            $this->pipeline->support->access->require($context, CrmPermission::AmountsView);
        }
        $limit = max(1, min(self::MAX_PAGE, $limit));
        // 0.10.2: "Mine" is the acting person's deals.
        $query = $query->forActor($context->actor->value);

        return $this->pipeline->support->read(function () use ($context, $query, $after, $limit): DealPage { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $owners  = $this->pipeline->support->access->visibleOwners($context);
            $cutoffs = $this->cutoffs($context, $query->funnelId);
            $visible = [];
            $next    = null;
            $cursor  = $after;
            // A host's per-record deny hides the row (0.3.0): the scan goes on past hidden rows, up to
            // MAX_SCAN_ROUNDS batches; the next key is the last VISIBLE row's, or the last row scanned once the bound
            // is reached (callers seal it).
            for ($round = 1; $round <= self::MAX_SCAN_ROUNDS; ++$round) {
                $rows    = $this->pipeline->store->deals($context->tenant, $query, $owners, $cursor, $limit, $cutoffs, $context->now);
                $more    = count($rows) > $limit;
                $rows    = array_slice($rows, 0, $limit);
                $granted = $this->pipeline->support->access->viewable($context, array_map(static fn (Deal $d): SubjectRef => SubjectRef::of(SubjectType::Deal, $d->getId(), $d->getOwnerId()), $rows));
                foreach ($rows as $deal) {
                    if (isset($granted[SubjectType::Deal->value . ':' . $deal->getId()])) {
                        if (count($visible) === $limit) {
                            $next = self::key($visible[$limit - 1], $query->sort);

                            break 2;
                        }
                        $visible[] = $deal;
                    }
                    $cursor = self::key($deal, $query->sort);
                }
                if (!$more) {
                    break;
                }
                if ($round === self::MAX_SCAN_ROUNDS) {
                    $next = $cursor;
                }
            }

            return new DealPage($this->views($context, $visible), $next);
        });
    }

    /**
     * A numbered page of the deals the actor may view (0.10.2, the deals list: page links and "Showing x–y of N"),
     * in the query's order. A deal a host's per-record deny hides is left out of the page and out of the total as far
     * as this page shows (like the company and contact lists). Sorting by amount needs `amounts_view`.
     *
     * @throws AccessDeniedException|NotAvailableException
     */
    public function page(DealQuery $query, int $page = 1, int $perPage = 25): DealListResult
    {
        $context = $this->pipeline->support->access->context(CrmPermission::View);
        if ($query->sort === DealQuery::SORT_AMOUNT) {
            $this->pipeline->support->access->require($context, CrmPermission::AmountsView);
        }
        $perPage = max(1, min(self::MAX_PAGE, $perPage));
        $offset  = (max(1, $page) - 1) * $perPage;
        $query   = $query->forActor($context->actor->value);

        return $this->pipeline->support->read(function () use ($context, $query, $offset, $perPage): DealListResult { // @igor-ignore - the runner calls this closure at once and keeps nothing
            [$rows, $total] = $this->pipeline->store->dealsPage($context->tenant, $query, $this->pipeline->support->access->visibleOwners($context), $offset, $perPage, $this->cutoffs($context, $query->funnelId), $context->now);
            $granted        = $this->pipeline->support->access->viewable($context, array_map(static fn (Deal $d): SubjectRef => SubjectRef::of(SubjectType::Deal, $d->getId(), $d->getOwnerId()), $rows));
            $visible        = array_values(array_filter($rows, static fn (Deal $d): bool => isset($granted[SubjectType::Deal->value . ':' . $d->getId()])));

            return new DealListResult($this->views($context, $visible), max(0, $total - (count($rows) - count($visible))));
        });
    }

    /**
     * The visible open deals of a company or contact (the 360° page), newest change first, at most 50.
     *
     * @throws AccessDeniedException|NotAvailableException
     *
     * @return list<DealView>
     */
    public function ofRecord(SubjectType $type, string $id): array
    {
        if (!RecordId::isValid($id) || !$type->isRecord()) {
            throw new NotAvailableException();
        }

        return $this->deals(new DealQuery(companyId: $type === SubjectType::Company ? $id : null, contactId: $type === SubjectType::Contact ? $id : null), null, 50)->deals;
    }

    /**
     * The key of a deal in a list order.
     *
     * @return array{v: string|null, id: string}
     */
    public static function key(Deal $deal, string $sort): array
    {
        return ['v' => match ($sort) {
            DealQuery::SORT_AMOUNT         => $deal->getAmount()?->amount,
            DealQuery::SORT_EXPECTED_CLOSE => $deal->getExpectedClose()?->value,
            DealQuery::SORT_TITLE          => $deal->getTitle(),
            default                        => $deal->getUpdatedAt()->format('Y-m-d H:i:s.u'),
        }, 'id' => $deal->getId()];
    }

    /**
     * The stale cutoffs of the open stages with an age limit: stage id ⇒ entered before then is stale.
     *
     * @return array<string, DateTimeImmutable>
     */
    public function cutoffs(CrmContext $context, ?string $funnelId): array
    {
        $funnels = $funnelId === null ? array_map(static fn (Funnel $f): string => $f->getId(), $this->pipeline->store->funnels($context->tenant)) : [$funnelId];
        $cutoffs = [];
        foreach ($this->pipeline->store->stages($context->tenant, $funnels) as $stage) {
            $days = $stage->getRotDays();
            if ($days !== null && $stage->getKind() === StageKind::Open) {
                $cutoffs[$stage->getId()] = $context->now->sub(new DateInterval('P' . $days . 'D'));
            }
        }

        return $cutoffs;
    }

    /**
     * The views of deals the actor may view.
     *
     * @param list<Deal> $deals
     * @param list<StageChangeView> $history
     * @param list<NextActionView> $actions
     *
     * @return list<DealView>
     */
    private function views(CrmContext $context, array $deals, array $history = [], array $actions = []): array
    {
        if ($deals === []) {
            return [];
        }
        $tenant  = $context->tenant;
        $amounts = $this->allowed($context, CrmPermission::AmountsView, null);
        $stages  = [];
        foreach ($this->pipeline->store->stages($tenant, array_values(array_unique(array_map(static fn (Deal $d): string => $d->getFunnelId(), $deals)))) as $stage) {
            $stages[$stage->getId()] = $stage;
        }
        $related = [
            SubjectType::Company->value => $this->related($context, SubjectType::Company, array_map(static fn (Deal $d): ?string => $d->getCompanyId(), $deals)),
            SubjectType::Contact->value => $this->related($context, SubjectType::Contact, array_map(static fn (Deal $d): ?string => $d->getContactId(), $deals)),
        ];
        $names = $this->views->actorNames($tenant, array_map(static fn (Deal $d): ?string => $d->getOwnerId(), $deals));
        $views = [];
        foreach ($deals as $deal) {
            $stage       = $stages[$deal->getStageId()] ?? null;
            $probability = $deal->getProbability() ?? ($stage instanceof Stage ? $stage->getProbability() : 0);
            $amount      = $deal->getAmount();
            $company     = $deal->getCompanyId() === null ? null : ($related[SubjectType::Company->value][$deal->getCompanyId()] ?? false);
            $contact     = $deal->getContactId() === null ? null : ($related[SubjectType::Contact->value][$deal->getContactId()] ?? false);
            $owner       = $deal->getOwnerId();
            $views[]     = new DealView(
                $deal->getId(),
                $deal->getFunnelId(),
                $deal->getStageId(),
                $stage instanceof Stage ? $stage->getKind() : StageKind::Open,
                $deal->getStatus(),
                $deal->getTitle(),
                is_string($company) ? $deal->getCompanyId() : null,
                is_string($company) ? $company : null,
                $company === false,
                is_string($contact) ? $deal->getContactId() : null,
                is_string($contact) ? $contact : null,
                $contact === false,
                $owner,
                $owner === null ? null : ($names[$owner] ?? null),
                $amounts ? $amount : null,
                $amounts ? $deal->getProbability() : null,
                $probability,
                $amounts && $amount !== null ? Decimal::percent($amount->amount, $probability) : null,
                !$amounts,
                $deal->getExpectedClose(),
                $deal->getStageEnteredAt(),
                $deal->getClosedAt(),
                $deal->getNextActionAt(),
                self::stale($deal, $stage, $context->now),
                $deal->getLostReason(),
                $deal->getLostNote(),
                $deal->getVersion(),
                $deal->getCreatedAt(),
                $deal->getUpdatedAt(),
                count($deals) === 1 ? $history : [],
                count($deals) === 1 ? $actions : [],
            );
        }

        return $views;
    }

    /**
     * Whether an open deal is stale: in its stage longer than the stage's age limit, or with an overdue next action.
     */
    public static function stale(Deal $deal, ?Stage $stage, DateTimeImmutable $now): bool
    {
        if ($deal->getStatus() !== DealStatus::Open) {
            return false;
        }
        $days = $stage?->getRotDays();
        $next = $deal->getNextActionAt();

        return ($days !== null && $deal->getStageEnteredAt() < $now->sub(new DateInterval('P' . $days . 'D'))) || ($next instanceof DateTimeImmutable && $next < $now);
    }

    /**
     * The labels of the related records the actor may view (active ones only), by id; hidden ones are absent.
     *
     * @param list<string|null> $ids
     *
     * @return array<string, string>
     */
    private function related(CrmContext $context, SubjectType $type, array $ids): array
    {
        $wanted = [];
        foreach ($ids as $id) {
            if ($id !== null && !in_array($id, $wanted, true)) {
                $wanted[] = $id;
            }
        }
        $labels = [];
        foreach ($this->pipeline->support->store->records($type, $context->tenant, $wanted) as $id => $record) {
            if ($record instanceof CrmRecord && $record->isActive() && $this->allowed($context, CrmPermission::View, SubjectRef::of($type, $record->getId(), $record->getOwnerId()))) {
                $labels[(string) $id] = $record->label();
            }
        }

        return $labels;
    }

    private function allowed(CrmContext $context, CrmPermission $permission, ?SubjectRef $subject): bool
    {
        try {
            $this->pipeline->support->access->require($context, $permission, $subject);

            return true;
        } catch (AccessDeniedException) {
            return false;
        }
    }
}
