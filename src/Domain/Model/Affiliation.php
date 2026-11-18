<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Model;

use DateTimeImmutable;

/**
 * A contact works for (or relates to) a company, with a role; at most one primary company per contact.
 */
class Affiliation
{
    /**
     * @param string|null $role e.g. "Purchasing manager"
     */
    public function __construct(
        private string $id,
        private string $tenantId,
        private string $contactId,
        private string $companyId,
        private ?string $role,
        private bool $primary,
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

    /** The contact. */
    public function getContactId(): string
    {
        return $this->contactId;
    }

    /** The company. */
    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    /** The role. */
    public function getRole(): ?string
    {
        return $this->role;
    }

    /** Whether it is the contact's primary company. */
    public function isPrimary(): bool
    {
        return $this->primary;
    }

    /** Sets or clears the primary flag. */
    public function setPrimary(bool $primary): void
    {
        $this->primary = $primary;
    }

    /** Points the affiliation at another contact or company (merge). */
    public function moveTo(?string $contactId, ?string $companyId): void
    {
        $this->contactId = $contactId ?? $this->contactId;
        $this->companyId = $companyId ?? $this->companyId;
    }

    /** When it was created. */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
