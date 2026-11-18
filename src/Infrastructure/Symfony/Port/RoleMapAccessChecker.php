<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Port;

use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Port\CrmAccessCheckerInterface;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\ExactId;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Throwable;

/**
 * Default access checker (contract sections 8.1 and 10): `access.roles` maps each permission to security roles;
 * **every list empty = denied**. A role grants the permission tenant-wide. The actor is the security user's
 * identifier (an identifier that is not a usable id acts as nobody). Without SecurityBundle nothing is granted.
 */
final readonly class RoleMapAccessChecker implements CrmAccessCheckerInterface
{
    /**
     * @param array<string, list<string>> $roles permission value => roles
     */
    public function __construct(
        private array $roles = [],
        private ?AuthorizationCheckerInterface $authorization = null,
        private ?TokenStorageInterface $tokens = null,
    ) {
    }

    /**
     * Whether a mapped role is held.
     */
    public function isGranted(CrmPermission $permission, TenantRef $tenant, ?SubjectRef $subject = null): bool
    {
        if (!$this->authorization instanceof AuthorizationCheckerInterface || !$this->currentActor() instanceof ActorRef) {
            return false;
        }
        foreach ($this->roles[$permission->value] ?? [] as $role) {
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

    /**
     * The security user's identifier.
     */
    public function currentActor(): ?ActorRef
    {
        $user = $this->tokens?->getToken()?->getUser();
        if (!$user instanceof UserInterface || !ExactId::isValid($user->getUserIdentifier())) {
            return null;
        }

        return new ActorRef($user->getUserIdentifier());
    }
}
