<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Operation;

use Nowo\CrmKitBundle\Api\CrmApiCapability;
use Nowo\CrmKitBundle\Api\CrmApiCode;
use Nowo\CrmKitBundle\Api\CrmApiOperation;
use Nowo\CrmKitBundle\Api\CrmApiPrincipal;
use Nowo\CrmKitBundle\Api\CrmApiRefusal;
use Nowo\CrmKitBundle\Api\Input\CrmApiCursor;
use Nowo\CrmKitBundle\Api\Input\CrmApiDealChanges;
use Nowo\CrmKitBundle\Api\Input\CrmApiDealDraft;
use Nowo\CrmKitBundle\Api\Input\CrmApiDealQuery;
use Nowo\CrmKitBundle\Api\Input\CrmApiFunnelStatsQuery;
use Nowo\CrmKitBundle\Api\Input\CrmApiStageMove;
use Nowo\CrmKitBundle\Api\Input\IdempotencyKey;
use Nowo\CrmKitBundle\Api\Input\RecordVersion;
use Nowo\CrmKitBundle\Api\Read\CrmApiItem;
use Nowo\CrmKitBundle\Api\Read\CrmApiList;
use Nowo\CrmKitBundle\Api\Read\CrmApiPage;
use Nowo\CrmKitBundle\Api\Read\CrmApiPresenter;
use Nowo\CrmKitBundle\Api\Read\CrmApiWritten;
use Nowo\CrmKitBundle\Application\Pipeline\DealInput;
use Nowo\CrmKitBundle\Application\Pipeline\DealQuery;
use Nowo\CrmKitBundle\Domain\Enum\FunnelKind;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Read\Pipeline\DealPage;
use Nowo\CrmKitBundle\Domain\Read\Pipeline\DealView;
use Nowo\CrmKitBundle\Domain\Read\Pipeline\FunnelStats;
use Nowo\CrmKitBundle\Domain\Read\Pipeline\FunnelView;
use Nowo\CrmKitBundle\Domain\Value\Money;
use Nowo\CrmKitBundle\Domain\Value\RecordId;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

use function array_map;
use function is_string;

/**
 * Deals and funnels through the API (0.3.0, design §6.2): search, get, funnels, statistics, create, update, stage
 * move and trash (restorable from the pages, QA-4). Unavailable while `pipeline.enabled` is false (404). Amounts are
 * read only with the amounts tick and `amounts_view`, and written only with both. Stateless.
 */
