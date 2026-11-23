<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Read\HubSpot;

use Nowo\CrmKitBundle\Domain\Enum\HubSpotObject;
use Nowo\CrmKitBundle\Domain\Value\HubSpotObjectId;

/**
 * A HubSpot list (0.10.0): id, name (shown escaped; the tag name of a created tag), the kind of its members (null:
 * neither companies nor contacts, never offered) and its size.
 */
final readonly class HubSpotList
{
    /**
     * Wires the list.
     */
    public function __construct(public HubSpotObjectId $id, public string $name, public ?HubSpotObject $object, public int $size)
    {
    }
}
