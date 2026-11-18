<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

use InvalidArgumentException;

use function preg_match;

/**
 * A consent purpose key, e.g. `marketing_email` (contract section 16.1).
 */
final readonly class ConsentPurpose
{
    /** Pattern (anchored, `/D`). */
    public const PATTERN = '/\A[a-z][a-z0-9_.]{0,63}\z/D';

    /**
     * @param string $value the purpose key
     */
    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException('A consent purpose is 1-64 characters of a-z, 0-9, "_" or ".", starting with a letter.');
        }
    }
}
