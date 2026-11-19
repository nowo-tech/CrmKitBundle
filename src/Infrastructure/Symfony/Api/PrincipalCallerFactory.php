<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Api;

use Nowo\CrmKitBundle\Api\CrmApiPrincipal;
use Nowo\CrmKitBundle\Api\Operation\CrmApiUnitHooks;
use Nowo\CrmKitBundle\Api\Port\CrmApiCallerFactoryInterface;
use Nowo\CrmKitBundle\Application\Access\CrmCallerInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmActorDirectoryInterface;
use Nowo\CrmKitBundle\Domain\Value\ActorProfile;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Port\SecurityActorDirectory;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\CrmActorResolverInterface;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\CrmKitAccessCheckerInterface;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\CrmResourceAccessChecker;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\ScopedRecordVisibility;

/**
 * Builds a {@see PrincipalCaller} per call (0.3.0). With the default actor directory (only the signed-in person, whom
 * an API call does not have) the principal is the one owner it may pick: itself or nobody. Stateless.
 */
final readonly class PrincipalCallerFactory implements CrmApiCallerFactoryInterface
{
    /**
     * Wires the collaborators.
     */
    public function __construct(
        private CrmKitAccessCheckerInterface $areas,
        private CrmResourceAccessChecker $resources,
        private ScopedRecordVisibility $visibility,
        private CrmActorDirectoryInterface $directory,
        private ?CrmActorResolverInterface $actors = null,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function for(CrmApiPrincipal $principal, TenantRef $tenant, ?CrmApiUnitHooks $hooks = null): CrmCallerInterface
    {
        $self = null;
        if ($this->directory instanceof SecurityActorDirectory) {
            $id      = $principal->actor->value;
            $profile = $this->actors?->resolve([$id])[$id] ?? null;
            $self    = $profile instanceof ActorProfile && $profile->ref->value === $id ? $profile : new ActorProfile($principal->actor, $id);
        }

        return new PrincipalCaller($principal, $tenant, $this->areas, $this->resources, $this->visibility, $self, $hooks);
    }
}
