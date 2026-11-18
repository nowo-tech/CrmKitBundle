<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Model;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;

/**
 * The trace of a merge (insert only): the merged record's values before the merge and the fields taken from it.
 */
class MergeRecord
{
    /**
     * @param array<string, mixed> $snapshot the merged record before the merge
     * @param list<string> $taken fields taken from it
     */
    public function __construct(
        private string $id,
        private string $tenantId,
        private SubjectType $subjectType,
        private string $survivorId,
        private string $mergedId,
        private array $snapshot,
        private array $taken,
        private ?string $actorId,
        private DateTimeImmutable $mergedAt,
    ) {
    }

    /** The row id. */
    public function getId(): string
    {
        return $this->id;
    }

    /** The tenant. */
    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    /** The kind of record. */
    public function getSubjectType(): SubjectType
    {
        return $this->subjectType;
    }

    /** The record that stays. */
    public function getSurvivorId(): string
    {
        return $this->survivorId;
    }

    /** The record that became a tombstone. */
    public function getMergedId(): string
    {
        return $this->mergedId;
    }

    /**
     * The merged record before the merge.
     *
     * @return array<string, mixed>
     */
    public function getSnapshot(): array
    {
        return $this->snapshot;
    }

    /**
     * Fields taken from the merged record.
     *
     * @return list<string>
     */
    public function getTaken(): array
    {
        return $this->taken;
    }

    /** Who merged. */
    public function getActorId(): ?string
    {
        return $this->actorId;
    }

    /** When. */
    public function getMergedAt(): DateTimeImmutable
    {
        return $this->mergedAt;
    }
}
