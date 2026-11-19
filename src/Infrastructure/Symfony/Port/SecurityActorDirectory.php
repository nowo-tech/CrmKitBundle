<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Port;

use Nowo\CrmKitBundle\Domain\Port\CrmActorDirectoryInterface;
use Nowo\CrmKitBundle\Domain\Value\ActorProfile;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\ExactId;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\CrmActorResolverInterface;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\IdentifierActorResolver;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Default actor directory: only the signed-in security user is known (actor id from the actor resolver, named by the
 * resolver or by their identifier) and is the only possible owner. Hosts with people implement the port.
 */
final readonly class SecurityActorDirectory implements CrmActorDirectoryInterface
{
    /**
     * Wires the token storage.
     */
    public function __construct(private ?TokenStorageInterface $tokens = null, private CrmActorResolverInterface $actors = new IdentifierActorResolver())
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
        $id   = $user instanceof UserInterface ? $this->actors->idOf($user) : '';
        if (!$user instanceof UserInterface || !ExactId::isValid($id) || ActorRef::isReserved($id)) {
            return null;
        }
        $profile = $this->actors->resolve([$id])[$id] ?? null;

        // The resolver's profile when it names this exact id, else the identifier as the name (0.1 / 0.2 behaviour).
        return $profile instanceof ActorProfile && $profile->ref->value === $id ? $profile : new ActorProfile(new ActorRef($id), $user->getUserIdentifier());
    }
}
