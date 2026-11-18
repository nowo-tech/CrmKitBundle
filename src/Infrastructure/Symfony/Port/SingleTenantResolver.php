<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Port;

use Nowo\CrmKitBundle\Domain\Port\CrmTenantResolverInterface;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * Default tenant resolver: one tenant, `default` (single-tenant hosts).
 */
final readonly class SingleTenantResolver implements CrmTenantResolverInterface
{
    /**
     * The `default` tenant.
     */
    public function current(): TenantRef
    {
        return TenantRef::default();
    }
}
