<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use Nowo\CrmKitBundle\Domain\Enum\ConsentSource;
use Nowo\CrmKitBundle\Domain\Enum\EmailTemplateKind;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Event\CrmEvent;
use Nowo\CrmKitBundle\Domain\Event\CrmMailSent;
use Nowo\CrmKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Exception\RateLimitedException;
use Nowo\CrmKitBundle\Domain\Read\MailOutcome;
use Nowo\CrmKitBundle\Domain\Service\ConsentServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\CrmReaderInterface;
use Nowo\CrmKitBundle\Domain\Service\EmailTemplateInput;
use Nowo\CrmKitBundle\Domain\Service\MailDraft;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Event\CrmKitEvents;
use Nowo\MailKitBundle\Mailer\MailHeaders;
use Nowo\MailKitBundle\Template\Value\TemplateKind;
use Symfony\Component\Mime\Address;

use function array_filter;
use function array_values;

/**
 * 0.13.0 (Beacon spec 254, A-2): *Write e-mail* — the checks under the contact's lock (edit, address, the template's
 * consent purpose, rate limit, one send per key), the send outside the transaction, the `email` activity and the event.
 */
final class MailComposerTest extends TemplatesTestCase
{
    public function testASentMailIsLoggedAsAnEmailActivityWithItsEvent(): void
    {
        $this->boot();
        $this->admin();
        $ada      = $this->contact('Ada', 'Lovelace', 'ada@example.test');
        $template = $this->templates()->create(new EmailTemplateInput(EmailTemplateKind::Template, false, 'hello', 'Hello', 'en', 'Hi', 'Text'));
        $key      = MailDraft::newKey();
        $outcome  = $this->composer()->send(new MailDraft($ada, "Your \u{200D}quote", "Dear Ada,\u{202E}\n\nhere it is.", $template, $key));
        self::assertTrue($outcome->logged());
        $mail = $this->mailer()->sent[0] ?? null;
        self::assertNotNull($mail);
        self::assertSame(['ada@example.test'], array_map(static fn (Address $a): string => $a->getAddress(), $mail->getTo()));
        self::assertSame('Ada Lovelace', $mail->getTo()[0]->getName());
        self::assertSame(['ana@example.test'], array_map(static fn (Address $a): string => $a->getAddress(), $mail->getReplyTo()));
        self::assertSame("Your \u{200D}quote", $mail->getSubject());
        self::assertNull($mail->getHtmlBody(), 'plain text only');
        self::assertSame('hello', $mail->getHeaders()->get(MailHeaders::TAG)?->getBodyAsString());
        self::assertSame(['Your quote', "Dear Ada,\n\nhere it is.", 'out'], [$this->text("SELECT title FROM nowo_crm_engagement WHERE kind = 'email'"), $this->text("SELECT body FROM nowo_crm_engagement WHERE kind = 'email'"), $this->text("SELECT direction FROM nowo_crm_engagement WHERE kind = 'email'")]);
        $events = array_values(array_filter($this->recorder()->events, static fn (CrmEvent $e): bool => $e instanceof CrmMailSent));
        self::assertCount(1, $events);
        self::assertSame([$ada, $outcome->engagementId, $template, null], [$events[0]->contactId, $events[0]->engagementId, $events[0]->templateId, $events[0]->purpose]);
        self::assertContains(CrmKitEvents::MAIL_SENT, [CrmMailSent::class]);
        self::assertContains('crm.mail.claimed', $this->recorder()->actions());

        // The same form twice: refused, nothing more is sent.
        $this->expectConflictCode(ConflictException::MAIL_SENT, fn (): MailOutcome => $this->composer()->send(new MailDraft($ada, 'Again', 'Text', null, $key)));
        self::assertCount(1, $this->mailer()->sent);

        // A person the directory has no address for: no Reply-To.
        $this->ctx()->owners['acme'] = [];
        $this->composer()->send(new MailDraft($ada, 'Plain', 'Text', null, MailDraft::newKey()));
        $plain = $this->lastMail();
        self::assertSame([], $plain->getReplyTo());
        // A person whose directory e-mail is not an address: no Reply-To either.
        $this->ctx()->owners['acme'] = ['ana smith' => 'Ana Smith'];
        $this->actAs('ana smith', 'view', 'edit');
        $this->composer()->send(new MailDraft($ada, 'Plain', 'Text', null, MailDraft::newKey()));
        self::assertSame([], $this->lastMail()->getReplyTo());
        $this->actAs('ana');
        self::assertNull($plain->getHeaders()->get(MailHeaders::TAG));
    }

