<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

use InvalidArgumentException;

use function mb_strlen;
use function mb_strtolower;
use function preg_match;

/**
 * A tag name (contract section 5): 1–60 characters, no control characters, no edge whitespace (forms trim before).
 * `key()` is the lowercase name, unique per tenant.
 */
final readonly class TagName
{
    /**
     * @param string $value the name
     */
    public function __construct(public string $value)
    {
        if ($value === '' || preg_match('//u', $value) !== 1 || mb_strlen($value) > 60 || preg_match('/[\p{Cc}\p{Cf}]|\A\s|\s\z/u', $value) === 1) {
            throw new InvalidArgumentException('A tag name is 1-60 characters without control characters or edge spaces.');
        }
    }

    /**
     * The case-insensitive key (unique per tenant).
     */
    public function key(): string
    {
        return mb_strtolower($this->value, 'UTF-8');
    }
}
