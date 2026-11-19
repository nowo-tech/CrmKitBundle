<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Service;

/**
 * Exports (contract section 9): CSV of a list (`manage`), vCard of a contact (`view`).
 */
interface CrmExporterInterface
{
    /**
     * CSV lines (header first) of every record matching the query's filters, streamed by keyset; cells that start
     * with `=`, `+`, `-`, `@`, tab or CR are prefixed with `'`.
     *
     * @return iterable<string>
     */
    public function csv(ListQuery $query): iterable;

    /**
     * A vCard 4.0 of a contact.
     */
    public function vcard(string $contactId): string;
}
