<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use Nowo\CrmKitBundle\Domain\Enum\EmailTemplateKind;
use Nowo\CrmKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Service\EmailTemplateInput;
use Nowo\CrmKitBundle\Domain\Service\EmailTemplatesInterface;
use Nowo\CrmKitBundle\Domain\Service\MailComposerInterface;
use Nowo\CrmKitBundle\Domain\Service\MailDraft;
use Nowo\CrmKitBundle\Tests\Fixtures\TestMailer;
use Nowo\MailKitBundle\Log\NullSentMailErasure;
use Nowo\MailKitBundle\Log\SentMailPersonalDataEraserInterface;
use Nowo\MailKitBundle\Mailbox\Html\MailHtmlSanitizer;
use Nowo\MailKitBundle\Mailbox\Html\RemoteImages;
use Nowo\MailKitBundle\Template\Engine\PlaceholderResolver;
use Nowo\MailKitBundle\Template\Engine\TemplateLibrary;
use Nowo\MailKitBundle\Template\Engine\TemplateRenderer;
use Nowo\MailKitBundle\Template\Infrastructure\Store\TemplateStore;
use Nowo\MailKitBundle\Template\Port\TemplatePermissionInterface;
use Nowo\MailKitBundle\Template\Service\TemplateLibraryInterface;
use Nowo\MailKitBundle\Template\Service\TemplateRendererInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Throwable;

use function array_map;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_locator;

/**
 * Audit ZDI-L2: the bridge against MailKit's own template library, renderer, store and placeholder resolver (the real
 * `nowo-tech/mail-kit-bundle` from require-dev, wired as its extension wires them, on the CRM's connection), with the
 * CRM's permission port and placeholder providers: create and classify, the permission port, the placeholders through
 * MailKit's resolver, a send, MailKit's version check, and the delete with its `TemplateDeletedEvent` listener.
 */
final class RealMailKitTest extends CrmTestCase
{
    public function testTheBridgeWorksOnMailKitsOwnTemplateServices(): void
    {
        $this->bootWithUiKit(['activities' => ['enabled' => true, 'notifier' => 'test.activities'], 'templates' => ['enabled' => true]], [], static function (ServicesConfigurator $s): void {
            $s->set('test.mailer', TestMailer::class)->public();
            $s->alias(MailerInterface::class, 'test.mailer')->public();
            $s->set(TemplateStore::class)->args([service('doctrine')])->public();
            $s->alias(TemplatePermissionInterface::class, 'nowo_crm_kit.template_permission');
            $s->set(PlaceholderResolver::class)->args([tagged_locator('nowo_mail_kit.template_placeholder_provider', null, 'placeholderNamespace'), 50, 1_048_576]);
            $s->set('test.mail_kit_sanitizer', MailHtmlSanitizer::class)->args([RemoteImages::HttpsOnly, 1_048_576]);
            $s->set(TemplateLibrary::class)->args([service(TemplateStore::class), service(TemplatePermissionInterface::class), service(PlaceholderResolver::class), service('test.mail_kit_sanitizer'), 65_536, 200, service('event_dispatcher'), null]);
            $s->alias(TemplateLibraryInterface::class, TemplateLibrary::class)->public();
            $s->set(TemplateRenderer::class)->args([service(TemplateLibrary::class), service(PlaceholderResolver::class), service('test.mail_kit_sanitizer'), 1_048_576]);
            $s->alias(TemplateRendererInterface::class, TemplateRenderer::class)->public();
            $s->set(NullSentMailErasure::class);
            $s->alias(SentMailPersonalDataEraserInterface::class, NullSentMailErasure::class);
        });
        $store = $this->kernel->getContainer()->get(TemplateStore::class);
        self::assertInstanceOf(TemplateStore::class, $store);
        $store->execute(['DROP TABLE IF EXISTS ' . $store->table()]);
        $store->execute($store->missingTablesSql());

        $this->actAs('ana', 'view', 'edit');
        $templates = $this->service(EmailTemplatesInterface::class);
        $ada       = $this->contact('Ada', 'Lovelace', 'ada@example.test');
        $id        = $templates->create(new EmailTemplateInput(EmailTemplateKind::Template, false, 'welcome', 'Welcome', 'en', 'Hi {{ contact.first_name }}', "Dear {{ contact.full_name }},\n{{ me.name }} {{ contact.unknown }}"));
        self::assertSame(1, $this->int('SELECT version FROM nowo_crm_template_purpose WHERE id = ?', [$id]));
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM ' . $store->table() . ' WHERE id = ? AND tenant = ?', [$id, 'acme']));

        // MailKit's permission port is the CRM's: shared rows need manage.
        $this->expectFailure(AccessDeniedException::class, static fn (): string => $templates->create(new EmailTemplateInput(EmailTemplateKind::Template, true, 'org', 'Org', 'en', 'S', 'T')));

        // MailKit's resolver with the CRM's providers: one pass, unknown placeholders stay as text.
        $text = $templates->compose($id, $ada);
        self::assertSame(['Hi Ada', "Dear Ada Lovelace,\nAna {{ contact.unknown }}", ['contact.unknown']], [$text->subject, $text->text, $text->unresolved]);

        // A send with the classified template.
        $this->service(MailComposerInterface::class)->send(new MailDraft($ada, $text->subject, $text->text, $id, MailDraft::newKey()));
        $mailer = $this->kernel->getContainer()->get('test.mailer');
        self::assertInstanceOf(TestMailer::class, $mailer);
        self::assertSame(['Hi Ada'], array_map(static fn (Email $m): ?string => $m->getSubject(), $mailer->sent));

        // MailKit's version check, and the classification follows the stored version.
        $templates->update($id, new EmailTemplateInput(EmailTemplateKind::Template, false, 'welcome', 'Welcome', 'en', 'Hello', 'Text', 'newsletter'), 1);
        self::assertSame(['newsletter', 2], [$this->text('SELECT purpose FROM nowo_crm_template_purpose WHERE id = ?', [$id]), $this->int('SELECT version FROM nowo_crm_template_purpose WHERE id = ?', [$id])]);
        $this->expectFailure(ConflictException::class, static fn () => $templates->update($id, new EmailTemplateInput(EmailTemplateKind::Template, false, 'welcome', 'Welcome', 'en', 'Hello', 'Text'), 1));
        $this->expectFailure(InvalidInputException::class, static fn (): string => $templates->create(new EmailTemplateInput(EmailTemplateKind::Template, false, 'welcome', 'Again', 'en', 'S', 'T')));

        // The delete: MailKit's transaction and TemplateDeletedEvent remove the CRM's classification.
        $templates->delete($id, 2);
        self::assertSame([0, 0], [$this->int('SELECT COUNT(*) FROM nowo_crm_template_purpose WHERE id = ?', [$id]), $this->int('SELECT COUNT(*) FROM ' . $store->table() . ' WHERE id = ?', [$id])]);
        $store->execute(['DROP TABLE ' . $store->table()]);
    }

    /**
     * @param class-string<Throwable> $class
     * @param callable(): mixed $call
     */
    private function expectFailure(string $class, callable $call): void
    {
        try {
            $call();
            self::fail('no ' . $class);
        } catch (Throwable $e) {
            self::assertInstanceOf($class, $e, $e->getMessage());
        }
    }
}
