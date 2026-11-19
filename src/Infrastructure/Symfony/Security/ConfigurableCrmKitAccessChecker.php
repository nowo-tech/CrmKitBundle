<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Security;

use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Throwable;

/**
 * The default area checker (REQ-UI-002): granted when the security user holds **any** role of the area's list
 * (`security.access_roles`, `manage_roles`, `settings_roles`, `export_roles`, `erase_roles`, `amounts_roles`,
 * `campaigns_roles`). An empty list (allowed for `access_roles`, `manage_roles`, `amounts_roles` and `campaigns_roles`
 * only) means any authenticated user for that area (the firewall and the record checks still apply). Without SecurityBundle, or
 * without a user, nothing is granted (fail closed), unless the list is empty. Stateless.
 */
final readonly class ConfigurableCrmKitAccessChecker implements TenantAwareCrmKitAccessCheckerInterface
{
    /**
     * @param list<string> $accessRoles
     * @param list<string> $manageRoles
     * @param list<string> $settingsRoles
     * @param list<string> $exportRoles
     * @param list<string> $eraseRoles
     * @param list<string> $amountsRoles
     * @param list<string> $campaignsRoles
     */
    public function __construct(
        private ?AuthorizationCheckerInterface $authorization = null,
        private array $accessRoles = ['ROLE_ADMIN'],
        private array $manageRoles = ['ROLE_ADMIN'],
        private array $settingsRoles = ['ROLE_ADMIN'],
        private array $exportRoles = ['ROLE_ADMIN'],
        private array $eraseRoles = ['ROLE_ADMIN'],
        private array $amountsRoles = ['ROLE_ADMIN'],
        private array $campaignsRoles = ['ROLE_ADMIN'],
    ) {
    }

    /** {@inheritDoc} */
    public function canAccess(?object $user, ?string $tenantKey = null): bool
    {
        return $this->any($user, $this->accessRoles);
    }

    /** {@inheritDoc} */
    public function canCreate(?object $user, ?string $tenantKey = null): bool
    {
        return $this->any($user, $this->manageRoles);
    }

    /** {@inheritDoc} */
    public function canEdit(?object $user, ?string $tenantKey = null): bool
    {
        return $this->any($user, $this->manageRoles);
    }

    /** {@inheritDoc} */
    public function canDelete(?object $user, ?string $tenantKey = null): bool
    {
        return $this->any($user, $this->manageRoles);
    }

    /** {@inheritDoc} */
    public function canImport(?object $user, ?string $tenantKey = null): bool
    {
        return $this->any($user, $this->manageRoles);
    }

    /** {@inheritDoc} */
    public function canExport(?object $user, ?string $tenantKey = null): bool
    {
        return $this->any($user, $this->exportRoles);
    }

    /** {@inheritDoc} */
    public function canManageSettings(?object $user, ?string $tenantKey = null): bool
    {
        return $this->any($user, $this->settingsRoles);
    }

    /** {@inheritDoc} */
    public function canErase(?object $user, ?string $tenantKey = null): bool
    {
        return $this->any($user, $this->eraseRoles);
    }

    /** {@inheritDoc} */
    public function canViewAmounts(?object $user, ?string $tenantKey = null): bool
    {
        return $this->any($user, $this->amountsRoles);
    }

    /** {@inheritDoc} */
    public function canSendCampaigns(?object $user, ?string $tenantKey = null): bool
    {
        return $this->any($user, $this->campaignsRoles);
    }

    /**
     * @param list<string> $roles
     */
    private function any(?object $user, array $roles): bool
    {
        if ($roles === []) {
            return true;
        }
        if ($user === null || !$this->authorization instanceof AuthorizationCheckerInterface) {
            return false;
        }
        foreach ($roles as $role) {
            try {
                if ($this->authorization->isGranted($role)) {
                    return true;
                }
            } catch (Throwable) {
                return false;
            }
        }

        return false;
    }
}
