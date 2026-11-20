<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Input;

use Nowo\CrmKitBundle\Api\CrmApiCode;
use Nowo\CrmKitBundle\Api\CrmApiRefusal;

use function preg_match;
use function str_starts_with;
use function substr;

/**
 * The base version of a write (0.3.0): 1..2147483647. HTTP sends it as `If-Match: "<n>"`, `W/"<n>"` or `<n>`; MCP as
 * `base_version`.
 */
final readonly class RecordVersion
{
    public const MAX = 2147483647;

    /**
     * @throws CrmApiRefusal `INPUT_002` (field `base_version`)
     */
    public function __construct(public int $value)
    {
        if ($value < 1 || $value > self::MAX) {
            throw CrmApiRefusal::malformed('base_version');
        }
    }

    /**
     * The version of an `If-Match` header: null when absent; `*` and anything else malformed are `INPUT_002`.
     */
    public static function fromIfMatch(?string $header): ?self
    {
        if ($header === null || $header === '') {
            return null;
        }
        $weak   = str_starts_with($header, 'W/');
        $value  = $weak ? substr($header, 2) : $header;
        $digits = preg_match('/\A"([1-9][0-9]{0,9})"\z/', $value, $m) === 1 ? $m[1] : (!$weak && preg_match('/\A[1-9][0-9]{0,9}\z/', $value) === 1 ? $value : null);
        if ($digits === null || (int) $digits > self::MAX) {
            throw CrmApiRefusal::malformed('If-Match');
        }

        return new self((int) $digits);
    }

    /**
     * A required base version (none: 428 `VERSION_001`).
     */
    public static function required(?self $version): self
    {
        return $version ?? throw CrmApiRefusal::of(CrmApiCode::VERSION_MISSING);
    }
}
