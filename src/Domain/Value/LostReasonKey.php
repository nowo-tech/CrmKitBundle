<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

use InvalidArgumentException;

use function preg_match;

/**
 * A key of `pipeline.lost_reasons` (0.3.0): 1–32 characters of a-z, 0-9 or `_`, starting with a letter; labels
 * `lost_reason.<key>`.
 */
final readonly class LostReasonKey
{
    public const PATTERN = '/\A[a-z][a-z0-9_]{0,31}\z/';

    /**
     * @throws InvalidArgumentException when the key is malformed
     */
    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException('Invalid lost reason key.');
        }
    }
}
