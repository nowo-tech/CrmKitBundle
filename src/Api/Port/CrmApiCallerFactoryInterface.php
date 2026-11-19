<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Port;

use Nowo\CrmKitBundle\Api\CrmApiPrincipal;
use Nowo\CrmKitBundle\Api\Operation\CrmApiUnitHooks;
use Nowo\CrmKitBundle\Application\Access\CrmCallerInterface;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * Builds the caller of one API call (0.3.0): the principal in the requested tenant (no tenant when its reach does
 * not include it), the area checker asked with the principal's user, the record events with its channel and token.
 */
interface CrmApiCallerFactoryInterface
{
    /**
     * The caller of one call; `$hooks` run first and last inside each of its write units.
     */
    public function for(CrmApiPrincipal $principal, TenantRef $tenant, ?CrmApiUnitHooks $hooks = null): CrmCallerInterface;
}
