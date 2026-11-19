<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Port;

use Nowo\CrmKitBundle\Domain\Port\CrmFeatureGateInterface;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * Default feature gate: `feature.default_enabled` for every tenant.
 */
final readonly class ConfiguredFeatureGate implements CrmFeatureGateInterface
{
    /**
     * Wires the setting.
     */
    public function __construct(private bool $enabled = true)
    {
    }

    /**
     * The configured value.
     */
    public function isEnabled(TenantRef $tenant): bool
    {
        return $this->enabled;
    }
}
