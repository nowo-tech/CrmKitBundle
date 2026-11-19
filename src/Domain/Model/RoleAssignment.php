<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Model;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;

/**
 * A party role held by a company or a contact, e.g. `customer` (unique per tenant, record and role; contract
 * section 6.8). A record holds any number of roles.
 */
class RoleAssignment
{
    /**
     * Wires the row.
     */
    public function __construct(
        private string $id,
        private string $tenantId,
        private SubjectType $subjectType,
        private string $subjectId,
        private string $roleKey,
        private DateTimeImmutable $createdAt,
        private ?string $createdById = null,
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

    /** The role key. */
    public function getRoleKey(): string
    {
        return $this->roleKey;
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

    /** Who added it. */
    public function getCreatedById(): ?string
    {
        return $this->createdById;
    }
}
