<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application\HubSpot;

use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;

/**
 * The translation key of a HubSpot failure (0.10.0): `error.<code>` (`error.hubspot_token_invalid`, …); never
 * HubSpot's own text.
 *
 * @internal
 */
final class HubSpotErrors
{
    /**
     * The input error of a failure on a field.
     */
    public static function input(string $field, HubSpotException $e): InvalidInputException
    {
        return InvalidInputException::field($field, 'error.' . $e->errorCode);
    }
}
