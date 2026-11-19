<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Service;

use Nowo\CrmKitBundle\Domain\Enum\SubjectType;

/**
 * Create, edit, trash, restore and purge companies and contacts; contact–company affiliations (contract section 7).
 * Every call checks tenant, feature, actor and permission; writes are write units (409 on conflicts).
 */
interface RecordServiceInterface
{
    /**
     * Creates a record; its id.
     */
    public function create(RecordInput $input): string;

    /**
     * Edits a record shown at `$expectedVersion`; the new version.
     */
    public function update(string $id, RecordInput $input, int $expectedVersion): int;

    /**
     * Moves a record to the trash.
     */
    public function trash(SubjectType $type, string $id, int $expectedVersion): void;

    /**
     * Brings a trashed record back (plan limit again).
     */
    public function restore(SubjectType $type, string $id): void;

    /**
     * Deletes a trashed record with its children (`manage`).
     */
    public function purge(SubjectType $type, string $id): void;

    /**
     * Relates a contact to a company; the affiliation id (an existing pair is updated). `$primary`: the contact's
     * primary company. `$primaryContact`: the company's primary contact (true clears the flag of the company's other
     * contacts; null keeps the stored flag). `$purposes`: keys of `affiliations.purposes` (null keeps the stored ones).
     *
     * @param list<string>|null $purposes
     */
    public function affiliate(string $contactId, string $companyId, ?string $role, bool $primary, ?bool $primaryContact = null, ?array $purposes = null): string;

    /**
     * Makes a contact the company's primary contact (at most one per company: the flag of the others is cleared), or
     * clears the company's primary contact (`$contactId` null). The contact must be affiliated to the company.
     */
    public function setPrimaryContact(string $companyId, ?string $contactId): void;

    /**
     * Replaces the party roles of a company or contact (set semantics over the keys of `roles.catalogue`; stored roles
     * no longer in the catalogue are kept). Audited after the commit.
     *
     * @param list<string> $roles
     */
    public function setRoles(SubjectType $type, string $id, array $roles): void;

    /**
     * Removes an affiliation.
     */
    public function unaffiliate(string $affiliationId): void;
}
