<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

use InvalidArgumentException;

use function mb_check_encoding;
use function mb_strlen;
use function preg_match;
use function trim;

/**
 * One plain-text message to one contact (0.13.0, *Write e-mail*): who sends it (tenant and person), the recipient's
 * address and name, the subject, the text, the MailKit template key (or null) and the sent-mail log tag of the tenant
 * (`logTag`, audit ZDI-M1: a sender that goes through Symfony Mailer must put it on the message with MailKit's
 * `MailHeaders::tag()`, or the contact's export and erasure do not find the logged row). The kit sends no HTML part: the person wrote or changed the text, and an HTML part would have to be rebuilt from it.
 */
final readonly class CrmOutgoingMail
{
    /** Longest subject, in characters (MailKit's stored subject limit). */
    public const MAX_SUBJECT = 255;

    /** Longest text, in characters. */
    public const MAX_TEXT = 100000;

    /** Characters a subject or a name may not contain: controls, line breaks, bidi overrides, zero-width marks. */
    public const UNSAFE_LINE = '/[\p{Cc}\x{202A}-\x{202E}\x{2066}-\x{2069}\p{Zl}\p{Zp}\x{200B}\x{2060}\x{FEFF}\x{200E}\x{200F}\x{061C}]/u';

    /**
     * @param EmailAddress|null $replyTo the sender's own address (the actor's e-mail, when the host shares it)
     * @param string|null $tag the MailKit template key of the message, or null
     * @param string|null $logTag the tenant's sent-mail log tag (`crm:` + 32 hex characters), or null when the CRM
     *                            does not log through MailKit
     *
     * @throws InvalidArgumentException a subject or a name that is not one safe line, or a text that is empty or too long
     */
    public function __construct(
        public TenantRef $tenant,
        public ActorRef $actor,
        public EmailAddress $to,
        public string $toName,
        public string $subject,
        public string $text,
        public ?EmailAddress $replyTo = null,
        public ?string $tag = null,
        public ?string $logTag = null,
    ) {
        if (!self::isLine($subject, self::MAX_SUBJECT, false)) {
            throw new InvalidArgumentException('A subject is 1 to 255 characters on one line.');
        }
        if (!self::isLine($toName, 200, true)) {
            throw new InvalidArgumentException('A recipient name is at most 200 characters on one line.');
        }
        if (!self::isText($text)) {
            throw new InvalidArgumentException('A text is 1 to 100 000 characters without control characters.');
        }
        if ($tag !== null && preg_match('/\A[a-z0-9][a-z0-9._-]{0,63}\z/D', $tag) !== 1) {
            throw new InvalidArgumentException('A tag is a MailKit template key.');
        }
        if ($logTag !== null && preg_match('/\Acrm:[0-9a-f]{32}\z/D', $logTag) !== 1) {
            throw new InvalidArgumentException('A log tag is crm: and 32 hex characters.');
        }
    }

    /**
     * Whether a value is a text: 1 to 100 000 characters of UTF-8 without control characters other than line breaks
     * and tabs.
     */
    public static function isText(string $text): bool
    {
        return trim($text) !== '' && mb_check_encoding($text, 'UTF-8') && mb_strlen($text, 'UTF-8') <= self::MAX_TEXT && preg_match('/[^\P{Cc}\t\n\r]/u', $text) !== 1;
    }

    /**
     * Whether a value is one safe line of at most `$max` characters.
     */
    public static function isLine(string $value, int $max, bool $empty): bool
    {
        return ($empty || trim($value) !== '')
            && mb_check_encoding($value, 'UTF-8')
            && mb_strlen($value, 'UTF-8') <= $max
            && preg_match(self::UNSAFE_LINE, $value) !== 1;
    }
}
