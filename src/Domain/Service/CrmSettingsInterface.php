<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Service;

/**
 * Per-tenant settings (contract section 6.2), `manage`.
 */
interface CrmSettingsInterface
{
    /**
     * The settings (defaults when never saved) and their version (0 when never saved).
     *
     * @return array{trash_days: int, timeline_years: int, import_keep_days: int, merge_keep_days: int, version: int}
     */
    public function get(): array;

    /**
     * Saves the settings shown at `$expectedVersion`.
     */
    public function update(int $trashDays, int $timelineYears, int $importKeepDays, int $mergeKeepDays, int $expectedVersion): void;
}
