<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

use function in_array;
use function intdiv;

/**
 * When a meeting takes place (0.11.0): a start, an end after it (at most 7 days later) and the IANA time zone it was
 * planned in (shown to the people who open it; the instants are stored in UTC).
 */
final readonly class MeetingSlot
{
    /** Longest meeting, in seconds (7 days). */
    public const MAX_SECONDS = 604800;

    public DateTimeImmutable $start;

    public DateTimeImmutable $end;

    /**
     * @param string $zone an IANA time zone identifier, e.g. `Europe/Madrid`
     *
     * @throws InvalidArgumentException an end not after the start, longer than 7 days, or an unknown zone
     */
    public function __construct(DateTimeImmutable $start, DateTimeImmutable $end, public string $zone)
    {
        if (!self::isZone($zone)) {
            throw new InvalidArgumentException('Unknown time zone.');
        }
        $utc         = new DateTimeZone('UTC');
        $this->start = $start->setTimezone($utc);
        $this->end   = $end->setTimezone($utc);
        $length      = $this->end->getTimestamp() - $this->start->getTimestamp();
        if ($length <= 0 || $length > self::MAX_SECONDS) {
            throw new InvalidArgumentException('A meeting ends after its start, at most 7 days later.');
        }
    }

    /**
     * Whether a value is one of PHP's canonical IANA time zone identifiers, exactly (offsets, abbreviations, other
     * spellings and backward-compatible aliases are refused).
     */
    public static function isZone(string $zone): bool
    {
        return in_array($zone, DateTimeZone::listIdentifiers(), true);
    }

    /**
     * The length in minutes.
     */
    public function minutes(): int
    {
        return intdiv($this->end->getTimestamp() - $this->start->getTimestamp(), 60);
    }
}
