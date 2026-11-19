<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Security;

use Nowo\CrmKitBundle\Application\CrmAccess;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;

/**
 * The one access gate of the CRM's pages (REQ-UI-002), on the same port the application services ask: no tenant, CRM
 * off for the tenant or nobody acting ⇒ {@see NotAvailableException} (404), refused ⇒ {@see AccessDeniedException}
 * (403). {@see CrmKitAccessListener} calls it on every bundle route; controllers call it for the action's permission.
 * Stateless.
 */
final readonly class CrmKitGuard
{
    /**
     * Wires the access gate.
     */
    public function __construct(private CrmAccess $access)
    {
    }

    /**
     * Requires a permission, tenant-wide or on one record.
     *
     * @throws AccessDeniedException|NotAvailableException
     */
    public function requireGranted(CrmPermission $permission, ?SubjectRef $subject = null): void
    {
        $context = $this->access->base();
        $this->access->require($context, $permission, $subject);
    }

    /**
     * Whether a permission is held (false when the CRM is not available): menus and buttons.
     */
    public function isGranted(CrmPermission $permission, ?SubjectRef $subject = null): bool
    {
        return $this->access->can($permission, $subject);
    }

    /**
     * Every permission's decision, keyed by its value (`view`, `edit`, …), tenant-wide or on one record.
     *
     * @return array<string, bool>
     */
    public function grants(?SubjectRef $subject = null): array
    {
        $grants = [];
        foreach (CrmPermission::cases() as $permission) {
            $grants[$permission->value] = $this->isGranted($permission, $subject);
        }

        return $grants;
    }
}