final readonly class CrmApiDealOperations
{
    /**
     * Wires the collaborators.
     */
    public function __construct(
        private CrmApiGuard $guard,
        private CrmApiWriter $writer,
        private CrmApiCursorCodec $cursors,
        private ?CrmApiPipeline $pipeline = null,
    ) {
    }

    /**
     * `crm_deal_search`: keyset-paged, same visibility as the pages.
     */
    public function search(CrmApiPrincipal $principal, TenantRef $tenant, CrmApiDealQuery $query): CrmApiPage
    {
        $call     = $this->guard->call($principal, $tenant, CrmApiOperation::DealSearch);
        $pipeline = $this->pipeline();
        $call->require(CrmApiCapability::Read);
        if ($query->sort === DealQuery::SORT_AMOUNT) {
            $call->require(CrmApiCapability::ReadAmounts);
        }
        $filter = CrmApiCursorCodec::filterHash($query->filters());
        $key    = $query->page->cursor instanceof CrmApiCursor ? $this->cursors->open($query->page->cursor, CrmApiOperation::DealSearch, $filter) : null;
        if ($key !== null && (!is_string($key['id'] ?? null) || ($key['v'] ?? null) !== null && !is_string($key['v']))) {
            throw CrmApiRefusal::of(CrmApiCode::INPUT_CURSOR);
        }
        /** @var array{v: ?string, id: string}|null $after */
        $after = $key === null ? null : ['v' => $key['v'] ?? null, 'id' => $key['id']];
        $dq    = new DealQuery(
            $query->funnelId?->value,
            $query->stageIds === null ? null : array_map(static fn (RecordId $s): string => $s->value, $query->stageIds),
            $query->status,
            $query->ownerId?->value,
            $query->companyId?->value,
            $query->contactId?->value,
            $query->text,
            $query->staleOnly,
            $query->sort,
        );
        $page = CrmApiErrors::run(static fn (): DealPage => $pipeline->deals->as($call->caller)->deals($dq, $after, $query->page->limit));

        return new CrmApiPage(array_map(CrmApiPresenter::deal(...), $page->deals), $page->next === null ? null : $this->cursors->seal(CrmApiOperation::DealSearch, $filter, $page->next));
    }

    /**
     * `crm_deal_get`: with its stage history (≤ 50) and open next actions.
     */
    public function deal(CrmApiPrincipal $principal, TenantRef $tenant, RecordId $id): CrmApiItem
    {
        $call = $this->guard->call($principal, $tenant, CrmApiOperation::DealGet);
        $view = $this->view($call, $id->value);
        $call->require(CrmApiCapability::Read);

        return CrmApiPresenter::deal($view);
    }

    /**
     * REST `GET /funnels`, MCP `crm_funnel_list`: deal and party funnels with their stages.
     */
    public function funnels(CrmApiPrincipal $principal, TenantRef $tenant): CrmApiList
    {
        $call     = $this->guard->call($principal, $tenant, CrmApiOperation::FunnelsList);
        $pipeline = $this->pipeline();
        $call->require(CrmApiCapability::Read);

        return new CrmApiList(array_map(CrmApiPresenter::funnel(...), CrmApiErrors::run(static fn (): array => $pipeline->funnels->as($call->caller)->funnels())));
    }

    /**
     * `crm_funnel_stats`: per stage count and stale count; values and weighted values per currency only with the
     * amounts tick and `amounts_view`.
     */
    public function stats(CrmApiPrincipal $principal, TenantRef $tenant, CrmApiFunnelStatsQuery $query): CrmApiItem
    {
        $call     = $this->guard->call($principal, $tenant, CrmApiOperation::FunnelStats);
        $pipeline = $this->pipeline();
        $funnel   = CrmApiErrors::run(static fn (): FunnelView => $pipeline->funnels->as($call->caller)->funnel($query->funnelId->value));
        if ($funnel->kind !== FunnelKind::Deal) {
            throw CrmApiRefusal::notFound();
        }
        $call->require(CrmApiCapability::Read);

        return CrmApiPresenter::stats(CrmApiErrors::run(static fn (): FunnelStats => $pipeline->statistics->as($call->caller)->stats($query->funnelId->value, $query->at, $query->ownerId?->value, $query->role?->value, $query->tagId?->value)));
    }

    /**
     * `crm_deal_create`: an open stage of a deal funnel; `deals` plan counter; amount fields need the amounts tick.
     */
    public function create(CrmApiPrincipal $principal, TenantRef $tenant, IdempotencyKey $key, CrmApiDealDraft $draft): CrmApiWritten
    {
        $call = $this->guard->call($principal, $tenant, CrmApiOperation::DealCreate);
        $this->pipeline();

        return $this->writer->write(
            $call,
            $key,
            CrmApiOperation::DealCreate,
            $draft->canonical(),
            201,
            static fn (mixed $id): array => ['deal' => is_string($id) ? $id : ''], // @igor-ignore - a per-call closure the writer runs at once; nothing is kept on the service
            function (CrmApiCall $bound) use ($call, $draft): CrmApiWritten { // @igor-ignore - a per-call closure the writer runs at once; nothing is kept on the service
                $bound->require(CrmApiCapability::Create);
                if ($draft->amount instanceof Money || $draft->probability !== null) {
                    $bound->require(CrmApiCapability::WriteAmounts);
                }
                $input = new DealInput($draft->title, $draft->funnelId->value, $draft->stageId->value, $draft->companyId?->value, $draft->contactId?->value, $draft->owner->resolve($bound->principal->actor), $draft->amount, $draft->probability, $draft->expectedClose);
                $id    = CrmApiErrors::run(fn (): string => $this->pipeline()->dealService->as($bound->caller)->create($input));

                return new CrmApiWritten(CrmApiPresenter::deal($this->view($call, $id)), false, true, 201);
            },
            fn (CrmApiReplay $r): CrmApiWritten => new CrmApiWritten(CrmApiPresenter::deal($this->view($call, $r->ids['deal'] ?? '')), true, true, $r->status), // @igor-ignore - a per-call closure the writer runs at once; nothing is kept on the service
        );
    }

    /**
     * `crm_deal_update`: partial; `$base` must be the current version (`CRM_001` with `current_version`).
     */
    public function update(CrmApiPrincipal $principal, TenantRef $tenant, IdempotencyKey $key, RecordId $id, RecordVersion $base, CrmApiDealChanges $changes): CrmApiWritten
    {
        $call = $this->guard->call($principal, $tenant, CrmApiOperation::DealUpdate);
        $this->pipeline();

        return $this->writer->write(
            $call,
            $key,
            CrmApiOperation::DealUpdate,
            ['id' => $id->value, 'base' => $base->value] + $changes->canonical(),
            200,
            static fn (): array => ['deal' => $id->value], // @igor-ignore - a per-call closure the writer runs at once; nothing is kept on the service
            function (CrmApiCall $bound) use ($call, $id, $base, $changes): CrmApiWritten { // @igor-ignore - a per-call closure the writer runs at once; nothing is kept on the service
                $view = $this->view($bound, $id->value);
                $bound->require(CrmApiCapability::Edit, $this->subject($view));
                if ($changes->touchesAmounts()) {
                    $bound->require(CrmApiCapability::WriteAmounts, $this->subject($view));
                }
                $input   = new DealInput($changes->title, null, null, $changes->companyId?->value, $changes->contactId?->value, $changes->owner?->resolve($bound->principal->actor), $changes->amount, $changes->probability, $changes->expectedClose, $changes->fields);
                $version = CrmApiErrors::run(fn (): int => $this->pipeline()->dealService->as($bound->caller)->update($id->value, $input, $base->value), $this->stale($call, $id->value));

                return new CrmApiWritten(CrmApiPresenter::deal($this->view($call, $id->value)), false, $version !== $base->value);
            },
            fn (CrmApiReplay $r): CrmApiWritten => new CrmApiWritten(CrmApiPresenter::deal($this->view($call, $id->value)), true, true, $r->status), // @igor-ignore - a per-call closure the writer runs at once; nothing is kept on the service
        );
    }

    /**
     * `crm_deal_move_stage`: to a lost stage needs a lost reason (`CRM_007`); a foreign or closed stage is `CRM_008`;
     * the same stage is `changed: false`.
     */
    public function moveStage(CrmApiPrincipal $principal, TenantRef $tenant, IdempotencyKey $key, RecordId $id, CrmApiStageMove $move, ?RecordVersion $base): CrmApiWritten
    {
        $call = $this->guard->call($principal, $tenant, CrmApiOperation::DealMoveStage);
        $this->pipeline();

        return $this->writer->write(
            $call,
            $key,
            CrmApiOperation::DealMoveStage,
            ['id' => $id->value, 'base' => $base?->value] + $move->canonical(),
            200,
            static fn (mixed $changed): array => ['deal' => $id->value, 'changed' => $changed === true ? '1' : '0'], // @igor-ignore - a per-call closure the writer runs at once; nothing is kept on the service
            function (CrmApiCall $bound) use ($call, $id, $move, $base): CrmApiWritten { // @igor-ignore - a per-call closure the writer runs at once; nothing is kept on the service
                $view = $this->view($bound, $id->value);
                $bound->require(CrmApiCapability::Edit, $this->subject($view));
                $changed = CrmApiErrors::run(fn (): bool => $this->pipeline()->dealService->as($bound->caller)->moveStage($id->value, $move->stageId->value, $move->lostReason?->value, $move->lostNote, $base?->value), $this->stale($call, $id->value));

                return new CrmApiWritten(CrmApiPresenter::deal($this->view($call, $id->value)), false, $changed);
            },
            fn (CrmApiReplay $r): CrmApiWritten => new CrmApiWritten(CrmApiPresenter::deal($this->view($call, $id->value)), true, ($r->ids['changed'] ?? '1') === '1', $r->status), // @igor-ignore - a per-call closure the writer runs at once; nothing is kept on the service
        );
    }

    /**
     * `crm_deal_trash` (QA-4): to the trash, restorable from the pages, purged after `trash_days`; destructive.
     */
    public function trash(CrmApiPrincipal $principal, TenantRef $tenant, IdempotencyKey $key, RecordId $id, ?RecordVersion $base): CrmApiWritten
    {
        $call    = $this->guard->call($principal, $tenant, CrmApiOperation::DealTrash);
        $trashed = new CrmApiItem('trashed', ['type' => SubjectType::Deal->value, 'id' => $id->value, 'status' => 'trashed']);
        $this->pipeline();

        return $this->writer->write(
            $call,
            $key,
            CrmApiOperation::DealTrash,
            ['id' => $id->value, 'base' => $base?->value],
            200,
            static fn (): array => ['deal' => $id->value], // @igor-ignore - a per-call closure the writer runs at once; nothing is kept on the service
            function (CrmApiCall $bound) use ($call, $id, $base, $trashed): CrmApiWritten { // @igor-ignore - a per-call closure the writer runs at once; nothing is kept on the service
                $view = $this->view($bound, $id->value);
                $bound->require(CrmApiCapability::Trash, $this->subject($view));
                CrmApiErrors::run(fn () => $this->pipeline()->dealService->as($bound->caller)->trash($id->value, $base?->value), $this->stale($call, $id->value));

                return new CrmApiWritten($trashed, false, true);
            },
            static fn (CrmApiReplay $r): CrmApiWritten => new CrmApiWritten($trashed, true, true, $r->status), // @igor-ignore - a per-call closure the writer runs at once; nothing is kept on the service
        );
    }

    /**
     * A deal the principal may view (else 404).
     */
    private function view(CrmApiCall $call, string $id): DealView
    {
        return RecordId::isValid($id) ? CrmApiErrors::run(fn (): DealView => $this->pipeline()->deals->as($call->caller)->deal($id)) : throw CrmApiRefusal::notFound();
    }

    private function subject(DealView $view): SubjectRef
    {
        return SubjectRef::of(SubjectType::Deal, $view->id, $view->ownerId);
    }

    /**
     * The funnel services, or 404 while the funnels are off.
     */
    private function pipeline(): CrmApiPipeline
    {
        return $this->pipeline ?? throw CrmApiRefusal::notFound();
    }

    /**
     * The extras of a stale version: the current one, when the deal is still visible.
     *
     * @return callable(ConflictException): array<string, int|string|null>
     */
    private function stale(CrmApiCall $call, string $id): callable
    {
        return fn (ConflictException $e): array => $e->errorCode === ConflictException::STALE ? ['current_version' => $this->view($call, $id)->version] : [];
    }
}
