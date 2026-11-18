<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Port;

use Nowo\CrmKitBundle\Domain\Enum\CrmResource;
use Nowo\CrmKitBundle\Domain\Port\CrmPlanLimitsInterface;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * Default plan limits: `limits.<resource>` for every tenant (null = unlimited).
 */
final readonly class ConfiguredPlanLimits implements CrmPlanLimitsInterface
{
    /**
     * @param array<string, int|null> $limits resource => limit
     */
    public function __construct(private array $limits = [])
    {
    }

    /**
     * The configured limit.
     */
    public function limit(TenantRef $tenant, CrmResource $resource): ?int
    {
        return $this->limits[$resource->value] ?? null;
    }
}
