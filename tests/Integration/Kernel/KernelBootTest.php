<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Kernel;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\CrmKitBundle\Infrastructure\Symfony\DependencyInjection\Compiler\PrivateEntityManagerPass;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Http\ConflictFallbackListener;
use Nowo\CrmKitBundle\NowoCrmKitBundle;
use Nowo\CrmKitBundle\Tests\Fixtures\TestServices;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnit;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\Yaml\Yaml;

use function dirname;

/**
 * The kernel boots on every host kind, the configuration reaches the container, the bundle keeps to its entity
 * manager, and a wrong setup fails at boot with the fix.
 */
final class KernelBootTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{Host}>
     */
    public static function hosts(): iterable
    {
        yield 'standard host' => [Host::standard()];
        yield 'DDD host' => [Host::ddd()];
        yield 'single entity manager' => [Host::singleEntityManager()];
    }

    #[DataProvider('hosts')]
    public function testTheKernelBootsWithTheDocumentedDefaults(Host $host): void
    {
        $container = $this->bootKernel($host->bundleConfig(), $host->configure())->getContainer();

        self::assertFalse($container->getParameter('nowo_crm_kit.ui.enabled'));
        self::assertSame(['view' => [], 'edit' => [], 'manage' => [], 'amounts_view' => [], 'campaigns_send' => []], $container->getParameter('nowo_crm_kit.access.roles'));
        self::assertSame(['marketing_email', 'newsletter', 'tracking'], $container->getParameter('nowo_crm_kit.gdpr.purposes'));
        self::assertSame(5, $container->getParameter('nowo_crm_kit.import.sync_chunks'));
        self::assertSame($host->kind === Host::DDD ? 'ddd_' : 'nowo_', $container->getParameter('nowo_crm_kit.doctrine.table_prefix'));
        $registry = $container->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $manager = $container->get('nowo_crm_kit.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        self::assertSame($registry->getManager($host->entityManagerName()), $manager);
    }

    public function testTheFlexRecipeDeclaresThePrivateEntityManager(): void
    {
        /** @var array{doctrine: array{orm: array{entity_managers: array<string, array<string, mixed>>}}} $recipe */
        $recipe = Yaml::parseFile(dirname(__DIR__, 3) . '/.symfony/recipe/nowo-tech/crm-kit-bundle/1.0/config/packages/nowo_crm_kit_doctrine.yaml');
        $orm    = $recipe['doctrine']['orm'];
        $kernel = $this->bootKernel([], static function (ContainerConfigurator $container, string $url) use ($orm): void {
            $orm['entity_managers']['default'] = ['connection' => 'default'];
            $container->extension('doctrine', ['dbal' => ['url' => $url], 'orm' => $orm]);
        });
        $registry = $kernel->getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        self::assertSame($registry->getManager('nowo_crm_kit'), $kernel->getContainer()->get('nowo_crm_kit.entity_manager'));
    }

    public function testAMissingPrivateEntityManagerFailsTheBootWithTheFix(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('doctrine.orm.entity_managers.nowo_crm_kit is not declared');
        $this->bootKernel([], static function (ContainerConfigurator $container, string $url): void {
            $container->extension('doctrine', ['dbal' => ['url' => $url], 'orm' => []]);
        });
    }

    public function testWithoutTheTransactionBundleTheFallbackAnswersConflicts(): void
    {
        $container = $this->bootKernel(TestServices::PORTS, null, true, false)->getContainer();
        $services  = $container->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $services);
        self::assertInstanceOf(WriteUnit::class, $services->get('nowo_crm_kit.write_unit'));
        self::assertInstanceOf(ConflictFallbackListener::class, $services->get(ConflictFallbackListener::class));
    }

    public function testLaterPhasesAreRefusedUntilTheirRelease(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('nowo_crm_kit.pipeline arrives with 0.2.0');
        $this->bootKernel(['pipeline' => ['enabled' => true]]);
    }

    public function testWithoutDoctrineNothingIsPrependedAndThePassRefuses(): void
    {
        $builder    = new ContainerBuilder();
        $bundle     = new NowoCrmKitBundle();
        $loader     = new PhpFileLoader($builder, new FileLocator(__DIR__));
        $instanceof = [];
        $bundle->prependExtension(new ContainerConfigurator($builder, $loader, $instanceof, __FILE__, __FILE__), $builder);
        self::assertSame([], $builder->getExtensionConfig('doctrine'));
        $this->expectException(LogicException::class);
        (new PrivateEntityManagerPass())->process($builder);
    }
}
