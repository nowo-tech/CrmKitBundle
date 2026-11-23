<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

use InvalidArgumentException;

use function preg_match;

/**
 * A HubSpot internal name (0.10.0): a property name (`firstname`, `hubspot_owner_id`, a custom `my_field`); 1–100
 * characters of `A-Z a-z 0-9 _ . : -`, anchored with `/D`. Used as a URL query value only after this check.
 */
final readonly class HubSpotKey
{
    /** The pattern (anchored, `/D`). */
    public const PATTERN = '/\A[A-Za-z0-9_.:-]{1,100}\z/D';

    /**
     * @param string $value the internal name
     */
    public function __construct(public string $value)
    {
        if (!self::isValid($value)) {
            throw new InvalidArgumentException('A HubSpot key is 1-100 characters of A-Z, a-z, 0-9, "_", ".", ":" or "-".');
        }
    }

    /**
     * Whether the value is a HubSpot internal name.
     */
    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }
}
