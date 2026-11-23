<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

use InvalidArgumentException;

use function preg_match;
use function sprintf;

/**
 * The rule of HubSpot's numeric ids (0.10.0): 1–20 digits without a leading zero, anchored with `/D` (no trailing
 * newline, no sign, no spaces, no zero-width characters). Compared byte for byte.
 */
trait HubSpotNumericIdTrait
{
    /** The pattern (anchored, `/D`). */
    public const PATTERN = '/\A[1-9][0-9]{0,19}\z/D';

    /**
     * @param string $value the id as HubSpot gives it
     */
    public function __construct(public readonly string $value)
    {
        if (!self::isValid($value)) {
            throw new InvalidArgumentException(sprintf('%s is 1-20 digits without a leading zero.', static::class));
        }
    }

    /**
     * Whether the value is a numeric HubSpot id.
     */
    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }

    /**
     * Byte-exact comparison.
     */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
