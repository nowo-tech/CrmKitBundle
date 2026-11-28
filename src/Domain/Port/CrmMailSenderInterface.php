<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Port;

use Nowo\CrmKitBundle\Domain\Value\CrmOutgoingMail;
use Nowo\CrmKitBundle\Domain\Value\CrmSentMailRef;

/**
 * Sends a message the CRM wrote (0.13.0, `templates.mail_sender`). Default: MailKit's mailer (Symfony Mailer, which
 * MailKit decorates: From and Reply-To from its settings when missing, the sent-mail log, tracking). A host sends
 * through a person's own mailbox (MailKit's delegated mailboxes) by implementing this port. It is called after the
 * CRM's checks (permission, consent, rate limit) and outside the CRM's transactions; an exception means the message
 * was not sent (the CRM logs no activity then).
 *
 * Audit ZDI-M1: a sender that sends through Symfony Mailer (so MailKit's sent-mail log records the message) MUST tag
 * the message with `$mail->logTag` (MailKit's `MailHeaders::tag($email, $mail->logTag)`). The contact's export and
 * erasure read and erase only rows with that tag: an untagged row stays in the log and is the host's to export and
 * erase. A sender that does not go through Symfony Mailer (for example MailKit's delegated mailboxes, which write no
 * sent-mail log row) keeps its own records and ignores the tag.
 */
interface CrmMailSenderInterface
{
    /**
     * Sends the message.
     */
    public function send(CrmOutgoingMail $mail): CrmSentMailRef;
}
