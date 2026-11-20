<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Input;

use Nowo\CrmKitBundle\Domain\Value\AffiliationPurpose;
use Nowo\CrmKitBundle\Domain\Value\RecordId;

/**
 * The company side of an affiliation (0.3.0): the company, the role (untrusted, ≤ 120), the primary-company flag, the
 * company's primary-contact flag and the purposes; a null part keeps the stored value.
 */
final readonly class CrmApiCompanyLink
{
    /**
     * @param list<string>|null $purposes keys of `affiliations.purposes` (null keeps them)
     */
    public function __construct(
        public RecordId $companyId,
        public ?string $role = null,
        public ?bool $primary = null,
        public ?bool $primaryContact = null,
        public ?array $purposes = null,
    ) {
    }

    /**
     * From an input array (`company_id`, `role`, `primary`, `primary_contact`, `purposes`); a company id given
     * separately (a path) wins.
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data, ?RecordId $companyId = null, string $prefix = ''): self
    {
        CrmApiFields::only($data, ['company_id', 'contact_id', 'role', 'primary', 'primary_contact', 'purposes'], $prefix);

        return new self(
            $companyId ?? CrmApiFields::requiredId($data, 'company_id'),
            CrmApiFields::text($data, 'role', 120),
            CrmApiFields::bool($data, 'primary'),
            CrmApiFields::bool($data, 'primary_contact'),
            CrmApiFields::keys($data, 'purposes', AffiliationPurpose::PATTERN, 32),
        );
    }

    /**
     * The canonical form.
     *
     * @return array<string, mixed>
     */
    public function canonical(): array
    {
        return ['company_id' => $this->companyId->value, 'role' => $this->role, 'primary' => $this->primary, 'primary_contact' => $this->primaryContact, 'purposes' => $this->purposes];
    }
}
