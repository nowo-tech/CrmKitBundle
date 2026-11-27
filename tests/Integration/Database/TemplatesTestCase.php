<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Service\EmailTemplatesInterface;
use Nowo\CrmKitBundle\Domain\Service\MailComposerInterface;
use Nowo\CrmKitBundle\Tests\Fixtures\TestMailer;
use Nowo\CrmKitBundle\Tests\Fixtures\TestMailTemplates;
use Nowo\CrmKitBundle\Tests\Fixtures\TestSentMailLog;
use Nowo\MailKitBundle\Log\SentMailPersonalDataEraserInterface;
use Nowo\MailKitBundle\Template\Service\TemplateLibraryInterface;
use Nowo\MailKitBundle\Template\Service\TemplateRendererInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Throwable;

use function array_replace_recursive;
use function end;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/**
 * 0.13.0 (Beacon spec 254, A-2): a kernel with the activities, the e-mail templates and an in-memory MailKit (library,
 * renderer, the CRM's permission port, the tagged placeholder providers) and mailer.
 */
abstract class TemplatesTestCase extends CrmTestCase
{
    /**
     * @param array<string, mixed> $config
     */
    protected function boot(array $config = []): void
    {
        /** @var array<string, mixed> $merged */
        $merged = array_replace_recursive(['activities' => ['enabled' => true, 'notifier' => 'test.activities'], 'templates' => ['enabled' => true]], $config);
        $this->bootWithUiKit($merged, [], static function (ServicesConfigurator $s): void {
            $s->set('test.mailer', TestMailer::class)->public();
            $s->alias(MailerInterface::class, 'test.mailer')->public();
            $s->set('test.mail_templates', TestMailTemplates::class)->args([service('nowo_crm_kit.template_permission'), service('event_dispatcher'), tagged_iterator('nowo_mail_kit.template_placeholder_provider')])->public();
            $s->alias(TemplateLibraryInterface::class, 'test.mail_templates')->public();
            $s->alias(TemplateRendererInterface::class, 'test.mail_templates')->public();
            $s->set('test.sent_mail_log', TestSentMailLog::class)->public();
            $s->alias(SentMailPersonalDataEraserInterface::class, 'test.sent_mail_log')->public();
        });
    }

    protected function templates(): EmailTemplatesInterface
    {
        return $this->service(EmailTemplatesInterface::class);
    }

    protected function composer(): MailComposerInterface
    {
        return $this->service(MailComposerInterface::class);
    }

    protected function mailKit(): TestMailTemplates
    {
        $service = $this->kernel->getContainer()->get('test.mail_templates');
        self::assertInstanceOf(TestMailTemplates::class, $service);

        return $service;
    }

    protected function sentMailLog(): TestSentMailLog
    {
        $service = $this->kernel->getContainer()->get('test.sent_mail_log');
        self::assertInstanceOf(TestSentMailLog::class, $service);

        return $service;
    }

    protected function mailer(): TestMailer
    {
        $service = $this->kernel->getContainer()->get('test.mailer');
        self::assertInstanceOf(TestMailer::class, $service);

        return $service;
    }

    protected function lastMail(): Email
    {
        $sent = $this->mailer()->sent;
        $last = end($sent);
        self::assertInstanceOf(Email::class, $last);

        return $last;
    }

    /**
     * @param array<string, string> $errors
     * @param callable(): mixed $call
     */
    protected function expectErrors(array $errors, callable $call): void
    {
        try {
            $call();
            self::fail('accepted');
        } catch (InvalidInputException $e) {
            self::assertSame($errors, $e->errors);
        }
    }

    /**
     * @param class-string<Throwable> $class
     * @param callable(): mixed $call
     */
    protected function expectThrows(string $class, callable $call): void
    {
        try {
            $call();
            self::fail('no ' . $class);
        } catch (Throwable $e) {
            self::assertInstanceOf($class, $e, $e->getMessage());
        }
    }

    /**
     * @param callable(): mixed $call
     */
    protected function expectConflictCode(string $code, callable $call): void
    {
        try {
            $call();
            self::fail('no conflict');
        } catch (ConflictException $e) {
            self::assertSame($code, $e->errorCode);
        }
    }
}
