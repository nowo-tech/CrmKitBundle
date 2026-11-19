<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Security;

use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Port\CrmAccessCheckerInterface;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\ExactId;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The default {@see CrmAccessCheckerInterface} (the application's port): the area checker
 * ({@see CrmKitAccessCheckerInterface}, by `security.*_roles` or the host's `security.access_checker`) for the
 * permission, then, for one record, {@see CrmResourceAccessChecker} and the `nowo_crm_kit.record_access_check` event.
 * The actor is the security user's identifier; with `security.allow_unauthenticated` and nobody signed in it is
 * `anonymous` (demos only). Stateless: the token is read per call.
 */
final readonly class SecurityCrmAccessChecker implements CrmAccessCheckerInterface
{
    /** Actor of an unauthenticated demo. */
    public const ANONYMOUS = 'anonymous';

    /**
     * Wires the collaborators.
     */
    public function __construct(
        private CrmKitAccessCheckerInterface $areas,
        private CrmResourceAccessChecker $resources,
        private ?TokenStorageInterface $tokens = null,
        private bool $allowUnauthenticated = false,
        private CrmActorResolverInterface $actors = new IdentifierActorResolver(),
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function isGranted(CrmPermission $permission, TenantRef $tenant, ?SubjectRef $subject = null): bool
    {
        $actor = $this->currentActor();
        if (!$actor instanceof ActorRef) {
            return false;
        }
        $user = $this->user();
        if (!$this->area($permission, $user, $tenant)) {
            return false;
        }

        return !$subject instanceof SubjectRef || $this->resources->isGranted($user, $actor, $tenant, $permission, $subject);
    }

    /**
     * {@inheritDoc}
     */
    public function currentActor(): ?ActorRef
    {
        $user = $this->user();
        if ($user instanceof UserInterface) {
            $id = $this->actors->idOf($user);

            return ExactId::isValid($id) ? new ActorRef($id) : null;
        }

        return $this->allowUnauthenticated ? new ActorRef(self::ANONYMOUS) : null;
    }

    /**
     * The area decision of a permission.
     */
    private function area(CrmPermission $permission, ?object $user, TenantRef $tenant): bool
    {
        $a = $this->areas;
        $t = $tenant->value;
        if ($a instanceof TenantAwareCrmKitAccessCheckerInterface) {
            return match ($permission) {
                CrmPermission::View          => $a->canAccess($user, $t),
                CrmPermission::Create        => $a->canAccess($user, $t) && $a->canCreate($user, $t),
                CrmPermission::Edit          => $a->canAccess($user, $t) && $a->canEdit($user, $t),
                CrmPermission::Delete        => $a->canAccess($user, $t) && $a->canDelete($user, $t),
                CrmPermission::Import        => $a->canAccess($user, $t) && $a->canImport($user, $t),
                CrmPermission::Export        => $a->canAccess($user, $t) && $a->canExport($user, $t),
                CrmPermission::Erase         => $a->canAccess($user, $t) && $a->canErase($user, $t),
                CrmPermission::AmountsView   => $a->canAccess($user, $t) && $a->canViewAmounts($user, $t),
                CrmPermission::CampaignsSend => $a->canAccess($user, $t) && $a->canSendCampaigns($user, $t),
                CrmPermission::Manage        => $a->canAccess($user, $t) && $a->canManageSettings($user, $t),
            };
        }

        return match ($permission) {
            CrmPermission::View          => $a->canAccess($user),
            CrmPermission::Create        => $a->canAccess($user) && $a->canCreate($user),
            CrmPermission::Edit          => $a->canAccess($user) && $a->canEdit($user),
            CrmPermission::Delete        => $a->canAccess($user) && $a->canDelete($user),
            CrmPermission::Import        => $a->canAccess($user) && $a->canImport($user),
            CrmPermission::Export        => $a->canAccess($user) && $a->canExport($user),
            CrmPermission::Erase         => $a->canAccess($user) && $a->canErase($user),
            CrmPermission::AmountsView   => $a->canAccess($user) && $a->canViewAmounts($user),
            CrmPermission::CampaignsSend => $a->canAccess($user) && $a->canSendCampaigns($user),
            CrmPermission::Manage        => $a->canAccess($user) && $a->canManageSettings($user),
        };
    }

    private function user(): ?object
    {
        $user = $this->tokens?->getToken()?->getUser();

        return $user instanceof UserInterface ? $user : null;
    }
}
