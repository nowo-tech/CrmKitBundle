<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

use InvalidArgumentException;

use function preg_match;

/**
 * HubSpot's opaque paging token (`paging.next.after`, 0.10.0): 1–256 characters of `A-Z a-z 0-9 _ = -`, anchored with
 * `/D`. Never a URL: `paging.next.link` is ignored, so no host or path from a response is ever followed.
 */
final readonly class HubSpotCursor
{
    /** The pattern (anchored, `/D`). */
    public const PATTERN = '/\A[A-Za-z0-9_=-]{1,256}\z/D';

    /**
     * @param string $value the `after` token
     */
    public function __construct(public string $value)
    {
        if (!self::isValid($value)) {
            throw new InvalidArgumentException('A HubSpot cursor is 1-256 characters of A-Z, a-z, 0-9, "_", "=" or "-".');
        }
    }

    /**
     * Whether the value is a usable `after` token.
     */
    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }
}
