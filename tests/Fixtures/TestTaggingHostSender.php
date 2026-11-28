<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Fixtures;

use Nowo\CrmKitBundle\Domain\Port\CrmMailSenderInterface;
use Nowo\CrmKitBundle\Domain\Value\CrmOutgoingMail;
use Nowo\CrmKitBundle\Domain\Value\CrmSentMailRef;
use Nowo\MailKitBundle\Mailer\MailHeaders;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * A host's own `templates.mail_sender` that sends through Symfony Mailer (audit ZDI-M1): it builds its own message and
 * applies the CRM's log tag, as {@see CrmMailSenderInterface} requires.
 */
final readonly class TestTaggingHostSender implements CrmMailSenderInterface
{
    public function __construct(private MailerInterface $mailer)
    {
    }

    public function send(CrmOutgoingMail $mail): CrmSentMailRef
    {
        $email = (new Email())->from('host@example.test')->to($mail->to->value)->subject($mail->subject)->text($mail->text);
        if ($mail->logTag !== null) {
            MailHeaders::tag($email, $mail->logTag);
        }
        $this->mailer->send($email);

        return new CrmSentMailRef('host-mailer-1');
    }
}
