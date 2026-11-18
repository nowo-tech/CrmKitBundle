<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Port;

use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * Whether the CRM is on for a tenant (contract section 8.1; Beacon: organisation feature + plan). Off: every route 404.
 */
interface CrmFeatureGateInterface
{
    /**
     * Whether the CRM is on for the tenant.
     */
    public function isEnabled(TenantRef $tenant): bool;
}
