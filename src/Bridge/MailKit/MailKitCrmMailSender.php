<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Bridge\MailKit;

use Nowo\CrmKitBundle\Domain\Port\CrmMailSenderInterface;
use Nowo\CrmKitBundle\Domain\Value\CrmOutgoingMail;
use Nowo\CrmKitBundle\Domain\Value\CrmSentMailRef;
use Nowo\CrmKitBundle\Domain\Value\EmailAddress;
use Nowo\MailKitBundle\Mailer\MailHeaders;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * The default {@see CrmMailSenderInterface} (0.13.0): a plain-text message through Symfony Mailer, which MailKit
 * decorates (From and Reply-To from MailKit's settings when the message has none, the sent-mail log, its tag). The
 * person's own address, when the host shares it, is the Reply-To. Stateless.
 */
final readonly class MailKitCrmMailSender implements CrmMailSenderInterface
{
    /**
     * Wires the mailer.
     */
    public function __construct(private MailerInterface $mailer)
    {
    }

    /**
     * Sends the message (the transport's message id is not known here).
     */
    public function send(CrmOutgoingMail $mail): CrmSentMailRef
    {
        $email = (new Email())
            ->to(new Address($mail->to->value, $mail->toName))
            ->subject($mail->subject)
            ->text($mail->text);
        if ($mail->replyTo instanceof EmailAddress) {
            $email->replyTo(new Address($mail->replyTo->value));
        }
        if ($mail->tag !== null) {
            MailHeaders::tag($email, $mail->tag);
        }
        $this->mailer->send($email);

        return new CrmSentMailRef();
    }
}
