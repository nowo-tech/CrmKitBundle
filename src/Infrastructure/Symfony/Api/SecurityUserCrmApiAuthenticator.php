<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Api;

use InvalidArgumentException;
use Nowo\CrmKitBundle\Api\CrmApiGrant;
use Nowo\CrmKitBundle\Api\CrmApiLevel;
use Nowo\CrmKitBundle\Api\CrmApiPrincipal;
use Nowo\CrmKitBundle\Api\CrmApiReach;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\ExactId;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\CrmActorResolverInterface;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\IdentifierActorResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function hash;

/**
 * Default {@see CrmApiAuthenticatorInterface} for a plain Symfony host (0.3.0): the firewall's user is the principal,
 * with the actor from the actor resolver, the whole reach, `api.http.default_grant`, the token id
 * `session:<sha256 of the user identifier>` and the token name `API`. Stateless: the token is read per request.
 */
final readonly class SecurityUserCrmApiAuthenticator implements CrmApiAuthenticatorInterface
{
    /**
     * @param array{level: string, amounts: bool, consents: bool, destructive: bool} $grant
     */
    public function __construct(
        private ?TokenStorageInterface $tokens,
        private array $grant,
        private CrmActorResolverInterface $actors = new IdentifierActorResolver(),
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function authenticate(Request $request): ?CrmApiPrincipal
    {
        $user = $this->tokens?->getToken()?->getUser();
        if (!$user instanceof UserInterface) {
            return null;
        }
        $id = $this->actors->idOf($user);
        if (!ExactId::isValid($id) || ActorRef::isReserved($id)) {
            return null;
        }
        try {
            return new CrmApiPrincipal(
                $user,
                new ActorRef($id),
                new CrmApiGrant(CrmApiLevel::from($this->grant['level']), $this->grant['amounts'], $this->grant['consents'], $this->grant['destructive']),
                CrmApiReach::everything(),
                'session:' . hash('sha256', $user->getUserIdentifier()),
                'API',
            );
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
