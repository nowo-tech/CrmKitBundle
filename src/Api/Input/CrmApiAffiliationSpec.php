<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Input;

use Nowo\CrmKitBundle\Domain\Value\RecordId;

/**
 * A contact's link to a company (0.3.0, `crm_affiliation_set`): the contact and {@see CrmApiCompanyLink}.
 */
final readonly class CrmApiAffiliationSpec implements CrmApiInput
{
    /**
     * Wires the parts.
     */
    public function __construct(public RecordId $contactId, public CrmApiCompanyLink $link)
    {
    }

    /**
     * From an input array (`contact_id`, `company_id`, `role`, `primary`, `primary_contact`, `purposes`); ids given
     * separately (a path) win over the array's.
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data, ?RecordId $contactId = null, ?RecordId $companyId = null): self
    {
        return new self($contactId ?? CrmApiFields::requiredId($data, 'contact_id'), CrmApiCompanyLink::fromArray($data, $companyId));
    }

    /**
     * {@inheritDoc}
     */
    public function canonical(): array
    {
        return ['contact_id' => $this->contactId->value] + $this->link->canonical();
    }
}
