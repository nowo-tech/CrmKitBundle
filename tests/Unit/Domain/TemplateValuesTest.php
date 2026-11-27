<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Unit\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\CrmKitBundle\Domain\Enum\EmailTemplateKind;
use Nowo\CrmKitBundle\Domain\Model\Template\TemplatePurpose;
use Nowo\CrmKitBundle\Domain\Read\EmailTemplateView;
use Nowo\CrmKitBundle\Domain\Read\MailOutcome;
use Nowo\CrmKitBundle\Domain\Service\MailDraft;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\CrmOutgoingMail;
use Nowo\CrmKitBundle\Domain\Value\EmailAddress;
use Nowo\CrmKitBundle\Domain\Value\TemplateRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * 0.13.0: the e-mail template values.
 */
final class TemplateValuesTest extends TestCase
{
    public function testAnOutgoingMailIsOneSafeSubjectLineAndAText(): void
    {
        $mail = $this->mail('Hello', 'Text', 'Ada', 'offer.v2');
        self::assertSame(['Hello', 'Text', 'Ada', 'offer.v2'], [$mail->subject, $mail->text, $mail->toName, $mail->tag]);
        foreach ([["a\nb", 'Text', 'Ada', null], [' ', 'Text', 'Ada', null], [str_repeat('s', 256), 'Text', 'Ada', null], ["x\u{202E}", 'Text', 'Ada', null], ['Hi', ' ', 'Ada', null], ['Hi', "a\x07", 'Ada', null], ['Hi', str_repeat('t', 100001), 'Ada', null], ['Hi', 'Text', "A\nB", null], ['Hi', 'Text', 'Ada', 'Bad Tag']] as [$subject, $text, $name, $tag]) {
            try {
                $this->mail($subject, $text, $name, $tag);
                self::fail('accepted');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertTrue(CrmOutgoingMail::isLine('', 10, true));
        self::assertFalse(CrmOutgoingMail::isLine("\xff", 10, true));
        self::assertTrue(CrmOutgoingMail::isText("line\n\ttab"));
        $tag = 'crm:' . str_repeat('0a', 16);
        self::assertSame($tag, (new CrmOutgoingMail(new TenantRef('acme'), new ActorRef('ana'), new EmailAddress('ada@example.test'), '', 'S', 'T', null, null, $tag))->logTag);
        self::assertNull($this->mail('S', 'T', '', null)->logTag);
        foreach (['crm:XYZ', 'other:' . str_repeat('0', 32), 'crm:' . str_repeat('0', 33)] as $bad) {
            try {
                new CrmOutgoingMail(new TenantRef('acme'), new ActorRef('ana'), new EmailAddress('ada@example.test'), '', 'S', 'T', null, null, $bad);
                self::fail('log tag accepted: ' . $bad);
            } catch (InvalidArgumentException $e) {
                self::assertSame('A log tag is crm: and 32 hex characters.', $e->getMessage());
            }
        }
    }

    public function testRefsKeysAndViews(): void
    {
        self::assertSame('0190a000-0000-7000-8000-00000000beef', (new TemplateRef('0190a000-0000-7000-8000-00000000beef'))->value);
        $this->expectExceptionObject(new InvalidArgumentException('A template id is a lowercase UUID.'));
        try {
            new TemplateRef('NOPE');
        } finally {
            self::assertTrue(MailDraft::isKey(MailDraft::newKey()));
            self::assertFalse(MailDraft::isKey(str_repeat('A', 30)));
            self::assertFalse((new MailOutcome(null))->logged());
            self::assertTrue((new MailOutcome('x'))->logged());
            $view = new EmailTemplateView('id', EmailTemplateKind::Snippet, false, 'k', 'n', 'en', '', '', false, 1, false, null, true, new DateTimeImmutable());
            self::assertTrue($view->sendable());
            $purpose = new TemplatePurpose('id', 'acme', null, 1, new DateTimeImmutable('2026-11-18'));
            $purpose->classify('newsletter', 2, new DateTimeImmutable('2026-11-19'));
            self::assertSame(['newsletter', 2, '2026-11-19'], [$purpose->getPurpose(), $purpose->getVersion(), $purpose->getUpdatedAt()->format('Y-m-d')]);
        }
    }

    private function mail(string $subject, string $text, string $name, ?string $tag): CrmOutgoingMail
    {
        return new CrmOutgoingMail(new TenantRef('acme'), new ActorRef('ana'), new EmailAddress('ada@example.test'), $name, $subject, $text, null, $tag);
    }
}
