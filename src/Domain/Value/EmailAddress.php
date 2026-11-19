<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

use InvalidArgumentException;

use function mb_strtolower;
use function preg_match;
use function strlen;

/**
 * An e-mail address as typed (contract section 5): at most 254 bytes, one `@` with a local part and a domain with a
 * dot, no whitespace or control characters. `key()` is the lowercase address, unique per tenant.
 */
final readonly class EmailAddress
{
    /**
     * @param string $value the address as typed
     */
    public function __construct(public string $value)
    {
        if (strlen($value) > 254 || preg_match('/\A[^@\s\p{Cc}\p{Cf}]{1,64}@[^@\s\p{Cc}\p{Cf}]+\.[^@\s\p{Cc}\p{Cf}.]+\z/uD', $value) !== 1) {
            throw new InvalidArgumentException('Not an e-mail address.');
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
