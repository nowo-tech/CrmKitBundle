<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Read\HubSpot;

use Nowo\CrmKitBundle\Domain\Value\HubSpotKey;

/**
 * A HubSpot property of companies or contacts (0.10.0): internal name, label (HubSpot text, shown escaped) and type.
 */
final readonly class HubSpotProperty
{
    /**
     * Wires the property.
     */
    public function __construct(public HubSpotKey $name, public string $label, public string $type)
    {
    }
}
