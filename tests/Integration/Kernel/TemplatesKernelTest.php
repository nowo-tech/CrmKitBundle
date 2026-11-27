<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Kernel;

use Closure;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Nowo\CrmKitBundle\Bridge\MailKit\MailKitCrmMailSender;
use Nowo\CrmKitBundle\Domain\Model\Template\TemplatePurpose;
use Nowo\CrmKitBundle\Domain\Port\CrmMailSenderInterface;
use Nowo\CrmKitBundle\Tests\Fixtures\TestHostSender;
use Nowo\CrmKitBundle\Tests\Fixtures\TestMailer;
use Nowo\CrmKitBundle\Tests\Fixtures\TestMailTemplates;
use Nowo\CrmKitBundle\Tests\Fixtures\TestServices;
use Nowo\MailKitBundle\Template\Service\TemplateLibraryInterface;
use Nowo\MailKitBundle\Template\Service\TemplateRendererInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Mailer\MailerInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/**
 * 0.13.0: `templates.enabled` needs the activities, MailKit's template services and a mail sender; the table is mapped
 * only while it is on.
 */
final class TemplatesKernelTest extends KernelTestCase
{
    private const ON = ['activities' => ['enabled' => true], 'templates' => ['enabled' => true], 'ui' => ['enabled' => true]];

    public function testTheTemplatesNeedTheActivities(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('nowo_crm_kit.templates.enabled needs activities.enabled');
        $this->bootKernel(TestServices::PORTS + ['templates' => ['enabled' => true]]);
    }

    public function testTheTemplatesNeedMailKitsTemplateServices(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('set nowo_mail_kit.templates.enabled: true');
        $this->bootKernel(TestServices::PORTS + self::ON);
    }

    public function testTheDefaultSenderNeedsSymfonyMailer(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('sends with Symfony Mailer (framework.mailer)');
        $this->bootKernel(TestServices::PORTS + self::ON, $this->host(false));
    }

    public function testTheSenderIsMailKitsMailerOrTheHosts(): void
    {
        $container = $this->bootKernel(TestServices::PORTS + self::ON, $this->host(true))->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        self::assertInstanceOf(MailKitCrmMailSender::class, $container->get(CrmMailSenderInterface::class));
        $em = $container->get('nowo_crm_kit.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertSame('nowo_crm_template_purpose', $em->getClassMetadata(TemplatePurpose::class)->getTableName());

        $container = $this->bootKernel(TestServices::PORTS + ['activities' => ['enabled' => true], 'templates' => ['enabled' => true, 'mail_sender' => 'test.sender'], 'ui' => ['enabled' => true]], $this->host(false, true))->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        self::assertInstanceOf(TestHostSender::class, $container->get(CrmMailSenderInterface::class));
    }

    /**
     * The standard host with an in-memory MailKit, and optionally Symfony Mailer and a host sender.
     */
    private function host(bool $mailer, bool $sender = false): Closure
    {
        $base = Host::standard()->configure();

        return static function (ContainerConfigurator $container, string $url) use ($base, $mailer, $sender): void {
            $base($container, $url);
            $s = $container->services();
            $s->set('test.mail_templates', TestMailTemplates::class)->args([service('nowo_crm_kit.template_permission'), service('event_dispatcher'), tagged_iterator('nowo_mail_kit.template_placeholder_provider')])->public();
            $s->alias(TemplateLibraryInterface::class, 'test.mail_templates')->public();
            $s->alias(TemplateRendererInterface::class, 'test.mail_templates')->public();
            if ($mailer) {
                $s->set('test.mailer', TestMailer::class)->public();
                $s->alias(MailerInterface::class, 'test.mailer')->public();
            }
            if ($sender) {
                $s->set('test.sender', TestHostSender::class)->public();
            }
        };
    }
}
