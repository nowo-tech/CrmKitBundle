<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

use InvalidArgumentException;

use function preg_match;

/**
 * The key of a custom field, e.g. `vat_regime` (contract section 6.4).
 */
final readonly class FieldKey
{
    /** Pattern (anchored, `/D`). */
    public const PATTERN = '/\A[a-z][a-z0-9_]{0,39}\z/D';

    /**
     * @param string $value the key
     */
    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException('A field key is 1-40 characters of a-z, 0-9 or "_", starting with a letter.');
        }
    }
}
