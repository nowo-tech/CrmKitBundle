<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application\HubSpot;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

use function ctype_digit;
use function html_entity_decode;
use function intdiv;
use function mb_strlen;
use function mb_substr;
use function preg_match;
use function preg_replace;
use function rtrim;
use function strip_tags;
use function strlen;
use function trim;

use const ENT_HTML5;
use const ENT_QUOTES;

/**
 * Turns HubSpot engagement values into what the activities model stores (0.12.0), pure: HTML bodies become plain
 * text (scripts and styles dropped, block ends kept as line breaks, entities decoded, control and format characters
 * removed, at most `$max` characters with an ellipsis), titles one line, times UTC. Every result is shown escaped.
 *
 * @internal
 */
final class HubSpotText
{
    /** The mark of a shortened text. */
    public const ELLIPSIS = '…';

    /**
     * A HubSpot HTML body as plain text of at most `$max` characters; null when nothing is left.
     */
    public static function plain(?string $html, int $max): ?string
    {
        if ($html === null || preg_match('//u', $html) !== 1) {
            return null;
        }
        $text = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#isu', '', $html);
        $text = (string) preg_replace('#<br\s*/?>|</(p|div|li|tr|h[1-6]|blockquote)\s*>#iu', "\n", $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/\r\n?/u', "\n", $text);
        $text = (string) preg_replace('/[^\P{Cc}\n\t]|\p{Cf}/u', '', $text);
        $text = (string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
        $text = (string) preg_replace('/ *\n */u', "\n", $text);
        $text = trim((string) preg_replace('/\n{3,}/u', "\n\n", $text));

        return $text === '' ? null : self::cut($text, $max);
    }

    /**
     * A one-line text (a title) of at most `$max` characters; null when empty.
     */
    public static function line(?string $value, int $max): ?string
    {
        $text = self::plain($value, $max * 4);
        if ($text === null) {
            return null;
        }

        return self::cut(trim((string) preg_replace('/\s+/u', ' ', $text)), $max);
    }

    /**
     * A HubSpot time (ISO 8601 with a zone, or milliseconds since the epoch) in UTC; null when absent, unreadable or
     * outside 1971–2100 (the activities model's range).
     */
    public static function time(?string $value): ?DateTimeImmutable
    {
        $value = trim($value ?? '');
        if ($value !== '' && ctype_digit($value) && strlen($value) <= 15) {
            $time = (new DateTimeImmutable('@' . intdiv((int) $value, 1000)))->setTimezone(new DateTimeZone('UTC'));
        } elseif (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,9})?)?(Z|[+-]\d{2}:?\d{2})\z/D', $value) === 1) {
            try {
                $time = (new DateTimeImmutable((string) preg_replace('/(\.\d{6})\d+/', '$1', $value)))->setTimezone(new DateTimeZone('UTC'));
            } catch (Exception) {
                return null;
            }
        } else {
            return null;
        }
        $year = (int) $time->format('Y');

        return $year < 1971 || $year > 2100 ? null : $time;
    }

    /**
     * Whole minutes of a duration in milliseconds (rounded up, at most `$max`); null when not a number.
     */
    public static function minutes(?string $milliseconds, int $max): ?int
    {
        $value = trim($milliseconds ?? '');
        if ($value === '' || !ctype_digit($value) || strlen($value) > 15) {
            return null;
        }
        $minutes = intdiv((int) $value + 59999, 60000);

        return $minutes > $max ? $max : $minutes;
    }

    private static function cut(string $text, int $max): string
    {
        return mb_strlen($text) <= $max ? $text : rtrim(mb_substr($text, 0, $max - 1)) . self::ELLIPSIS;
    }
}
