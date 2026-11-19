<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Read;

/**
 * A contact–company relation, with both labels.
 */
final readonly class AffiliationView
{
    /**
     * @param bool $primary the contact's primary company
     * @param bool $primaryContact the company's primary contact
     * @param list<string> $purposes purpose keys, list order (purposes no longer configured are left out)
     */
    public function __construct(
        public string $id,
        public string $contactId,
        public string $contactName,
        public string $companyId,
        public string $companyName,
        public ?string $role,
        public bool $primary,
        public bool $primaryContact = false,
        public array $purposes = [],
    ) {
    }
}
