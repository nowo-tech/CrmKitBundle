<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Nowo\CrmKitBundle\Bridge\MailKit\CompanyPlaceholders;
use Nowo\CrmKitBundle\Bridge\MailKit\ContactPlaceholders;
use Nowo\CrmKitBundle\Bridge\MailKit\CrmMailLogTag;
use Nowo\CrmKitBundle\Bridge\MailKit\DealPlaceholders;
use Nowo\CrmKitBundle\Bridge\MailKit\MePlaceholders;
use Nowo\CrmKitBundle\Domain\Enum\EmailTemplateKind;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Service\CrmPersonalDataInterface;
use Nowo\CrmKitBundle\Domain\Service\CrmReaderInterface;
use Nowo\CrmKitBundle\Domain\Service\EmailTemplateInput;
use Nowo\CrmKitBundle\Domain\Service\EmailTemplatesInterface;
use Nowo\CrmKitBundle\Domain\Service\MailComposerInterface;
use Nowo\CrmKitBundle\Domain\Service\MailDraft;
use Nowo\CrmKitBundle\Domain\Service\MergeServiceInterface;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Nowo\CrmKitBundle\Tests\Fixtures\TestMailer;
use Nowo\MailKitBundle\Log\NullSentMailErasure;
use Nowo\MailKitBundle\Log\NullSentMailPersonalDataExporter;
use Nowo\MailKitBundle\Log\SentMailPersonalDataSubject;
use Nowo\MailKitBundle\Log\SentMailRecorder;
use Nowo\MailKitBundle\Log\SentMailTaggedPersonalDataEraserInterface;
use Nowo\MailKitBundle\Log\SentMailTaggedPersonalDataExporterInterface;
use Nowo\MailKitBundle\Mailbox\Html\MailHtmlSanitizer;
use Nowo\MailKitBundle\Mailbox\Html\RemoteImages;
use Nowo\MailKitBundle\Mailer\MailHeaders;
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

