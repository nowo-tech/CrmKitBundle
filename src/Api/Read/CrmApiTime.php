<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Read;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Instants of the API (0.3.0): UTC RFC 3339 with microseconds.
 *
 * @internal
 */
final class CrmApiTime
{
    /**
     * The instant as `Y-m-d\TH:i:s.uP` in UTC, or null.
     */
    public static function format(?DateTimeImmutable $at): ?string
    {
        return $at?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP');
    }
}
