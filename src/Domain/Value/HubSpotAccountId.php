<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

/**
 * A HubSpot account (portal, hub) id (0.10.0).
 */
final readonly class HubSpotAccountId
{
    use HubSpotNumericIdTrait;
}
