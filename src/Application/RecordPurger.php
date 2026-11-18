<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Nowo\CrmKitBundle\Application\Port\CrmStoreInterface;
use Nowo\CrmKitBundle\Application\Shared\TransactionRunnerInterface;
use Nowo\CrmKitBundle\Domain\Enum\RecordStatus;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Model\Contact;
use Nowo\CrmKitBundle\Domain\Model\CrmRecord;
use Nowo\CrmKitBundle\Domain\Model\CrmSettings;
use Nowo\CrmKitBundle\Domain\Port\CrmAuditSinkInterface;
use Nowo\CrmKitBundle\Domain\Value\AuditEntry;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Psr\Clock\ClockInterface;

use function count;

/**
 * Deletes records with their children, and the retention purge (contract section 16.4): trashed records after
 * `trash_days`, timeline entries after `timeline_years`, import rows after `import_keep_days`, merge traces after
 * `merge_keep_days`. Batches of 200, one transaction each, re-checked inside. Stateless.
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
    ) {
    }

    /**
     * Deletes a record and every child row (inside the caller's unit).
     */
    public function deleteRecord(TenantRef $tenant, CrmRecord $record): void
    {
        $type = $record::type();
        $id   = $record->getId();
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
        foreach ($this->store->affiliations($tenant, $type, $id) as $row) {
            $this->store->remove($row); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        }
        if ($record instanceof Contact) {
            foreach ($this->store->consents($tenant, $id) as $row) {
                $this->store->remove($row); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            }
        }
        $this->store->remove($record); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
    }

    /**
     * Runs the retention purge for every tenant (or one); counts per kind, or null when another run holds the
     * single-run claim (a claim older than an hour is taken over: a run that died).
     *
     * @return array{records: int, activities: int, import_rows: int, merges: int}|null
     */
    public function purge(?TenantRef $only = null, bool $dryRun = false): ?array
    {
        if ($dryRun) {
            return $this->run($only, true);
        }
        $now = $this->clock->now()->getTimestamp();
        if (!$this->runner->write(fn (): bool => $this->store->claimPurge($now, $now - self::STALE_CLAIM_SECONDS))) {
            return null;
        }

        try {
            return $this->run($only, false);
        } finally {
            $this->runner->write(fn () => $this->store->releasePurge());
        }
    }

    /**
     * The purge itself.
     *
     * @return array{records: int, activities: int, import_rows: int, merges: int}
     */
    private function run(?TenantRef $only, bool $dryRun): array
    {
        $totals  = ['records' => 0, 'activities' => 0, 'import_rows' => 0, 'merges' => 0];
        $tenants = $only instanceof TenantRef ? [$only] : array_map(static fn (string $t): TenantRef => new TenantRef($t), $this->runner->read(fn (): array => $this->store->tenants()));
        foreach ($tenants as $tenant) {
            $now      = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
            $settings = $this->runner->read(fn (): ?CrmSettings => $this->store->settings($tenant)) ?? new CrmSettings($tenant->value, $now);
            $counts   = [
                'records'     => $this->purgeTrash($tenant, $now->sub(new DateInterval('P' . $settings->getTrashDays() . 'D')), $dryRun),
                'activities'  => $this->batches(fn (): int => $this->store->deleteActivitiesBefore($tenant, $now->sub(new DateInterval('P' . $settings->getTimelineYears() . 'Y')), self::BATCH), $dryRun),
                'import_rows' => $this->batches(fn (): int => $this->store->deleteImportRowsBefore($tenant, $now->sub(new DateInterval('P' . $settings->getImportKeepDays() . 'D')), self::BATCH), $dryRun),
                'merges'      => $this->batches(fn (): int => $this->store->deleteMergesBefore($tenant, $now->sub(new DateInterval('P' . $settings->getMergeKeepDays() . 'D')), self::BATCH), $dryRun),
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

    private function purgeTrash(TenantRef $tenant, DateTimeImmutable $before, bool $dryRun): int
    {
        $count = 0;
        foreach ([SubjectType::Company, SubjectType::Contact] as $type) {
            do {
                $ids = $this->runner->read(fn (): array => $this->store->trashedBefore($tenant, $type, $before, self::BATCH));
                if ($dryRun) {
                    $count += count($ids);

                    continue 2;
                }
                $done = $this->runner->write(function () use ($tenant, $type, $ids, $before): int { // @igor-ignore - the runner calls this closure at once and keeps nothing
                    $deleted = 0;
                    foreach ($this->store->lockRecords($type, $tenant, $ids) as $record) {
                        $trashedAt = $record->getTrashedAt();
                        if ($record->getStatus() === RecordStatus::Trashed && $trashedAt instanceof DateTimeImmutable && $trashedAt < $before) {
                            $this->deleteRecord($tenant, $record);
                            ++$deleted;
                        }
                    }

                    return $deleted;
                });
                $count += $done;
            } while (count($ids) === self::BATCH && $done > 0);
        }

        return $count;
    }

    /**
     * Runs a deleting batch until it deletes less than a full batch.
     *
     * @param callable(): int $batch
     */
    private function batches(callable $batch, bool $dryRun): int
    {
        if ($dryRun) {
            return 0;
        }
        $total = 0;
        do {
            $deleted = $this->runner->write($batch);
            $total += $deleted;
        } while ($deleted === self::BATCH);

        return $total;
    }
}
