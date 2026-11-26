<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

use DateTimeImmutable;
use InvalidArgumentException;

use function in_array;
use function preg_match;

/**
 * A meeting as an iCalendar event (0.11.0): what the kit's ICS writer writes
 * for the authorised `.ics` download, and what the MailKit invite part (MailKit 2.3, a follow-up) will carry: a stable
 * UID per meeting, a SEQUENCE that moves with every change of its time, place, title or cancellation, the method
 * (`PUBLISH` for a download, `REQUEST` / `CANCEL` for an invite) and the plain-text fields.
 */
final readonly class IcsInvite
{
    /** The methods a writer accepts. */
    public const METHODS = ['PUBLISH', 'REQUEST', 'CANCEL'];

    /**
     * @param string $uid `<id>@<domain>`, printable ASCII without spaces (1–255)
     * @param string|null $url an https or http URL of the record page (null: none)
     *
     * @throws InvalidArgumentException a malformed UID, method, sequence or an end not after the start
     */
    public function __construct(
        public string $uid,
        public int $sequence,
        public string $method,
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
        public string $summary,
        public ?string $location,
        public ?string $url,
        public bool $cancelled,
        public DateTimeImmutable $stamp,
    ) {
        if (preg_match('/\A[\x21-\x7E]{1,255}\z/D', $uid) !== 1 || $sequence < 0 || !in_array($method, self::METHODS, true) || $end <= $start) {
            throw new InvalidArgumentException('Invalid ICS invite.');
        }
        if ($url !== null && preg_match('#\Ahttps?://[\x21-\x7E]+\z#D', $url) !== 1) {
            throw new InvalidArgumentException('An ICS URL is an absolute http(s) URL.');
        }
    }
}
