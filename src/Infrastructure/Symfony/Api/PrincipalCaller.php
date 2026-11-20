<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Api;

use Nowo\CrmKitBundle\Api\CrmApiCapability;
use Nowo\CrmKitBundle\Api\CrmApiLevel;
use Nowo\CrmKitBundle\Api\CrmApiPrincipal;
use Nowo\CrmKitBundle\Api\Operation\CrmApiUnitHooks;
use Nowo\CrmKitBundle\Application\Access\CrmCallerInterface;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Value\ActorProfile;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\AuditOrigin;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\CrmKitAccessCheckerInterface;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\CrmResourceAccessChecker;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\ScopedRecordVisibility;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\TenantAwareCrmKitAccessCheckerInterface;

/**
 * The caller of one API call (0.3.0, design §4.4): the requested tenant only when the principal's reach includes it;
 * the principal's actor; a permission only when the grant covers it (defence in depth), then the area checker asked
 * with the principal's user and, for one record, {@see CrmResourceAccessChecker} with the principal's visible owners
 * (the manager's see-everything reach only with a manage-level grant, QA-1) and its channel and token in the event.
 * Built per call by {@see PrincipalCallerFactory}; never kept.
 */
final readonly class PrincipalCaller implements CrmCallerInterface
{
    /**
     * @param ActorProfile|null $self the principal as the only owner it may pick (the default directory), or null
     */
    public function __construct(
        private CrmApiPrincipal $principal,
        private TenantRef $tenant,
        private CrmKitAccessCheckerInterface $areas,
        private CrmResourceAccessChecker $resources,
        private ScopedRecordVisibility $visibility,
        private ?ActorProfile $self = null,
        private ?CrmApiUnitHooks $hooks = null,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function tenant(): ?TenantRef
    {
        return $this->principal->reach->reaches($this->tenant) ? $this->tenant : null;
    }

    /**
     * {@inheritDoc}
     */
    public function actor(): ActorRef
    {
        return $this->principal->actor;
    }

    /**
     * {@inheritDoc}
     */
    public function isGranted(CrmPermission $permission, TenantRef $tenant, ?SubjectRef $subject = null): bool
    {
        $capability = CrmApiCapability::forPermission($permission);
        if (!$capability instanceof CrmApiCapability || !$capability->allows($this->principal->grant) || $tenant->value !== $this->tenant->value || !$this->area($permission, $tenant)) {
            return false;
        }

        return !$subject instanceof SubjectRef || $this->resources->decide($this->principal->user, $this->principal->actor, $tenant, $permission, $subject, $this->visibleOwners($tenant, $this->principal->actor), $this->principal->channel, $this->principal->tokenId);
    }

    /**
     * {@inheritDoc}
     */
    public function grantedSubjects(CrmPermission $permission, TenantRef $tenant, array $subjects): array
    {
        $capability = CrmApiCapability::forPermission($permission);
        if ($subjects === [] || !$capability instanceof CrmApiCapability || !$capability->allows($this->principal->grant) || $tenant->value !== $this->tenant->value || !$this->area($permission, $tenant)) {
            return [];
        }
        $owners  = $this->visibleOwners($tenant, $this->principal->actor);
        $granted = [];
        foreach ($subjects as $subject) {
            if ($this->resources->decide($this->principal->user, $this->principal->actor, $tenant, $permission, $subject, $owners, $this->principal->channel, $this->principal->tokenId)) {
                $granted[$subject->key()] = true;
            }
        }

        return $granted;
    }

    /**
     * {@inheritDoc}
     */
    public function visibleOwners(TenantRef $tenant, ActorRef $actor): ?array
    {
        return $this->visibility->visibleOwnersFor($this->principal->user, $tenant, $actor, $this->principal->grant->level === CrmApiLevel::Manage, $this->principal->channel, $this->principal->tokenId);
    }

    /**
     * {@inheritDoc}
     */
    public function hostContext(): ?object
    {
        return $this->principal->hostContext;
    }

    /**
     * {@inheritDoc}
     */
    public function origin(): AuditOrigin
    {
        return $this->principal->origin();
    }

    /**
     * {@inheritDoc}
     */
    public function selfOwners(TenantRef $tenant): ?array
    {
        return $this->self instanceof ActorProfile ? [$this->self] : null;
    }

    /**
     * {@inheritDoc}
     */
    public function beginUnit(): void
    {
        $this->hooks?->begin();
    }

    /**
     * {@inheritDoc}
     */
    public function endUnit(mixed $result): void
    {
        $this->hooks?->end($result);
    }

    /**
     * The area decision of a permission for the principal's user.
     */
    private function area(CrmPermission $permission, TenantRef $tenant): bool
    {
        $a    = $this->areas;
        $user = $this->principal->user;
        $t    = $tenant->value;
        if ($a instanceof TenantAwareCrmKitAccessCheckerInterface) {
            return $a->canAccess($user, $t) && match ($permission) {
                CrmPermission::Create      => $a->canCreate($user, $t),
                CrmPermission::Edit        => $a->canEdit($user, $t),
                CrmPermission::Delete      => $a->canDelete($user, $t),
                CrmPermission::AmountsView => $a->canViewAmounts($user, $t),
                CrmPermission::Manage      => $a->canManageSettings($user, $t),
                default                    => true,
            };
        }

        return $a->canAccess($user) && match ($permission) {
            CrmPermission::Create      => $a->canCreate($user),
            CrmPermission::Edit        => $a->canEdit($user),
            CrmPermission::Delete      => $a->canDelete($user),
            CrmPermission::AmountsView => $a->canViewAmounts($user),
            CrmPermission::Manage      => $a->canManageSettings($user),
            default                    => true,
        };
    }
}
