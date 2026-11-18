<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Port;

use Nowo\CrmKitBundle\Domain\Port\CrmActorDirectoryInterface;
use Nowo\CrmKitBundle\Domain\Value\ActorProfile;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\ExactId;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Default actor directory: only the signed-in security user is known (named by their identifier) and is the only
 * possible owner. Hosts with people implement the port.
 */
final readonly class SecurityActorDirectory implements CrmActorDirectoryInterface
{
    /**
     * Wires the token storage.
     */
    public function __construct(private ?TokenStorageInterface $tokens = null)
    {
    }

    /**
     * The signed-in user when it is the asked actor.
     */
    public function actor(TenantRef $tenant, ActorRef $actor): ?ActorProfile
    {
        $current = $this->current();

        return $current instanceof ActorProfile && $current->ref->value === $actor->value ? $current : null;
    }

    /**
     * The signed-in user.
     */
    public function owners(TenantRef $tenant): array
    {
        $current = $this->current();

        return $current instanceof ActorProfile ? [$current] : [];
    }

    private function current(): ?ActorProfile
    {
        $user = $this->tokens?->getToken()?->getUser();
        if (!$user instanceof UserInterface || !ExactId::isValid($user->getUserIdentifier())) {
            return null;
        }

        return new ActorProfile(new ActorRef($user->getUserIdentifier()), $user->getUserIdentifier());
    }
}
