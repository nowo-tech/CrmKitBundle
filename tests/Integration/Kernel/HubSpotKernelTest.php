<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Kernel;

use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\CrmKitBundle\Bridge\DoctrineEncrypt\DoctrineEncryptCrmSecretBox;
use Nowo\CrmKitBundle\Bridge\OutboundUrlGuard\GuardedHubSpotRequestOptions;
use Nowo\CrmKitBundle\Domain\Model\HubSpot\ExternalRef;
use Nowo\CrmKitBundle\Domain\Port\CrmSecretBoxInterface;
use Nowo\CrmKitBundle\Domain\Service\HubSpotConnectionServiceInterface;
use Nowo\CrmKitBundle\Infrastructure\Crypto\SodiumCrmSecretBox;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Messenger\RunCrmImport;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Messenger\RunCrmImportHandler;
use Nowo\CrmKitBundle\Tests\Fixtures\TestServices;
use Nowo\DoctrineEncryptBundle\Encryptors\EncryptorRegistryInterface;
use Nowo\OutboundUrlGuardBundle\Guard\OutboundUrlGuard;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;

use function str_repeat;

/**
 * The HubSpot container (0.10.0): the mapping group and services only while enabled, the secret box choices (sodium
 * default, a host box, the DoctrineEncrypt bridge and its refusal), the OutboundUrlGuard pin (auto, required, off),
 * the Messenger handler and its refusal without a bus.
 */
final class HubSpotKernelTest extends KernelTestCase
{
    /** @var array<string, mixed> */
    private const ON = ['import' => ['hubspot' => ['enabled' => true]], 'secrets' => ['keys' => ['k1' => '0123456789abcdef0123456789abcdef'], 'current_key' => 'k1']];

    public function testOffByDefaultAndTheSodiumBoxWhenOn(): void
    {
        $off = $this->bootKernel(TestServices::PORTS)->getContainer();
        self::assertFalse($off->has(HubSpotConnectionServiceInterface::class));
        $on = $this->bootKernel(TestServices::PORTS + self::ON)->getContainer();
        self::assertTrue($on->has(HubSpotConnectionServiceInterface::class));
        self::assertInstanceOf(SodiumCrmSecretBox::class, $this->services($on)->get(CrmSecretBoxInterface::class));
        $doctrine = $on->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $manager = $doctrine->getManager('nowo_crm_kit');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        self::assertTrue($manager->getMetadataFactory()->hasMetadataFor(ExternalRef::class) || $manager->getClassMetadata(ExternalRef::class)->getTableName() !== '');
    }

    public function testTheDoctrineEncryptBridgeAndAHostBox(): void
    {
        $bridge = $this->bootKernel(TestServices::PORTS + ['import' => ['hubspot' => ['enabled' => true]], 'secrets' => ['box' => DoctrineEncryptCrmSecretBox::class]], $this->host(static function (ServicesConfigurator $services): void {
            $services->set(EncryptorRegistryInterface::class)->synthetic()->public();
        }))->getContainer();
        self::assertTrue($this->services($bridge)->has(DoctrineEncryptCrmSecretBox::class));
        $host = $this->bootKernel(TestServices::PORTS + ['import' => ['hubspot' => ['enabled' => true]], 'secrets' => ['box' => 'test.box']], $this->host(static function (ServicesConfigurator $services): void {
            $services->set('test.box', SodiumCrmSecretBox::class)->args([['k9' => str_repeat('z', 32)], 'k9'])->public();
        }))->getContainer();
        self::assertSame('k9', $this->box($host)->currentKeyId());
    }

    public function testTheDoctrineEncryptBridgeNeedsTheBundle(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('nowo-tech/doctrine-encrypt-bundle is not installed');
        $this->bootKernel(TestServices::PORTS + ['import' => ['hubspot' => ['enabled' => true]], 'secrets' => ['box' => DoctrineEncryptCrmSecretBox::class]]);
    }

    public function testTheOutboundUrlGuardPin(): void
    {
        $guarded = $this->bootKernel(TestServices::PORTS + self::ON, $this->host(static function (ServicesConfigurator $services): void {
            $services->set(OutboundUrlGuard::class)->synthetic()->public();
        }))->getContainer();
        self::assertTrue($this->services($guarded)->has(GuardedHubSpotRequestOptions::class));
        $off = $this->bootKernel(TestServices::PORTS + ['import' => ['hubspot' => ['enabled' => true, 'outbound_url_guard' => 'off']]] + self::ON, $this->host(static function (ServicesConfigurator $services): void {
            $services->set(OutboundUrlGuard::class)->synthetic()->public();
        }))->getContainer();
        self::assertFalse($this->services($off)->has(GuardedHubSpotRequestOptions::class));
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('outbound_url_guard is "required"');
        $this->bootKernel(TestServices::PORTS + ['import' => ['hubspot' => ['enabled' => true, 'outbound_url_guard' => 'required']]] + self::ON);
    }

    public function testTheMessengerHandler(): void
    {
        $container = $this->bootKernel(TestServices::PORTS + ['import' => ['messenger' => ['enabled' => true]], 'actor_email_lookup' => 'test.ports'], $this->host(static function (ServicesConfigurator $services, ContainerConfigurator $container): void {
            $container->extension('framework', ['messenger' => ['transports' => ['memory' => 'in-memory://'], 'routing' => [RunCrmImport::class => 'memory']]]);
        }))->getContainer();
        self::assertInstanceOf(RunCrmImportHandler::class, $this->services($container)->get(RunCrmImportHandler::class));
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('import.messenger.enabled needs symfony/messenger');
        $this->bootKernel(TestServices::PORTS + ['import' => ['messenger' => ['enabled' => true]]]);
    }

    private function services(ContainerInterface $container): ContainerInterface
    {
        $services = $container->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $services);

        return $services;
    }

    private function box(ContainerInterface $container): CrmSecretBoxInterface
    {
        $box = $this->services($container)->get(CrmSecretBoxInterface::class);
        self::assertInstanceOf(CrmSecretBoxInterface::class, $box);

        return $box;
    }

    /**
     * @param Closure(ServicesConfigurator, ContainerConfigurator): void $extra
     */
    private function host(Closure $extra): Closure
    {
        $standard = Host::standard()->configure();

        return static function (ContainerConfigurator $container, string $url) use ($standard, $extra): void {
            $standard($container, $url);
            $extra($container->services(), $container);
        };
    }
}
