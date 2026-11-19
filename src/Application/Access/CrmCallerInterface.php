<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application\Access;

use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Value\ActorProfile;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\AuditOrigin;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * Who is calling, for ONE call (0.3.0): the tenant, the actor, the permission decisions and the record visibility.
 * {@see SessionCaller} is the CRM's pages and the public services (the 0.2 behaviour); an API principal gets its own
 * caller. Built per call and passed down; never stored in a service (worker mode).
 */
interface CrmCallerInterface
{
    /** The tenant of the call; null answers 404. */
    public function tenant(): ?TenantRef;

    /** The acting person; null answers 404. */
    public function actor(): ?ActorRef;

    /** Whether the permission is held in the tenant, or on one record. */
    public function isGranted(CrmPermission $permission, TenantRef $tenant, ?SubjectRef $subject = null): bool;

    /**
     * The owners whose records the actor may see in lists (null: every record of the tenant), as
     * `CrmRecordVisibilityInterface::visibleOwners()`.
     *
     * @return list<string>|null
     */
    public function visibleOwners(TenantRef $tenant, ActorRef $actor): ?array;

    /** `web`, `api`, `mcp`, …: the audit and the "via" timeline parameter. */
    public function channel(): string;

    /** The token behind the call (null for the CRM's pages). */
    public function origin(): ?AuditOrigin;

    /**
     * The owners the actor may pick when the host's actor directory knows only the signed-in person (the default
     * directory): null keeps the directory's answer.
     *
     * @return list<ActorProfile>|null
     */
    public function selfOwners(TenantRef $tenant): ?array;
}
