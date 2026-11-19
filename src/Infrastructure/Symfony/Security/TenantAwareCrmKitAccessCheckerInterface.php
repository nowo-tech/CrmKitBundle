<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Security;

/**
 * {@see CrmKitAccessCheckerInterface} with the tenant of the call (the `TenantRef` value of the tenant resolver), for a
 * host whose areas differ per tenant (an organisation admin of one tenant only). The bundle passes the tenant whenever
 * the checker implements this interface.
 */
interface TenantAwareCrmKitAccessCheckerInterface extends CrmKitAccessCheckerInterface
{
    /** {@inheritDoc} */
    public function canAccess(?object $user, ?string $tenantKey = null): bool;

    /** {@inheritDoc} */
    public function canCreate(?object $user, ?string $tenantKey = null): bool;

    /** {@inheritDoc} */
    public function canEdit(?object $user, ?string $tenantKey = null): bool;

    /** {@inheritDoc} */
    public function canDelete(?object $user, ?string $tenantKey = null): bool;

    /** {@inheritDoc} */
    public function canImport(?object $user, ?string $tenantKey = null): bool;

    /** {@inheritDoc} */
    public function canExport(?object $user, ?string $tenantKey = null): bool;

    /** {@inheritDoc} */
    public function canManageSettings(?object $user, ?string $tenantKey = null): bool;

    /** {@inheritDoc} */
    public function canErase(?object $user, ?string $tenantKey = null): bool;

    /** {@inheritDoc} */
    public function canViewAmounts(?object $user, ?string $tenantKey = null): bool;

    /** {@inheritDoc} */
    public function canSendCampaigns(?object $user, ?string $tenantKey = null): bool;
}
