<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application\Engagement;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\CrmKitBundle\Domain\Value\IcsInvite;

use function implode;
use function mb_strcut;
use function preg_replace;
use function str_replace;
use function strlen;
use function substr;

/**
 * Writes an {@see IcsInvite} as an RFC 5545 calendar (0.11.0): one `VEVENT` in UTC, CRLF line ends, lines folded at
 * 75 octets (never inside a UTF-8 character), text values escaped (`\`, `;`, `,`, line breaks) and stripped of
 * control characters. No attendees, organiser or description: the download carries the meeting's title, time and
 * place only, never the notes or the people's addresses. Stateless.
 */
final class IcsWriter
{
    /** The product identifier. */
    public const PRODID = '-//nowo-tech//CRM Kit//EN';

    /**
     * The calendar text.
     */
    public static function write(IcsInvite $invite): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:' . self::PRODID,
            'CALSCALE:GREGORIAN',
            'METHOD:' . $invite->method,
            'BEGIN:VEVENT',
            'UID:' . $invite->uid,
            'SEQUENCE:' . $invite->sequence,
            'DTSTAMP:' . self::utc($invite->stamp),
            'DTSTART:' . self::utc($invite->start),
            'DTEND:' . self::utc($invite->end),
            'SUMMARY:' . self::text($invite->summary),
            'STATUS:' . ($invite->cancelled ? 'CANCELLED' : 'CONFIRMED'),
        ];
        if ($invite->location !== null) {
            $lines[] = 'LOCATION:' . self::text($invite->location);
        }
        if ($invite->url !== null) {
            $lines[] = 'URL:' . $invite->url;
        }
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';
        $out     = [];
        foreach ($lines as $line) {
            $out[] = self::fold($line);
        }

        return implode("\r\n", $out) . "\r\n";
    }

    /**
     * A UTC date-time (`20300304T091500Z`).
     */
    private static function utc(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    /**
     * An escaped TEXT value without control characters.
     */
    private static function text(string $value): string
    {
        $value = (string) preg_replace('/[\x{0000}-\x{0008}\x{000B}-\x{001F}\x{007F}\p{Cf}\x{2028}\x{2029}]/u', '', $value);

        return str_replace(['\\', ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\;', '\\,', '\\n', '\\n', '\\n'], $value);
    }

    /**
     * Folds a content line at 75 octets (continuation lines start with a space).
     */
    private static function fold(string $line): string
    {
        $parts = [];
        $limit = 75;
        while (strlen($line) > $limit) {
            $chunk   = mb_strcut($line, 0, $limit, 'UTF-8');
            $parts[] = $chunk;
            $line    = substr($line, strlen($chunk));
            $limit   = 74;
        }
        $parts[] = $line;

        return implode("\r\n ", $parts);
    }
}
