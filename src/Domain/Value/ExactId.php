<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

use function preg_match;
use function strlen;

/**
 * The exact-id rule of the opaque host references (REQ-SEC-006 rule 1): 1–128 bytes of valid UTF-8, no control or
 * format characters (ZWSP, BOM, bidi marks), no line or paragraph separators, no whitespace or NBSP at either edge.
 * Never trimmed, truncated or case folded.
 */
final class ExactId
{
    /** Longest id in bytes (the `string(128)` binary column). */
    public const MAX_BYTES = 128;

    /**
     * Whether the value is a usable id of at most `$maxBytes` bytes.
     */
    public static function isValid(string $value, int $maxBytes = self::MAX_BYTES): bool
    {
        return $value !== ''
            && strlen($value) <= $maxBytes
            && preg_match('//u', $value) === 1
            && preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $value) !== 1
            && preg_match('/\A[\s\x{00A0}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]|[\s\x{00A0}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]\z/u', $value) !== 1;
    }
}