use function array_column;
use function array_map;
use function count;
use function sort;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service_locator;

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
        $this->bootWithMailKit();
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
     * Audit ZDI-M1 on MailKit's own recorder and database: a *Write e-mail* logged as MailKit logs it carries the
     * tenant's tag; the contact's export lists it and its erasure removes it, while another tenant's row and the host's
     * untagged row for the same address stay as they were.
     */
    public function testTheContactsSentMailLogIsTheTenantsOnlyOnMailKitsRecorder(): void
    {
        $this->bootWithMailKit(true);
        $db = $this->connection();
        $this->createSentMailLog($db);
        $recorder = $this->kernel->getContainer()->get(SentMailRecorder::class);
        self::assertInstanceOf(SentMailRecorder::class, $recorder);

        $this->admin();
        $ada = $this->contact('Ada', 'Lovelace', 'ada@example.test');
        $dup = $this->contact('Ada', 'L.', 'ada.old@example.test');
        // A mail to the duplicate, then the duplicate merged into Ada: its old address is in Ada's merge trace.
        $this->sendAndLog($recorder, $dup, 'To the old address');
        $this->service(MergeServiceInterface::class)->merge(SubjectType::Contact, $ada, $dup, [], $this->service(CrmReaderInterface::class)->record(SubjectType::Contact, $ada)->version, 1);
        $this->sendAndLog($recorder, $ada, 'Your quote');
        $globex = (new Email())->from('app@example.test')->to('ada@example.test')->subject('Other tenant')->text('t');
        MailHeaders::tag($globex, CrmMailLogTag::of(new TenantRef('globex')));
        $recorder->sent(null, $globex, 'smtp', null, null);
        $recorder->sent(null, (new Email())->from('app@example.test')->to('ADA@example.test')->subject('Host mail')->text('t'), 'smtp', null, null);
        $recorder->sent(null, (new Email())->from('app@example.test')->to('ada.old@example.test')->subject('Host to old')->text('t'), 'smtp', null, null);

        $personalData = $this->service(CrmPersonalDataInterface::class);
        $sent         = $personalData->export($ada)['sent_mail'] ?? null;
        self::assertIsArray($sent);
        $subjects = array_column($sent, 'subject');
        sort($subjects);
        self::assertSame(['To the old address', 'Your quote'], $subjects);

        $personalData->erase($ada);
        self::assertSame(['Host mail', 'Host to old', 'Other tenant'], $db->fetchFirstColumn('SELECT subject FROM mail_kit_sent_mail ORDER BY subject'));
        self::assertSame([], $recorder->exportTagged(SentMailPersonalDataSubject::fromEmail('ada@example.test'), CrmMailLogTag::of(new TenantRef('acme'))));
        $db->executeStatement('DROP TABLE mail_kit_sent_mail_body');
        $db->executeStatement('DROP TABLE mail_kit_sent_mail');
    }

    /**
     * Audit ZDI-M1: a single-tenant host (`TenantRef::default()`) has one stable tag; erasure works on it and leaves the
     * host's untagged mail.
     */
    public function testTheSingleTenantTagIsTheDefaultTenants(): void
    {
        $this->bootWithMailKit(true);
        $db = $this->connection();
        $this->createSentMailLog($db);
        $recorder = $this->kernel->getContainer()->get(SentMailRecorder::class);
        self::assertInstanceOf(SentMailRecorder::class, $recorder);
        $this->ctx()->tenant = TenantRef::DEFAULT;
        $this->admin();
        $ada = $this->contact('Ada', 'Lovelace', 'ada@example.test');
        $this->sendAndLog($recorder, $ada, 'Single tenant');
        $recorder->sent(null, (new Email())->from('app@example.test')->to('ada@example.test')->subject('Host mail')->text('t'), 'smtp', null, null);
        self::assertSame(CrmMailLogTag::of(TenantRef::default()), $db->fetchOne("SELECT tag FROM mail_kit_sent_mail WHERE subject = 'Single tenant'"));

        $sent = $this->service(CrmPersonalDataInterface::class)->export($ada)['sent_mail'] ?? null;
        self::assertIsArray($sent);
        self::assertSame(['Single tenant'], array_column($sent, 'subject'));
        $this->service(CrmPersonalDataInterface::class)->erase($ada);
        self::assertSame(['Host mail'], $db->fetchFirstColumn('SELECT subject FROM mail_kit_sent_mail'));
        $db->executeStatement('DROP TABLE mail_kit_sent_mail_body');
        $db->executeStatement('DROP TABLE mail_kit_sent_mail');
    }

    /**
     * Sends a *Write e-mail* to the contact and records the sent message as MailKit's mailer decoration does.
     */
    private function sendAndLog(SentMailRecorder $recorder, string $contactId, string $subject): void
    {
        $this->service(MailComposerInterface::class)->send(new MailDraft($contactId, $subject, 'Text', null, MailDraft::newKey()));
        $mailer = $this->kernel->getContainer()->get('test.mailer');
        self::assertInstanceOf(TestMailer::class, $mailer);
        $message = $mailer->sent[count($mailer->sent) - 1] ?? null;
        self::assertInstanceOf(Email::class, $message);
        $recorder->sent(null, $message, 'smtp', null, null);
    }

    /**
     * Boots the CRM on MailKit's own template services, with MailKit's sent-mail log recorder or its no-op ports.
     */
    private function bootWithMailKit(bool $realLog = false): void
    {
        $this->bootWithUiKit(['activities' => ['enabled' => true, 'notifier' => 'test.activities'], 'templates' => ['enabled' => true]], [], static function (ServicesConfigurator $s) use ($realLog): void {
            $s->set('test.mailer', TestMailer::class)->public();
            $s->alias(MailerInterface::class, 'test.mailer')->public();
            $s->set(TemplateStore::class)->args([service('doctrine')])->public();
            $s->alias(TemplatePermissionInterface::class, 'nowo_crm_kit.template_permission');
            // The registry keyed by each provider's placeholderNamespace(), as MailKit's TemplatePlaceholderPass builds it.
            // Not tagged_locator(..., 'placeholderNamespace'): Symfony 8.1 reads that third argument as $exclude.
            $providers = [];
            foreach ([ContactPlaceholders::class, CompanyPlaceholders::class, DealPlaceholders::class, MePlaceholders::class] as $provider) {
                $providers[$provider::placeholderNamespace()] = service($provider);
            }
            $s->set(PlaceholderResolver::class)->args([service_locator($providers), 50, 1_048_576]);
            $s->set('test.mail_kit_sanitizer', MailHtmlSanitizer::class)->args([RemoteImages::HttpsOnly, 1_048_576]);
            $s->set(TemplateLibrary::class)->args([service(TemplateStore::class), service(TemplatePermissionInterface::class), service(PlaceholderResolver::class), service('test.mail_kit_sanitizer'), 65_536, 200, service('event_dispatcher'), null]);
            $s->alias(TemplateLibraryInterface::class, TemplateLibrary::class)->public();
            $s->set(TemplateRenderer::class)->args([service(TemplateLibrary::class), service(PlaceholderResolver::class), service('test.mail_kit_sanitizer'), 1_048_576]);
            $s->alias(TemplateRendererInterface::class, TemplateRenderer::class)->public();
            if ($realLog) {
                // MailKit's own sent-mail log recorder on the test database (audit ZDI-M1).
                $s->set(SentMailRecorder::class)->args([service('doctrine')])->public();
                $s->alias(SentMailTaggedPersonalDataEraserInterface::class, SentMailRecorder::class);
                $s->alias(SentMailTaggedPersonalDataExporterInterface::class, SentMailRecorder::class);
            } else {
                $s->set(NullSentMailErasure::class);
                $s->set(NullSentMailPersonalDataExporter::class);
                $s->alias(SentMailTaggedPersonalDataEraserInterface::class, NullSentMailErasure::class);
                $s->alias(SentMailTaggedPersonalDataExporterInterface::class, NullSentMailPersonalDataExporter::class);
            }
        });
    }

    /**
     * MailKit's two sent-mail log tables (the columns its recorder writes), created with DBAL on any platform.
     */
    private function createSentMailLog(Connection $db): void
    {
        $db->executeStatement('DROP TABLE IF EXISTS mail_kit_sent_mail_body');
        $db->executeStatement('DROP TABLE IF EXISTS mail_kit_sent_mail');
        $schema = new Schema();
        $main   = $schema->createTable('mail_kit_sent_mail');
        $main->addColumn('id', Types::STRING, ['length' => 36]);
        $main->addColumn('status', Types::STRING, ['length' => 16]);
        $main->addColumn('tag', Types::STRING, ['length' => 64, 'notnull' => false]);
        $main->addColumn('subject', Types::STRING, ['length' => 255]);
        $main->addColumn('sender', Types::STRING, ['length' => 255]);
        $main->addColumn('recipients', Types::TEXT);
        $main->addColumn('recipient_index', Types::TEXT);
        $main->addColumn('transport', Types::STRING, ['length' => 32]);
        $main->addColumn('provider_message_id', Types::STRING, ['length' => 255, 'notnull' => false]);
        $main->addColumn('size', Types::INTEGER, ['notnull' => false]);
        $main->addColumn('error', Types::TEXT, ['notnull' => false]);
        $main->addColumn('attempts', Types::INTEGER, ['default' => 0]);
        $main->addColumn('queued_at', Types::DATETIME_IMMUTABLE);
        $main->addColumn('sent_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $main->addColumn('failed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $body = $schema->createTable('mail_kit_sent_mail_body');
        $body->addColumn('id', Types::STRING, ['length' => 36]);
        $body->addColumn('html_body', Types::TEXT, ['notnull' => false]);
        $body->addColumn('text_body', Types::TEXT, ['notnull' => false]);
        foreach ($schema->toSql($db->getDatabasePlatform()) as $sql) {
            $db->executeStatement($sql);
        }
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
