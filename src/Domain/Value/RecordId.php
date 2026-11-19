<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

use InvalidArgumentException;

use function preg_match;

/**
 * Id of a CRM row: a canonical lowercase UUID string (new rows are UUIDv7).
 */
final readonly class RecordId
{
    /** Canonical lowercase form (anchored, `/D`). */
    public const PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D';

    /** Route requirement. */
    public const REQUIREMENT = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    /**
     * @param string $value a lowercase UUID
     */
    public function __construct(public string $value)
    {
        if (!self::isValid($value)) {
            throw new InvalidArgumentException('A record id is a lowercase UUID.');
        }
    }

    /**
     * Whether the value is a canonical lowercase UUID.
     */
    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }

    /**
     * The id, as a string.
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
