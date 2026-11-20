<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api;

use Nowo\CrmKitBundle\Domain\Value\TenantRef;

use function array_values;
use function in_array;

/**
 * Which tenants a principal reaches (0.3.0): the person's whole reach except some tenants, or only the listed ones.
 * An empty or unreadable scope must be `limitedTo([])`. The reach only narrows; access still comes from the checkers.
 */
final readonly class CrmApiReach
{
    /**
     * @param list<string> $tenants
     */
    private function __construct(public bool $everything, public array $tenants)
    {
    }

    /**
     * Everything the person reaches, except these tenants.
     *
     * @param list<string> $exceptTenants
     */
    public static function everything(array $exceptTenants = []): self
    {
        return new self(true, array_values($exceptTenants));
    }

    /**
     * Only these tenants.
     *
     * @param list<string> $tenants
     */
    public static function limitedTo(array $tenants): self
    {
        return new self(false, array_values($tenants));
    }

    /**
     * Whether the tenant is reached (compared exactly).
     */
    public function reaches(TenantRef $tenant): bool
    {
        $listed = in_array($tenant->value, $this->tenants, true);

        return $this->everything ? !$listed : $listed;
    }
}
