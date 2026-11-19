<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application\Access;

use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Port\CrmAccessCheckerInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmRecordVisibilityInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmTenantResolverInterface;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\AuditOrigin;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * The caller of the CRM's pages and public services: the configured tenant resolver, access checker and record
 * visibility, asked on every call (the 0.2 behaviour). Stateless.
 */
final readonly class SessionCaller implements CrmCallerInterface
{
    /** The channel of the CRM's own pages. */
    public const CHANNEL = 'web';

    /**
     * Wires the ports.
     */
    public function __construct(
        private CrmTenantResolverInterface $tenants,
        private CrmAccessCheckerInterface $checker,
        private ?CrmRecordVisibilityInterface $visibility = null,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function tenant(): ?TenantRef
    {
        return $this->tenants->current();
    }

    /**
     * {@inheritDoc}
     */
    public function actor(): ?ActorRef
    {
        return $this->checker->currentActor();
    }

    /**
     * {@inheritDoc}
     */
    public function isGranted(CrmPermission $permission, TenantRef $tenant, ?SubjectRef $subject = null): bool
    {
        return $this->checker->isGranted($permission, $tenant, $subject);
    }

    /**
     * {@inheritDoc}
     */
    public function visibleOwners(TenantRef $tenant, ActorRef $actor): ?array
    {
        return $this->visibility?->visibleOwners($tenant, $actor);
    }

    /**
     * {@inheritDoc}
     */
    public function origin(): ?AuditOrigin
    {
        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function selfOwners(TenantRef $tenant): ?array
    {
        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function beginUnit(): void
    {
    }

    /**
     * {@inheritDoc}
     */
    public function endUnit(mixed $result): void
    {
    }
}
