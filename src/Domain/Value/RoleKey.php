<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

use InvalidArgumentException;

use function preg_match;

/**
 * A party role key, e.g. `customer` or `supplier` (contract section 6.8): a key of the configured catalogue
 * (`roles.catalogue`). Compared byte for byte, never case folded.
 */
final readonly class RoleKey
{
    /** Pattern (anchored; `/D`: no trailing newline). */
    public const PATTERN = '/^[a-z][a-z0-9_]{0,31}$/D';

    /**
     * @param string $value the role key
     */
    public function __construct(public string $value)
    {
        if (!self::isValid($value)) {
            throw new InvalidArgumentException('A role key is 1-32 characters of a-z, 0-9 or "_", starting with a letter.');
        }
    }

    /**
     * Whether a string is a valid key.
     */
    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }
}
