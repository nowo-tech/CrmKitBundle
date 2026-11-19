<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Port;

use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * The tenant of the current call (contract section 8.1). Asked per call, never cached (worker mode).
 */
interface CrmTenantResolverInterface
{
    /**
     * The tenant; null = no tenant: every page and action answers 404.
     */
    public function current(): ?TenantRef;
}
