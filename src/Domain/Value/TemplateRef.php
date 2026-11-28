<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

use InvalidArgumentException;

/**
 * The id of a stored e-mail template or snippet (0.13.0): MailKit's row id, a canonical lowercase UUID. The CRM keys
 * its own data about a template (the consent purpose) by it.
 */
final readonly class TemplateRef
{
    /**
     * @param string $value a lowercase UUID
     */
    public function __construct(public string $value)
    {
        if (!self::isValid($value)) {
            throw new InvalidArgumentException('A template id is a lowercase UUID.');
        }
    }

    /**
     * Whether the value is a canonical lowercase UUID.
     */
    public static function isValid(string $value): bool
    {
        return RecordId::isValid($value);
    }
}
