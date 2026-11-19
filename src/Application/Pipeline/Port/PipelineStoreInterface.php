<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application\Pipeline\Port;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Application\Pipeline\DealQuery;
use Nowo\CrmKitBundle\Domain\Enum\FunnelKind;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Model\Pipeline\Deal;
use Nowo\CrmKitBundle\Domain\Model\Pipeline\Funnel;
use Nowo\CrmKitBundle\Domain\Model\Pipeline\NextAction;
use Nowo\CrmKitBundle\Domain\Model\Pipeline\PartyStage;
use Nowo\CrmKitBundle\Domain\Model\Pipeline\Stage;
use Nowo\CrmKitBundle\Domain\Model\Pipeline\StageChange;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * The funnels' persistence (0.3.0, implemented on the bundle's entity manager; rows are added and removed through
 * the CRM store). Every lookup names the tenant and re-checks it with `===` in PHP (REQ-SEC-006 rule 2); callers run
 * inside the transaction runner.
 *
 * @internal
 */
interface PipelineStoreInterface
{
    /**
     * Funnels of the tenant by position.
     *
     * @return list<Funnel>
     */
    public function funnels(TenantRef $tenant, ?FunnelKind $kind = null, bool $withArchived = true): array;

    /** One funnel, optionally locked `FOR UPDATE`. */
    public function funnel(TenantRef $tenant, string $id, bool $lock = false): ?Funnel;

    /**
     * Stages of funnels by funnel and position.
     *
     * @param list<string> $funnelIds
     *
     * @return list<Stage>
     */
    public function stages(TenantRef $tenant, array $funnelIds): array;

    /** One stage. */
    public function stage(TenantRef $tenant, string $id): ?Stage;

    /** One deal, optionally locked `FOR UPDATE`. */
    public function deal(TenantRef $tenant, string $id, bool $lock = false): ?Deal;

    /**
     * A keyset page of deals (one more row than asked, so the caller knows whether a next page exists).
     *
     * @param list<string>|null $visibleOwners null: every deal; else only ownerless deals and these owners'
     * @param array<string, DateTimeImmutable> $staleCutoffs stage id ⇒ entered before this instant is stale
     * @param array{v: string|null, id: string}|null $after
     *
     * @return list<Deal>
     */
    public function deals(TenantRef $tenant, DealQuery $query, ?array $visibleOwners, ?array $after, int $limit, array $staleCutoffs, DateTimeImmutable $now): array;

    /**
     * Deals of a company or contact (any status), in id order.
     *
     * @return list<Deal>
     */
    public function dealsOf(TenantRef $tenant, SubjectType $type, string $id): array;

    /** Deals that are not in the trash (the `deals` plan counter's seed). */
    public function countLiveDeals(TenantRef $tenant): int;

    /**
     * Stage changes of an item, newest first.
     *
     * @return list<StageChange>
     */
    public function stageChanges(TenantRef $tenant, SubjectType $type, string $itemId, int $limit): array;

    /**
     * The party stages of a company or contact.
     *
     * @return list<PartyStage>
     */
    public function partyStages(TenantRef $tenant, SubjectType $type, string $id): array;

    /** The party stage of a record in one funnel. */
    public function partyStage(TenantRef $tenant, string $funnelId, string $subjectId): ?PartyStage;

    /**
     * Next actions of a subject (open ones first, earliest due first).
     *
     * @return list<NextAction>
     */
    public function nextActions(TenantRef $tenant, SubjectType $type, string $id, bool $openOnly, int $limit): array;

    /** One next action. */
    public function nextAction(TenantRef $tenant, string $id): ?NextAction;

    /** The next action of a dedupe key. */
    public function nextActionByDedupe(TenantRef $tenant, string $dedupeKey): ?NextAction;

    /** The earliest due time of the subject's open next actions. */
    public function earliestOpenDue(TenantRef $tenant, SubjectType $type, string $id): ?DateTimeImmutable;

    /**
     * Ids of deals trashed before the time.
     *
     * @return list<string>
     */
    public function trashedDealsBefore(TenantRef $tenant, DateTimeImmutable $before, int $limit): array;

    /**
     * Deletes the stage changes, party stages and next actions of an item (purge, erasure); how many rows.
     */
    public function deleteItemRows(TenantRef $tenant, SubjectType $type, string $id): int;

    /**
     * Unlinks the deals of a company or contact (`company_id` / `contact_id` set to null, version moved); how many.
     */
    public function unlinkDeals(TenantRef $tenant, SubjectType $type, string $id): int;

    /**
     * Points the deals, stage history, party stages and next actions of a merged company or contact at the survivor
     * (a party stage the survivor already has in that funnel wins; the merged one's is deleted).
     */
    public function repoint(TenantRef $tenant, SubjectType $type, string $fromId, string $toId): void;

    /**
     * Per stage and currency: count, sum, weighted sum (×100: the caller divides), stale count — now, or at a past
     * instant (the stage of each item from its last stage change at or before `$at`; items created after `$at` left
     * out; stale then always 0).
     *
     * @param list<string>|null $visibleOwners
     * @param array<string, DateTimeImmutable> $staleCutoffs
     * @param array{owner?: string|null, role?: string|null, tag?: string|null} $filters
     *
     * @return list<array{stage: string, currency: string|null, count: int, sum: string|null, weighted: string|null, stale: int}>
     */
    public function stageNumbers(TenantRef $tenant, string $funnelId, ?array $visibleOwners, array $filters, ?DateTimeImmutable $at, array $staleCutoffs, DateTimeImmutable $now): array;

    /**
     * How many distinct items entered each stage (conversion) and the average seconds spent in each stage, from the
     * stage history of the funnel, with the same visibility and filters.
     *
     * @param list<string>|null $visibleOwners
     * @param array{owner?: string|null, role?: string|null, tag?: string|null} $filters
     *
     * @return array{reached: array<string, int>, seconds: array<string, int>}
     */
    public function stageFlow(TenantRef $tenant, string $funnelId, ?array $visibleOwners, array $filters): array;

    /**
     * Deals of the funnel closed in the period: won and lost counts and lost reasons.
     *
     * @param list<string>|null $visibleOwners
     * @param array{owner?: string|null, role?: string|null, tag?: string|null} $filters
     *
     * @return array{won: int, lost: int, reasons: array<string, int>}
     */
    public function closedIn(TenantRef $tenant, string $funnelId, ?array $visibleOwners, array $filters, DateTimeImmutable $from, DateTimeImmutable $to): array;
}
