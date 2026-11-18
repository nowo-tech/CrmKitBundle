<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Port;

use Nowo\CrmKitBundle\Domain\Value\ActorProfile;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * The host's people (contract section 8.1): names of authors and owners, and who may own records.
 */
interface CrmActorDirectoryInterface
{
    /**
     * The person, or null when unknown in the tenant. Must return exactly the requested ref (checked with ===).
     */
    public function actor(TenantRef $tenant, ActorRef $actor): ?ActorProfile;

    /**
     * People who may own records (owner pickers).
     *
     * @return list<ActorProfile>
     */
    public function owners(TenantRef $tenant): array;
}
