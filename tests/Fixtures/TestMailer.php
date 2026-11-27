<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Fixtures;

use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * A mailer that keeps the messages it was given (or fails while `$fail`), for the CRM's *Write e-mail* tests.
 */
final class TestMailer implements MailerInterface
{
    /** @var list<Email> */
    public array $sent = [];

    public bool $fail = false;

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        if ($this->fail) {
            throw new RuntimeException('The transport is down.');
        }
        if ($message instanceof Email) {
            $this->sent[] = $message;
        }
    }
}
