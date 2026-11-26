<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application\Pipeline;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Application\Engagement\EngagementHousekeeping;
use Nowo\CrmKitBundle\Application\Pipeline\Port\PipelineStoreInterface;
use Nowo\CrmKitBundle\Application\PlanCounter;
use Nowo\CrmKitBundle\Application\Port\CrmStoreInterface;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Model\Pipeline\Deal;
use Nowo\CrmKitBundle\Domain\Model\Pipeline\NextAction;
use Nowo\CrmKitBundle\Domain\Model\Pipeline\PartyStage;
use Nowo\CrmKitBundle\Domain\Model\Pipeline\StageChange;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

use function array_map;
use function count;

use const DATE_ATOM;

/**
 * The funnels' part of the CRM's housekeeping (0.3.0), inside the caller's unit: a purged deal's rows, a purged or
 * erased company / contact (its deals unlinked, its stage history, lifecycle stages and next actions deleted), a
 * merged one (everything pointed at the survivor), the GDPR export of a contact, the trash retention of deals and the
 * `deals` counter rebuild. No access checks here: the callers checked. Stateless.
 *
 * @internal
 */
final readonly class PipelineHousekeeping
{
    /** Deals per retention batch. */
    public const BATCH = 200;

    /**
     * Wires the collaborators.
     */
    public function __construct(private PipelineStoreInterface $store, private CrmStoreInterface $crm, private PlanCounter $counter, private ?EngagementHousekeeping $engagements = null)
    {
    }

    /**
     * Deletes a deal with its stage history, next actions and timeline.
     */
    public function deleteDeal(TenantRef $tenant, Deal $deal): void
    {
        $this->store->deleteItemRows($tenant, SubjectType::Deal, $deal->getId());
        foreach ($this->crm->allActivities($tenant, SubjectType::Deal, $deal->getId()) as $row) {
            $this->crm->remove($row); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        }
        // 0.11.0: the activities associated with the deal stop pointing at it.
        $this->engagements?->forgetRecord($tenant, SubjectType::Deal, $deal->getId());
        $this->crm->remove($deal); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
    }

    /**
     * Forgets a company or contact that is purged or erased: its deals unlinked, its funnel rows deleted.
     */
    public function forgetRecord(TenantRef $tenant, SubjectType $type, string $id): void
    {
        $this->store->unlinkDeals($tenant, $type, $id);
        $this->store->deleteItemRows($tenant, $type, $id);
    }

    /**
     * Points a merged company's or contact's funnel rows at the survivor.
     */
    public function moveRecord(TenantRef $tenant, SubjectType $type, string $fromId, string $toId): void
    {
        $this->store->repoint($tenant, $type, $fromId, $toId);
    }

    /**
     * What the funnels hold about a contact (GDPR export): its deals, lifecycle stages, stage history and next actions.
     *
     * @return array{deals: list<array<string, mixed>>, lifecycle: list<array<string, mixed>>, stage_history: list<array<string, mixed>>, next_actions: list<array<string, mixed>>}
     */
    public function exportContact(TenantRef $tenant, string $contactId): array
    {
        return [
            'deals'         => array_map(static fn (Deal $d): array => ['id' => $d->getId(), 'title' => $d->getTitle(), 'status' => $d->getStatus()->value, 'created_at' => $d->getCreatedAt()->format(DATE_ATOM)], $this->store->dealsOf($tenant, SubjectType::Contact, $contactId)),
            'lifecycle'     => array_map(static fn (PartyStage $p): array => ['funnel' => $p->getFunnelId(), 'stage' => $p->getStageId(), 'entered_at' => $p->getEnteredAt()->format(DATE_ATOM)], $this->store->partyStages($tenant, SubjectType::Contact, $contactId)),
            'stage_history' => array_map(static fn (StageChange $c): array => ['funnel' => $c->getFunnelId(), 'from' => $c->getFromStageId(), 'to' => $c->getToStageId(), 'occurred_at' => $c->getOccurredAt()->format(DATE_ATOM)], $this->store->stageChanges($tenant, SubjectType::Contact, $contactId, 1000)),
            'next_actions'  => array_map(static fn (NextAction $n): array => ['title' => $n->getTitle(), 'due_at' => $n->getDueAt()->format(DATE_ATOM), 'status' => $n->getStatus()->value], $this->store->nextActions($tenant, SubjectType::Contact, $contactId, false, 1000)),
        ];
    }

    /**
     * Deletes one batch of deals trashed before the time (status and time re-checked under the lock); how many.
     */
    public function purgeTrashed(TenantRef $tenant, DateTimeImmutable $before): int
    {
        $deleted = 0;
        foreach ($this->store->trashedDealsBefore($tenant, $before, self::BATCH) as $id) {
            $deal = $this->store->deal($tenant, $id, true);
            $at   = $deal?->getTrashedAt();
            if ($deal instanceof Deal && $deal->isTrashed() && $at instanceof DateTimeImmutable && $at < $before) {
                $this->deleteDeal($tenant, $deal);
                ++$deleted;
            }
        }

        return $deleted;
    }

    /**
     * How many deals a purge would delete (dry run).
     */
    public function countTrashed(TenantRef $tenant, DateTimeImmutable $before): int
    {
        return count($this->store->trashedDealsBefore($tenant, $before, self::BATCH));
    }

    /**
     * Recounts the `deals` counter of a tenant (inside the caller's unit).
     */
    public function rebuildCounter(TenantRef $tenant): int
    {
        return $this->counter->rebuild($tenant, SubjectType::Deal, fn (): int => $this->store->countLiveDeals($tenant));
    }
}
