<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

use InvalidArgumentException;

use function is_array;
use function mb_check_encoding;
use function mb_strlen;
use function parse_url;
use function preg_match;
use function str_starts_with;
use function strtolower;
use function trim;

/**
 * Where a meeting takes place (0.11.0): a line of text of at most 200 characters (a room, an address) or an `https://`
 * URL (a video call). Shown escaped; a URL is a link only when it is https with a host.
 */
final readonly class MeetingPlace
{
    /** Longest place, in characters. */
    public const MAX = 200;

    public string $value;

    /**
     * @throws InvalidArgumentException empty, too long, with control characters, or a URL that is not https
     */
    public function __construct(string $value)
    {
        $value = trim($value);
        if ($value === '' || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > self::MAX || preg_match('/[\p{Cc}\p{Cf}\x{2028}\x{2029}]/u', $value) === 1) {
            throw new InvalidArgumentException('A place is 1 to 200 printable characters.');
        }
        if (preg_match('~\A[a-z][a-z0-9+.-]*://~i', $value) === 1 && !self::isHttpsUrl($value)) {
            throw new InvalidArgumentException('A place URL must be https.');
        }
        $this->value = $value;
    }

    /**
     * Whether the place is an https URL with a host (shown as a link).
     */
    public function isUrl(): bool
    {
        return self::isHttpsUrl($this->value);
    }

    /**
     * Whether a value is an https URL with a host and no credentials.
     */
    public static function isHttpsUrl(string $value): bool
    {
        if (!str_starts_with(strtolower($value), 'https://') || preg_match('/[\s\\\\]/', $value) === 1) {
            return false;
        }
        $parts = parse_url($value);

        return is_array($parts) && ($parts['host'] ?? '') !== '' && !isset($parts['user']) && !isset($parts['pass']);
    }
}
