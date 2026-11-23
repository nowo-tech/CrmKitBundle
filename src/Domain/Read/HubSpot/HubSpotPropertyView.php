<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Read\HubSpot;

/**
 * A property row of the mapping step (0.10.0): HubSpot's name, label (escaped when shown) and type, the targets it may
 * map to and the one currently chosen (`''`: skip).
 */
final readonly class HubSpotPropertyView
{
    /**
     * @param list<string> $targets
     */
    public function __construct(public string $name, public string $label, public string $type, public array $targets, public string $target)
    {
    }
}
