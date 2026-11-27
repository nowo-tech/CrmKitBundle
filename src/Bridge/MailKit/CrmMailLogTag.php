<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Bridge\MailKit;

use Nowo\CrmKitBundle\Domain\Port\CrmMailSenderInterface;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

use function hash;
use function substr;

/**
 * The MailKit sent-mail log tag of one CRM tenant (0.13.0, audit ZDI-M1): `crm:` and the first 32 hex characters of a
 * SHA-256 of the tenant value. Every *Write e-mail* sent through Symfony Mailer carries it, and the contact's erasure
 * and export ask MailKit only for rows with it, so they never touch rows another tenant or the host sent to the same
 * address. Hashed because a tenant value may be longer than MailKit's 64-character tag or hold any character; stable
 * across releases (changing it would orphan logged rows). Not a secret and not an access control. Also passed to
 * every {@see CrmMailSenderInterface} as `CrmOutgoingMail::$logTag`.
 *
 * @api
 */
final class CrmMailLogTag
{
    /** Prefix of every CRM tag. */
    public const PREFIX = 'crm:';

    /**
     * The tag of the tenant.
     */
    public static function of(TenantRef $tenant): string
    {
        return self::PREFIX . substr(hash('sha256', 'nowo_crm_kit.sent_mail_log|' . $tenant->value), 0, 32);
    }
}
