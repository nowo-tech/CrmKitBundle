<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Read;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Enum\DuplicatePolicy;
use Nowo\CrmKitBundle\Domain\Enum\ImportFormat;
use Nowo\CrmKitBundle\Domain\Enum\ImportStatus;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;

/**
 * An import with its preview rows.
 */
final readonly class ImportView
{
    /**
     * @param list<string> $columns
     * @param array<string, string> $mapping column => field
     * @param array{created: int, updated: int, duplicate: int, invalid: int, limit: int} $counts
     * @param list<ImportRowView> $rows the first rows
     */
    public function __construct(
        public string $id,
        public SubjectType $subjectType,
        public ImportFormat $format,
        public ImportStatus $status,
        public string $fileName,
        public array $columns,
        public array $mapping,
        public DuplicatePolicy $duplicatePolicy,
        public int $rowsTotal,
        public int $rowsDone,
        public array $counts,
        public array $rows,
        public int $version,
        public ?string $createdById,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $claimedAt,
    ) {
    }
}
