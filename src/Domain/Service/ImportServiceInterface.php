<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Service;

use Nowo\CrmKitBundle\Domain\Enum\DuplicatePolicy;
use Nowo\CrmKitBundle\Domain\Enum\ImportFormat;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Read\ImportView;

/**
 * CSV and vCard imports (contract sections 9 and 15.4), `edit`.
 */
interface ImportServiceInterface
{
    /**
     * Parses a file into an import in preview; its id.
     */
    public function upload(SubjectType $type, ImportFormat $format, string $fileName, string $bytes): string;

    /**
     * The import with its first rows validated against the mapping.
     */
    public function preview(string $id, int $rows = 20): ImportView;

    /**
     * The import with its first rows validated against another mapping, stored nowhere (0.4.0: the preview step of the
     * import modal). An invalid mapping is an input error on `mapping`.
     *
     * @param array<string, string> $mapping column => field, as for {@see self::confirm()}
     */
    public function previewMapping(string $id, array $mapping, int $rows = 20): ImportView;

    /**
     * 0.4.0: the acting person's unfinished imports in the tenant (running: paused after `import.sync_rows`, waiting
     * for a person, or stopped by a conflict), newest first: how many, and the first `$limit` (id, file name). `import`.
     *
     * @return array{total: int, imports: list<array{id: string, file: string}>}
     */
    public function unfinished(int $limit = 5): array;

    /**
     * Claims the import for running with the chosen mapping (one confirm wins; a second answers 409).
     *
     * @param array<string, string> $mapping column => field (`first_name`, `custom.<key>`, `tags`, or '' to skip)
     */
    public function confirm(string $id, array $mapping, DuplicatePolicy $policy, int $expectedVersion): void;

    /**
     * Runs up to `$maxChunks` chunks of a running import (null: until done); whether it is done.
     */
    public function run(string $id, ?int $maxChunks = null): bool;

    /**
     * Cancels an import in preview (or a running one whose claim is stale).
     */
    public function cancel(string $id): void;
}
