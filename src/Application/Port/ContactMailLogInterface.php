<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application\Port;

use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * The log of the e-mails *Write e-mail* sent to a contact (0.13.0, only while `templates.enabled`: MailKit's sent-mail
 * log, which records every message sent through Symfony Mailer with the recipient's address and the subject). The log
 * is host-wide, so the CRM tags what it sends with the tenant's tag and both calls are limited to that tag (audit
 * ZDI-M1): the contact's erasure and export never touch rows another tenant or the host sent to the same address.
 *
 * @internal
 */
interface ContactMailLogInterface
{
    /**
     * Erases the tenant's log rows of the addresses (idempotent). Runs inside the caller's unit when both share the
     * connection.
     *
     * @param list<string> $addresses
     */
    public function erase(TenantRef $tenant, array $addresses): void;

    /**
     * The tenant's log rows of the addresses (envelope only: status, template tag, subject, dates), oldest first per
     * address, each row once.
     *
     * @param list<string> $addresses
     *
     * @return list<array{id: string, roles: list<'recipient'|'sender'>, status: string, subject: string, queued_at: string|null, sent_at: string|null, failed_at: string|null}>
     */
    public function export(TenantRef $tenant, array $addresses): array;
}
