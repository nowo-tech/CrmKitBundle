<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

use function checkdate;
use function preg_match;

/**
 * A calendar date without a time (0.3.0): `Y-m-d`, e.g. a deal's expected close.
 */
final readonly class DateImmutable
{
    /**
     * @throws InvalidArgumentException when the value is not a valid `Y-m-d` date
     */
    public function __construct(public string $value)
    {
        if (preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $value, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[1] < 1) {
            throw new InvalidArgumentException('Invalid date.');
        }
    }

    /**
     * The date of an instant, in UTC.
     */
    public static function of(DateTimeImmutable $at): self
    {
        return new self($at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d'));
    }

    /**
     * Midnight UTC of the date.
     */
    public function toDateTime(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->value . 'T00:00:00', new DateTimeZone('UTC'));
    }
}
