<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Enum;

/**
 * State of a tenant's HubSpot connection (0.10.0): `active` until HubSpot refuses the token (401) or the sealed token
 * no longer opens (key lost or rotated away); `invalid` asks a manager for a new token.
 */
enum HubSpotConnectionStatus: string
{
    case Active  = 'active';
    case Invalid = 'invalid';
}
