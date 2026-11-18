<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Port;

use Nowo\CrmKitBundle\Domain\Enum\CrmResource;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * Plan limits (contract sections 8.1 and 15.1): the maximum of active records per resource.
 */
interface CrmPlanLimitsInterface
{
    /**
     * The limit; null = unlimited.
     */
    public function limit(TenantRef $tenant, CrmResource $resource): ?int;
}
