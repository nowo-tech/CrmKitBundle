<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Port;

use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * Who may do what (contract sections 8.1 and 13). Deny by default.
 */
interface CrmAccessCheckerInterface
{
    /**
     * Whether the current actor holds the permission in the tenant. `$subject` (type, id, owner) narrows the grant to
     * one record; null asks for the tenant-wide grant (lists, imports, settings).
     */
    public function isGranted(CrmPermission $permission, TenantRef $tenant, ?SubjectRef $subject = null): bool;

    /**
     * The person acting, per call; null = nobody signed in (404).
     */
    public function currentActor(): ?ActorRef;
}