    public function testConsentAndClassificationDecideWhetherATemplateMaySend(): void
    {
        $this->boot();
        $this->admin();
        $ada        = $this->contact('Ada', 'Lovelace', 'ada@example.test');
        $commercial = $this->templates()->create(new EmailTemplateInput(EmailTemplateKind::Template, false, 'offer', 'Offer', 'en', 'Offer', 'Buy', 'marketing_email'));
        $snippet    = $this->templates()->create(new EmailTemplateInput(EmailTemplateKind::Snippet, false, 'sig', 'Sig', 'en', '', '--'));
        $legacy     = $this->mailKit()->seed('acme', null, TemplateKind::Template, 'legacy', 'Old', 'Old');
        $hidden     = $this->mailKit()->seed('acme', 'leo', TemplateKind::Template, 'leos', 'Leo', 'Leo');
        $send       = fn (?string $template): object => $this->composer()->send(new MailDraft($ada, 'Offer', 'Buy', $template, MailDraft::newKey()));
        $this->expectErrors(['template' => 'mail_error.no_consent'], static fn (): object => $send($commercial));
        $consents = $this->service(ConsentServiceInterface::class);
        $consents->record($ada, 'marketing_email', true, ConsentSource::Manual);
        self::assertTrue($send($commercial)->logged());
        $consents->record($ada, 'marketing_email', false, ConsentSource::Manual);
        $this->expectErrors(['template' => 'mail_error.no_consent'], static fn (): object => $send($commercial));
        $this->expectErrors(['template' => 'mail_error.unclassified'], static fn (): object => $send($legacy));
        $this->expectErrors(['template' => 'mail_error.template'], static fn (): object => $send($snippet));
        $this->expectErrors(['template' => 'mail_error.template'], static fn (): object => $send($hidden));
        $this->expectErrors(['template' => 'mail_error.template'], static fn (): object => $send('not-an-id'));
        self::assertTrue($send(null)->logged(), 'a message written by hand is a service message');
        self::assertCount(2, $this->mailer()->sent);
    }

    public function testInputAddressRateLimitAndFailures(): void
    {
        $this->boot(['templates' => ['send_rate_limit' => ['limit' => 3, 'interval' => 60]]]);
        $this->admin();
        $ada    = $this->contact('Ada', 'Lovelace', 'ada@example.test');
        $nobody = $this->contact('No', 'Address');
        $send   = fn (string $contact, string $subject = 'Hi', string $text = 'Text', string $key = ''): object => $this->composer()->send(new MailDraft($contact, $subject, $text, null, $key === '' ? MailDraft::newKey() : $key));
        $this->expectErrors(['_' => 'error.csrf'], static fn (): object => $send($ada, key: 'short'));
        $this->expectErrors(['subject' => 'mail_error.subject', 'text' => 'mail_error.text'], static fn (): object => $send($ada, "a\nb", ''));
        $this->expectErrors(['text' => 'mail_error.text'], static fn (): object => $send($ada, 'Hi', "bell\x07"));
        $this->expectErrors(['to' => 'mail_error.no_address'], static fn (): object => $send($nobody));
        $this->expectThrows(NotAvailableException::class, static fn (): object => $send('nope'));
        $this->expectThrows(NotAvailableException::class, static fn (): object => $send('0190a000-0000-7000-8000-00000000dead'));

        // The transport fails: nothing is logged and the key can be used again.
        $this->mailer()->fail = true;
        $key                  = MailDraft::newKey();
        $this->expectErrors(['_' => 'mail_error.not_sent'], static fn (): object => $send($ada, key: $key));
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_engagement'));
        $this->mailer()->fail = false;
        self::assertTrue($send($ada, key: $key)->logged());

        // The activity cannot be logged (a subject the activity refuses): the mail went out, the outcome says so.
        self::assertFalse($send($ada, "\u{200D}", "\u{202E}")->logged());
        self::assertCount(2, $this->mailer()->sent);

        // Three sends a minute: the fourth waits.
        $this->expectThrows(RateLimitedException::class, static fn (): object => $send($ada));

        // Edit on the contact is needed.
        $this->clock()->modify('+2 minutes');
        $this->ctx()->grants = [];
        $this->actAs('ana', 'view', 'edit');
        $this->ctx()->denied[] = 'edit:' . $ada;
        $this->expectThrows(AccessDeniedException::class, static fn (): object => $send($ada));
        $this->ctx()->grants = [];
        $this->actAs('ana', 'view');
        $this->expectThrows(AccessDeniedException::class, static fn (): object => $send($ada));
        self::assertNotNull($this->service(CrmReaderInterface::class)->record(SubjectType::Contact, $ada));
    }
}
