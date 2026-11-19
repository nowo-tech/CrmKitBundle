<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Security;

/**
 * Area access checker of the CRM (REQ-UI-002): which parts of the CRM a person may use, independent of one record.
 * The default is {@see ConfigurableCrmKitAccessChecker} (the `security.*_roles` keys); `security.access_checker` points
 * this interface at a host service. Decisions on one record (owner, team) are {@see CrmResourceAccessChecker}'s and the
 * host's `nowo_crm_kit.record_access_check` listeners'.
 */
interface CrmKitAccessCheckerInterface
{
    /**
     * Opening the CRM at all: lists, 360° pages, timeline, search (`security.access_roles`).
     *
     * @param object|null $user the security user (null only with `security.allow_unauthenticated`)
     */
    public function canAccess(?object $user): bool;

    /** Creating companies and contacts (`security.manage_roles`). */
    public function canCreate(?object $user): bool;

    /** Editing records and their notes, tags, roles, links, affiliations, consents; merging (`security.manage_roles`). */
    public function canEdit(?object $user): bool;

    /** Moving records to the trash and restoring them (`security.manage_roles`). */
    public function canDelete(?object $user): bool;

    /** CSV / vCard imports (`security.manage_roles`). */
    public function canImport(?object $user): bool;

    /** CSV export of lists and the GDPR export of a contact (`security.export_roles`). */
    public function canExport(?object $user): bool;

    /** Tags, custom fields, per-tenant settings, purge from the trash (`security.settings_roles`). */
    public function canManageSettings(?object $user): bool;

    /** GDPR erasure of a contact (`security.erase_roles`). */
    public function canErase(?object $user): bool;
}
