<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Service;

use function bin2hex;
use function preg_match;
use function random_bytes;

/**
 * A message a person sends from a contact's page (0.13.0, *Write e-mail*): subject and text as written (after any
 * template or snippet insert), the template it started from (its consent purpose applies) and the form's send key
 * (one send per key: a second submission of the same form is refused).
 */
final readonly class MailDraft
{
    /** A send key: 30 lowercase hex characters. */
    public const KEY_PATTERN = '/\A[0-9a-f]{30}\z/D';

    /**
     * @param string|null $templateId the MailKit template inserted, or null (a message written by hand: a service message)
     */
    public function __construct(
        public string $contactId,
        public string $subject,
        public string $text,
        public ?string $templateId,
        public string $sendKey,
    ) {
    }

    /**
     * A new random send key.
     */
    public static function newKey(): string
    {
        return bin2hex(random_bytes(15));
    }

    /**
     * Whether a value is a send key.
     */
    public static function isKey(string $value): bool
    {
        return preg_match(self::KEY_PATTERN, $value) === 1;
    }
}
