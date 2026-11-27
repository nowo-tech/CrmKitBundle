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
 */
interface CrmMailSenderInterface
{
    /**
     * Sends the message.
     */
    public function send(CrmOutgoingMail $mail): CrmSentMailRef;
}
