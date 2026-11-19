<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Model;

use DateTimeImmutable;

use function array_values;
use function explode;
use function implode;
use function sort;

use const SORT_STRING;

/**
 * A contact works for (or relates to) a company, with a role; at most one primary company per contact and at most one
 * primary contact per company (contract section 6.8); the purposes say what the contact is the company's contact for
 * (`billing`, `orders`, ...), stored as one delimited key column (`,billing,orders,`) so a filter is one exact `LIKE`.
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
        private bool $primaryContact = false,
        private string $purposeKeys = '',
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

    /** Changes the role and the primary-company flag (an existing pair affiliated again). */
    public function change(?string $role, bool $primary): void
    {
        $this->role    = $role;
        $this->primary = $primary;
    }

    /** Sets or clears the primary flag. */
    public function setPrimary(bool $primary): void
    {
        $this->primary = $primary;
    }

    /** Whether the contact is the company's primary contact. */
    public function isPrimaryContact(): bool
    {
        return $this->primaryContact;
    }

    /** Sets or clears the company's primary contact flag. */
    public function setPrimaryContact(bool $primaryContact): void
    {
        $this->primaryContact = $primaryContact;
    }

    /**
     * The purpose keys, sorted.
     *
     * @return list<string>
     */
    public function getPurposes(): array
    {
        $keys = [];
        foreach (explode(',', $this->purposeKeys) as $key) {
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Replaces the purposes (keys already validated; stored sorted and distinct as `,a,b,`).
     *
     * @param list<string> $purposes
     */
    public function setPurposes(array $purposes): void
    {
        $this->purposeKeys = self::encode($purposes);
    }

    /**
     * The stored form of a purpose list: sorted, distinct, `,a,b,` (empty list: '').
     *
     * @param list<string> $purposes
     */
    public static function encode(array $purposes): string
    {
        $keys = [];
        foreach ($purposes as $purpose) {
            $keys[$purpose] = $purpose;
        }
        $keys = array_values($keys);
        sort($keys, SORT_STRING);

        return $keys === [] ? '' : ',' . implode(',', $keys) . ',';
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
