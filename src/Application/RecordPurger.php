<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Nowo\CrmKitBundle\Application\Engagement\EngagementHousekeeping;
use Nowo\CrmKitBundle\Application\Pipeline\PipelineHousekeeping;
use Nowo\CrmKitBundle\Application\Port\CrmStoreInterface;
use Nowo\CrmKitBundle\Application\Port\ExternalRefHooksInterface;
use Nowo\CrmKitBundle\Application\Shared\TransactionRunnerInterface;
use Nowo\CrmKitBundle\Domain\Enum\RecordStatus;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Event\RecordsPurged;
use Nowo\CrmKitBundle\Domain\Model\Contact;
use Nowo\CrmKitBundle\Domain\Model\CrmRecord;
use Nowo\CrmKitBundle\Domain\Model\CrmSettings;
use Nowo\CrmKitBundle\Domain\Port\CrmAuditSinkInterface;
use Nowo\CrmKitBundle\Domain\Value\AuditEntry;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

use function count;

/**
 * Deletes records with their children, and the retention purge (contract section 16.4): trashed records after
 * `trash_days`, timeline entries after `timeline_years`, import rows after `import_keep_days`, merge traces after
 * `merge_keep_days` (with the merge tombstones last changed before then). Batches of 200, one transaction each,
 * re-checked inside. Every committed batch of deleted records is announced with {@see RecordsPurged} after its commit
 * (a rolled-back or replayed batch announces nothing). Stateless.
 *
 * @internal
 */
