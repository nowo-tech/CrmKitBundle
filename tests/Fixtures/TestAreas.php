<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Fixtures;

use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Event\RecordAccessCheckEvent;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\TenantAwareCrmKitAccessCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The area checker of the API tests: the grants of {@see TestContext} for the user's identifier in the tenant
 * (`security.access_checker`), plus a record listener that applies `TestContext::$denied` (`<permission>:<id>`).
 */
final readonly class TestAreas implements TenantAwareCrmKitAccessCheckerInterface
{
    public function __construct(private TestContext $context)
    {
    }

    public function canAccess(?object $user, ?string $tenantKey = null): bool
    {
        return $this->holds($user, $tenantKey, CrmPermission::View);
    }

    public function canCreate(?object $user, ?string $tenantKey = null): bool
    {
        return $this->holds($user, $tenantKey, CrmPermission::Create);
    }

    public function canEdit(?object $user, ?string $tenantKey = null): bool
    {
        return $this->holds($user, $tenantKey, CrmPermission::Edit);
    }

    public function canDelete(?object $user, ?string $tenantKey = null): bool
    {
        return $this->holds($user, $tenantKey, CrmPermission::Delete);
    }

    public function canImport(?object $user, ?string $tenantKey = null): bool
    {
        return $this->holds($user, $tenantKey, CrmPermission::Import);
    }

    public function canExport(?object $user, ?string $tenantKey = null): bool
    {
        return $this->holds($user, $tenantKey, CrmPermission::Export);
    }

    public function canManageSettings(?object $user, ?string $tenantKey = null): bool
    {
        return $this->holds($user, $tenantKey, CrmPermission::Manage);
    }

    public function canErase(?object $user, ?string $tenantKey = null): bool
    {
        return $this->holds($user, $tenantKey, CrmPermission::Erase);
    }

    public function canViewAmounts(?object $user, ?string $tenantKey = null): bool
    {
        return $this->holds($user, $tenantKey, CrmPermission::AmountsView);
    }

    public function canSendCampaigns(?object $user, ?string $tenantKey = null): bool
    {
        return false;
    }

    /**
     * `RECORD_ACCESS_CHECK`: denies `<action>:<record id>` listed in the context.
     */
    public function onRecord(RecordAccessCheckEvent $event): void
    {
        foreach ($this->context->denied as $denied) {
            if ($denied === $event->getAction() . ':' . $event->getSubjectId() || $denied === $event->getChannel() . ':' . $event->getAction() . ':' . $event->getSubjectId()) {
                $event->deny();
            }
        }
        $this->context->seenChannels[] = $event->getChannel() . '|' . ($event->getTokenId() ?? '');
    }

    /**
     * `RECORD_VISIBILITY`: notes the channel and the token of the read.
     */
    public function onVisibility(\Nowo\CrmKitBundle\Infrastructure\Symfony\Event\RecordVisibilityEvent $event): void
    {
        $this->context->seenChannels[] = 'visibility:' . $event->getChannel() . '|' . ($event->getTokenId() ?? '');
    }

    private function holds(?object $user, ?string $tenant, CrmPermission $permission): bool
    {
        return $user instanceof UserInterface && $tenant !== null && $this->context->holds($tenant, $user->getUserIdentifier(), $permission, null);
    }
}
