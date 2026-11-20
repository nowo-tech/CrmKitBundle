<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Api;

use Nowo\CrmKitBundle\Domain\Port\CrmTenantResolverInterface;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Symfony\Component\HttpFoundation\Request;

/**
 * Default {@see CrmApiTenantResolverInterface}: the configured tenant resolver (the pages' tenant). Stateless.
 */
final readonly class ConfiguredCrmApiTenantResolver implements CrmApiTenantResolverInterface
{
    /**
     * Wires the resolver.
     */
    public function __construct(private CrmTenantResolverInterface $tenants)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function tenant(Request $request): ?TenantRef
    {
        return $this->tenants->current();
    }
}
