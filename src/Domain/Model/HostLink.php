<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Model;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;

/**
 * A record linked to a host object by id (contract section 6.5).
 */
class HostLink
{
    /**
     * Wires the row.
     */
    public function __construct(
        private string $id,
        private string $tenantId,
        private SubjectType $subjectType,
        private string $subjectId,
        private string $hostType,
        private string $hostId,
        private ?string $label,
        private ?string $createdById,
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

    /** The host object type. */
    public function getHostType(): string
    {
        return $this->hostType;
    }

    /** The host object id. */
    public function getHostId(): string
    {
        return $this->hostId;
    }

    /** The label stored with the link. */
    public function getLabel(): ?string
    {
        return $this->label;
    }

    /** Who linked it. */
    public function getCreatedById(): ?string
    {
        return $this->createdById;
    }

    /** When. */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Points it at another record (merge). */
    public function moveTo(string $subjectId): void
    {
        $this->subjectId = $subjectId;
    }
}
