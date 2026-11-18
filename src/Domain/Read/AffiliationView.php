<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Read;

/**
 * A contact–company relation, with both labels.
 */
final readonly class AffiliationView
{
    /**
     * Wires the view.
     */
    public function __construct(
        public string $id,
        public string $contactId,
        public string $contactName,
        public string $companyId,
        public string $companyName,
        public ?string $role,
        public bool $primary,
    ) {
    }
}
