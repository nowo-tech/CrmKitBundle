<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Model;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;

/**
 * A tag on a record (unique per tag and record).
 */
class Tagging
{
    /**
     * Wires the row.
     */
    public function __construct(
        private string $id,
        private string $tenantId,
        private string $tagId,
        private SubjectType $subjectType,
        private string $subjectId,
        private DateTimeImmutable $createdAt,
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

    /** The tag. */
    public function getTagId(): string
    {
        return $this->tagId;
    }

    /** The kind of record. */
    public function getSubjectType(): SubjectType
    {
        return $this->subjectType;
    }

    /** The record. */
    public function getSubjectId(): string
    {
        return $this->subjectId;
    }

    /** Points it at another record (merge). */
    public function moveTo(string $subjectId): void
    {
        $this->subjectId = $subjectId;
    }

    /** When it was added. */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
