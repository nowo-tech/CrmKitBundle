<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Default {@see CrmActorResolverInterface}: the actor id is the security user's identifier (the 0.1 / 0.2 behaviour,
 * so no stored id changes), and it names nobody itself (names come from the actor directory). Stateless.
 */
final readonly class IdentifierActorResolver implements CrmActorResolverInterface
{
    /**
     * The user identifier.
     */
    public function idOf(UserInterface $user): string
    {
        return $user->getUserIdentifier();
    }

    /**
     * Nobody: the directory names people.
     */
    public function resolve(array $ids): array
    {
        return [];
    }
}
