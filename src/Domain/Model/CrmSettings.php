<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Model;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Per-tenant settings (contract section 6.2): retention periods.
 */
class CrmSettings
{
    /** Bounds: field => [min, max, default]. */
    public const BOUNDS = [
        'trashDays'      => [7, 365, 30],
        'timelineYears'  => [1, 10, 5],
        'importKeepDays' => [1, 90, 30],
        'mergeKeepDays'  => [1, 365, 90],
    ];

    private int $trashDays = 30;

    private int $timelineYears = 5;

    private int $importKeepDays = 30;

    private int $mergeKeepDays = 90;

    private int $version = 1;

    private ?string $updatedById = null;

    /**
     * Defaults for a tenant.
     */
    public function __construct(private string $tenantId, private DateTimeImmutable $updatedAt)
    {
    }

    /** The tenant. */
    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    /** Days a trashed record can be restored. */
    public function getTrashDays(): int
    {
        return $this->trashDays;
    }

    /** Years the timeline is kept. */
    public function getTimelineYears(): int
    {
        return $this->timelineYears;
    }

    /** Days the rows of a finished import are kept. */
    public function getImportKeepDays(): int
    {
        return $this->importKeepDays;
    }

    /** Days a merge snapshot is kept. */
    public function getMergeKeepDays(): int
    {
        return $this->mergeKeepDays;
    }

    /** The optimistic version. */
    public function getVersion(): int
    {
        return $this->version;
    }

    /** Who changed it last. */
    public function getUpdatedById(): ?string
    {
        return $this->updatedById;
    }

    /** When. */
    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Changes the periods.
     *
     * @throws InvalidArgumentException naming the field out of bounds
     */
    public function change(int $trashDays, int $timelineYears, int $importKeepDays, int $mergeKeepDays, ?string $actor, DateTimeImmutable $now): void
    {
        foreach (['trashDays' => $trashDays, 'timelineYears' => $timelineYears, 'importKeepDays' => $importKeepDays, 'mergeKeepDays' => $mergeKeepDays] as $field => $value) {
            [$min, $max] = self::BOUNDS[$field];
            if ($value < $min || $value > $max) {
                throw new InvalidArgumentException($field);
            }
        }
        $this->trashDays      = $trashDays;
        $this->timelineYears  = $timelineYears;
        $this->importKeepDays = $importKeepDays;
        $this->mergeKeepDays  = $mergeKeepDays;
        $this->updatedById    = $actor;
        $this->updatedAt      = $now;
    }
}
