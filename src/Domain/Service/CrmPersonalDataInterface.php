<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Service;

/**
 * GDPR export and erasure of a contact (contract section 16), `manage`.
 */
interface CrmPersonalDataInterface
{
    /**
     * Everything the CRM holds about the contact, JSON-ready.
     *
     * @return array<string, mixed>
     */
    public function export(string $contactId): array;

    /**
     * Erases the contact's personal data (idempotent).
     */
    public function erase(string $contactId): void;
}
