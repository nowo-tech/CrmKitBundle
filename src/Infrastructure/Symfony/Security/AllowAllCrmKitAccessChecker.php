<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Security;

/**
 * Grants every area: demos and tests only (`security.access_checker: Nowo\CrmKitBundle\Infrastructure\Symfony\Security\AllowAllCrmKitAccessChecker`,
 * or `security.allow_unauthenticated: true` without SecurityBundle). Record decisions still go through
 * {@see CrmResourceAccessChecker} and its event.
 */
final class AllowAllCrmKitAccessChecker implements TenantAwareCrmKitAccessCheckerInterface
{
    /** {@inheritDoc} */
    public function canAccess(?object $user, ?string $tenantKey = null): bool
    {
        return true;
    }

    /** {@inheritDoc} */
    public function canCreate(?object $user, ?string $tenantKey = null): bool
    {
        return true;
    }

    /** {@inheritDoc} */
    public function canEdit(?object $user, ?string $tenantKey = null): bool
    {
        return true;
    }

    /** {@inheritDoc} */
    public function canDelete(?object $user, ?string $tenantKey = null): bool
    {
        return true;
    }

    /** {@inheritDoc} */
    public function canImport(?object $user, ?string $tenantKey = null): bool
    {
        return true;
    }

    /** {@inheritDoc} */
    public function canExport(?object $user, ?string $tenantKey = null): bool
    {
        return true;
    }

    /** {@inheritDoc} */
    public function canManageSettings(?object $user, ?string $tenantKey = null): bool
    {
        return true;
    }

    /** {@inheritDoc} */
    public function canErase(?object $user, ?string $tenantKey = null): bool
    {
        return true;
    }
}
