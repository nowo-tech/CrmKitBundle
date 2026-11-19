<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application;

use DateTimeZone;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Port\CrmAccessCheckerInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmFeatureGateInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmRecordVisibilityInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmTenantResolverInterface;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Psr\Clock\ClockInterface;

use function array_filter;
use function array_values;
use function in_array;

/**
 * The gate of every call (contract section 13): tenant (none ⇒ 404), feature gate (off ⇒ 404), actor (none ⇒ 404),
 * then the permission (refused ⇒ 403). Asks the ports on every call; keeps nothing (worker mode).
 */
final readonly class CrmAccess
{
    /**
     * Wires the ports.
     */
    public function __construct(
        private CrmTenantResolverInterface $tenants,
        private CrmFeatureGateInterface $gate,
        private CrmAccessCheckerInterface $checker,
        private ClockInterface $clock,
        private ?CrmRecordVisibilityInterface $visibility = null,
    ) {
    }

    /**
     * The owners whose records the actor may see in lists and related blocks (null: every record of the tenant).
     *
     * @return list<string>|null
     */
    public function visibleOwners(CrmContext $context): ?array
    {
        $owners = $this->visibility?->visibleOwners($context->tenant, $context->actor);

        // Whatever the visibility service (the bundle's or a host's) returns, the anonymised placeholder is never a
        // visible owner (audits VD-1, VD2-7).
        return $owners === null ? null : array_values(array_filter($owners, static fn (string $o): bool => !ActorRef::isReserved($o)));
    }

    /**
     * Whether a record of this owner is visible under {@see self::visibleOwners()}.
     *
     * @param list<string>|null $owners
     */
    public static function visible(?array $owners, ?string $owner): bool
    {
        return $owners === null || $owner === null || in_array($owner, $owners, true);
    }

    /**
     * The call's tenant and actor when the CRM is available, without a permission check.
     *
     * @throws NotAvailableException
     */
    public function base(): CrmContext
    {
        $tenant = $this->tenants->current();
        if (!$tenant instanceof TenantRef || !$this->gate->isEnabled($tenant)) {
            throw new NotAvailableException();
        }
        $actor = $this->checker->currentActor();
        // The anonymised placeholder never acts, whichever checker returned it (audit VD-1).
        if (!$actor instanceof ActorRef || ActorRef::isReserved($actor->value)) {
            throw new NotAvailableException();
        }

        return new CrmContext($tenant, $actor, $this->clock->now()->setTimezone(new DateTimeZone('UTC')));
    }

    /**
     * The context after a tenant-wide permission check.
     *
     * @throws AccessDeniedException|NotAvailableException
     */
    public function context(CrmPermission $permission): CrmContext
    {
        $context = $this->base();
        $this->require($context, $permission);

        return $context;
    }

    /**
     * Checks a permission, optionally on one record.
     *
     * @throws AccessDeniedException
     */
    public function require(CrmContext $context, CrmPermission $permission, ?SubjectRef $subject = null): void
    {
        if (!$this->checker->isGranted($permission, $context->tenant, $subject)) {
            throw new AccessDeniedException();
        }
    }

    /**
     * Whether the permission is held (false when the CRM is not available); for menus and buttons.
     */
    public function can(CrmPermission $permission, ?SubjectRef $subject = null): bool
    {
        try {
            $context = $this->base();
        } catch (NotAvailableException) {
            return false;
        }

        return $this->checker->isGranted($permission, $context->tenant, $subject);
    }
}
