<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application\Port;

/**
 * The log of the e-mails *Write e-mail* sent to a contact (0.13.0, only while `templates.enabled`: MailKit's sent-mail
 * log, which records every message sent through Symfony Mailer with the recipient's address and the subject). The
 * contact's erasure erases its rows by exact address (audit ZDI-M1). There is no export here: the log is host-wide (no
 * tenant), so a tenant's export would show other tenants' and the host's own mail to the same address; the host's
 * data-subject export covers it.
 *
 * @internal
 */
interface ContactMailLogInterface
{
    /**
     * Erases the log rows of the addresses (idempotent). Runs inside the caller's unit when both share the connection.
     *
     * @param list<string> $addresses
     */
    public function erase(array $addresses): void;
}
