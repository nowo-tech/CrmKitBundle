<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

use InvalidArgumentException;

use function sprintf;

/**
 * Validation shared by the opaque host references ({@see ExactId}); two references are equal only when their bytes
 * are (REQ-SEC-006: `usera` is not `UserA`, `bob ` is not `bob`).
 */
trait OpaqueRefTrait
{
    /**
     * @param string $value the host id, exactly as the host gives it
     */
    public function __construct(public readonly string $value)
    {
        if (!ExactId::isValid($value)) {
            throw new InvalidArgumentException(sprintf('%s must be 1-128 bytes of UTF-8 without control, format or edge whitespace characters.', static::class));
        }
    }

    /**
     * Byte-exact comparison.
     */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * The reference, for logs and templates.
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