final readonly class RecordPurger
{
    /** Rows per batch. */
    public const BATCH = 200;

    /** Age after which a purge claim is taken over (the run that held it died). */
    public const STALE_CLAIM_SECONDS = 3600;

    /**
     * Wires the collaborators.
     */
    public function __construct(
        private CrmStoreInterface $store,
        private TransactionRunnerInterface $runner,
        private CrmAuditSinkInterface $audit,
        private ClockInterface $clock,
        private ?EventDispatcherInterface $events = null,
        private ?PipelineHousekeeping $pipeline = null,
        private ?ExternalRefHooksInterface $refs = null,
        private ?EngagementHousekeeping $engagements = null,
    ) {
    }

    /**
     * Deletes a record and every child row (inside the caller's unit).
     */
    public function deleteRecord(TenantRef $tenant, CrmRecord $record): void
    {
        $type = $record::type();
        $id   = $record->getId();
        // 0.10.0: its HubSpot references go with it (an erased person's tombstone stays).
        $this->refs?->forget($tenant, $type, $id);
        foreach ($this->store->taggings($tenant, $type, [$id]) as $row) {
            $this->store->remove($row); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        }
        foreach ($this->store->notes($tenant, $type, $id) as $row) {
            $this->store->remove($row); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        }
        foreach ($this->store->allActivities($tenant, $type, $id) as $row) {
            $this->store->remove($row); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        }
        foreach ($this->store->links($tenant, $type, $id) as $row) {
            $this->store->remove($row); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        }
        foreach ($this->store->roleAssignments($tenant, $type, [$id]) as $row) {
            $this->store->remove($row); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        }
        foreach ($this->store->affiliations($tenant, $type, $id) as $row) {
            $this->store->remove($row); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        }
        if ($record instanceof Contact) {
            foreach ($this->store->consents($tenant, $id) as $row) {
                $this->store->remove($row); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            }
        }
        // 0.3.0: its deals are unlinked; its stage history, lifecycle stages and next actions go with it.
        $this->pipeline?->forgetRecord($tenant, $type, $id);
        // 0.11.0: its logged activities and the associations and attendee rows that point at it go with it.
        $this->engagements?->forgetRecord($tenant, $type, $id);
        $this->store->remove($record); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
    }

    /**
     * Runs the retention purge for every tenant (or one); counts per kind, or null when another run holds the
     * single-run claim (a claim older than an hour is taken over: a run that died). The claim is refreshed in every
     * batch, so a long run keeps it; a run whose claim was taken over stops at its next batch (null) and never frees
     * the other run's claim.
     *
     * @return array{records: int, tombstones: int, activities: int, import_rows: int, merges: int, deals: int, engagements: int}|null
     */
    public function purge(?TenantRef $only = null, bool $dryRun = false): ?array
    {
        $now = $this->clock->now()->getTimestamp();
        if ($dryRun) {
            return $this->run($only, true, new PurgeClaim($now));
        }
        if (!$this->runner->write(fn (): bool => $this->store->claimPurge($now, $now - self::STALE_CLAIM_SECONDS))) {
            return null;
        }
        $claim = new PurgeClaim($now);

        try {
            return $this->run($only, false, $claim);
        } catch (PurgeClaimLostException) {
            return null;
        } finally {
            $this->runner->write(fn () => $this->store->releasePurge($claim->value));
        }
    }

    /**
     * The purge itself.
     *
     * @return array{records: int, tombstones: int, activities: int, import_rows: int, merges: int, deals: int, engagements: int}
     */
    private function run(?TenantRef $only, bool $dryRun, PurgeClaim $claim): array
    {
        $totals  = ['records' => 0, 'tombstones' => 0, 'activities' => 0, 'import_rows' => 0, 'merges' => 0, 'deals' => 0, 'engagements' => 0];
        $tenants = $only instanceof TenantRef ? [$only] : array_map(static fn (string $t): TenantRef => new TenantRef($t), $this->runner->read(fn (): array => $this->store->tenants()));
        foreach ($tenants as $tenant) {
            $now          = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
            $settings     = $this->runner->read(fn (): ?CrmSettings => $this->store->settings($tenant)) ?? new CrmSettings($tenant->value, $now);
            $mergedBefore = $now->sub(new DateInterval('P' . $settings->getMergeKeepDays() . 'D'));
            $counts       = [
                'records'     => $this->purgeRecords($tenant, $now->sub(new DateInterval('P' . $settings->getTrashDays() . 'D')), $dryRun, $claim, RecordStatus::Trashed),
                'tombstones'  => $this->purgeRecords($tenant, $mergedBefore, $dryRun, $claim, RecordStatus::Merged),
                'activities'  => $this->batches(fn (): int => $this->store->deleteActivitiesBefore($tenant, $now->sub(new DateInterval('P' . $settings->getTimelineYears() . 'Y')), self::BATCH), $dryRun, $claim),
                'import_rows' => $this->batches(fn (): int => $this->store->deleteImportRowsBefore($tenant, $now->sub(new DateInterval('P' . $settings->getImportKeepDays() . 'D')), self::BATCH), $dryRun, $claim),
                'merges'      => $this->batches(fn (): int => $this->store->deleteMergesBefore($tenant, $mergedBefore, self::BATCH), $dryRun, $claim),
                'deals'       => $this->purgeDeals($tenant, $now->sub(new DateInterval('P' . $settings->getTrashDays() . 'D')), $dryRun, $claim),
                // 0.11.0: logged activities are kept as long as the timeline (timeline_years).
                'engagements' => $this->purgeEngagements($tenant, $now->sub(new DateInterval('P' . $settings->getTimelineYears() . 'Y')), $dryRun, $claim),
            ];
            foreach ($counts as $kind => $count) {
                $totals[$kind] += $count;
            }
            if (!$dryRun && array_sum($counts) > 0) {
                $this->audit->record(new AuditEntry($tenant, null, 'crm.retention.purged', null, null, $counts, $now));
            }
        }

        return $totals;
    }

    /**
     * Deletes trashed records past `trash_days`, or merge tombstones past `merge_keep_days` (their status and time
     * re-checked under the lock).
     */
    private function purgeRecords(TenantRef $tenant, DateTimeImmutable $before, bool $dryRun, PurgeClaim $claim, RecordStatus $status): int
    {
        $count = 0;
        foreach ([SubjectType::Company, SubjectType::Contact] as $type) {
            do {
                $ids = $this->runner->read(fn (): array => $status === RecordStatus::Trashed ? $this->store->trashedBefore($tenant, $type, $before, self::BATCH) : $this->store->mergedBefore($tenant, $type, $before, self::BATCH));
                if ($dryRun) {
                    $count += $status === RecordStatus::Trashed ? count($ids) : 0;

                    continue 2;
                }
                // The deleted ids are the unit's result: a replayed attempt starts from an empty list.
                $deleted = $this->claimed($claim, function () use ($tenant, $type, $ids, $before, $status): array { // @igor-ignore - the runner calls this closure at once and keeps nothing
                    $deleted = [];
                    foreach ($this->store->lockRecords($type, $tenant, $ids) as $record) {
                        $at = $status === RecordStatus::Trashed ? $record->getTrashedAt() : $record->getUpdatedAt();
                        if ($record->getStatus() === $status && $at instanceof DateTimeImmutable && $at < $before) {
                            $this->deleteRecord($tenant, $record);
                            $deleted[] = SubjectRef::of($type, $record->getId());
                        }
                    }

                    return $deleted;
                });
                // Committed: announce the batch (ids only).
                if ($deleted !== []) {
                    $this->events?->dispatch(new RecordsPurged($tenant, $this->clock->now(), $deleted));
                }
                $done = count($deleted);
                $count += $done;
            } while (count($ids) === self::BATCH && $done > 0);
        }

        return $count;
    }

    /**
     * Deletes the deals trashed before `trash_days` (0.3.0; none while the funnels are off).
     */
    private function purgeDeals(TenantRef $tenant, DateTimeImmutable $before, bool $dryRun, PurgeClaim $claim): int
    {
        $pipeline = $this->pipeline;
        if (!$pipeline instanceof PipelineHousekeeping) {
            return 0;
        }
        if ($dryRun) {
            return $this->runner->read(static fn (): int => $pipeline->countTrashed($tenant, $before));
        }
        $total = 0;
        do {
            $deleted = $this->claimed($claim, static fn (): int => $pipeline->purgeTrashed($tenant, $before));
            $total += $deleted;
        } while ($deleted > 0 && $deleted % PipelineHousekeeping::BATCH === 0);

        return $total;
    }

    /**
     * Deletes the logged activities that happened before the timeline's retention (0.11.0; none while activities are
     * off).
     */
    private function purgeEngagements(TenantRef $tenant, DateTimeImmutable $before, bool $dryRun, PurgeClaim $claim): int
    {
        $engagements = $this->engagements;
        if (!$engagements instanceof EngagementHousekeeping || $dryRun) {
            return 0;
        }
        $total = 0;
        do {
            $deleted = $this->claimed($claim, static fn (): int => $engagements->purgeBefore($tenant, $before));
            $total += $deleted;
        } while ($deleted === EngagementHousekeeping::BATCH);

        return $total;
    }

    /**
     * Runs a deleting batch until it deletes less than a full batch.
     *
     * @param callable(): int $batch
     */
    private function batches(callable $batch, bool $dryRun, PurgeClaim $claim): int
    {
        if ($dryRun) {
            return 0;
        }
        $total = 0;
        do {
            $deleted = $this->claimed($claim, $batch);
            $total += $deleted;
        } while ($deleted === self::BATCH);

        return $total;
    }

    /**
     * One batch in one write unit, with the claim refreshed in the same transaction; the new claim time is kept only
     * after the commit (a replayed attempt refreshes from the committed one). Returns the batch's result once committed.
     *
     * @template T
     *
     * @param callable(): T $batch
     *
     * @return T
     */
    private function claimed(PurgeClaim $claim, callable $batch): mixed
    {
        // Another run took the claim over: PurgeClaimLostException, caught by purge().
        [$deleted, $next] = $this->runner->write(function () use ($claim, $batch): array { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $next = $this->store->refreshPurge($claim->value, $this->clock->now()->getTimestamp()) ?? throw new PurgeClaimLostException();

            return [$batch(), $next];
        });
        $claim->value = $next; // @igor-ignore - per-run holder, never a shared service

        return $deleted;
    }
}
