<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Fixtures;

use Nowo\CrmKitBundle\Domain\Port\CrmMailSenderInterface;
use Nowo\CrmKitBundle\Domain\Value\CrmOutgoingMail;
use Nowo\CrmKitBundle\Domain\Value\CrmSentMailRef;

/**
 * A host's own mail sender (a person's mailbox), for the `templates.mail_sender` wiring.
 */
final class TestHostSender implements CrmMailSenderInterface
{
    public function send(CrmOutgoingMail $mail): CrmSentMailRef
    {
        return new CrmSentMailRef('host-1');
    }
}
