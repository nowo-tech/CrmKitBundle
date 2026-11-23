<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

/**
 * The id of a HubSpot object, owner or list (0.10.0).
 */
final readonly class HubSpotObjectId
{
    use HubSpotNumericIdTrait;
}
