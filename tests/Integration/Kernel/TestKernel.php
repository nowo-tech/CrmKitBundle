<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Kernel;

use Closure;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\CrmKitBundle\Domain\Port\CrmAccessCheckerInterface;
use Nowo\CrmKitBundle\NowoCrmKitBundle;
use Nowo\CrmKitBundle\Tests\Fixtures\TestServices;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\TransactionDoctrine\Symfony\NowoTransactionDoctrineBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

use function dirname;
use function getenv;
use function is_string;
use function sys_get_temp_dir;

/**
 * Kernel of the integration tests: FrameworkBundle, DoctrineBundle (ORM) and the bundle. The test-only key
 * `access_checker` of the bundle configuration (the root key was removed in 0.3.0) points the application's
 * `CrmAccessCheckerInterface` at a test service through a compiler pass; null keeps the bundle's default.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $bundleConfig `nowo_crm_kit` configuration
     * @param Closure(ContainerConfigurator, string): void $configure host configuration (Doctrine)
     */
    public function __construct(
        private readonly string $id,
        private readonly array $bundleConfig,
        private readonly Closure $configure,
        private readonly bool $full = true,
        private readonly bool $transactionBundle = true,
    ) {
        parent::__construct('test', false);
    }

    protected function build(ContainerBuilder $container): void
    {
        $checker = $this->bundleConfig['access_checker'] ?? null;
        if (is_string($checker) && $checker !== '') {
            $container->addCompilerPass(new class($checker) implements CompilerPassInterface {
                public function __construct(private readonly string $checker)
                {
                }

                public function process(ContainerBuilder $container): void
                {
                    $container->setAlias(CrmAccessCheckerInterface::class, $this->checker)->setPublic(true);
                }
            });
        }
    }

    /**
     * `NOWO_TEST_DATABASE_URL` (CI matrix: MySQL, MariaDB, PostgreSQL) or a SQLite file.
     */
    public static function defaultDatabaseUrl(): string
    {
        $url = getenv('NOWO_TEST_DATABASE_URL');
        if (is_string($url) && $url !== '') {
            return $url;
        }
        (new Filesystem())->mkdir(self::baseDir());

        return 'sqlite:///' . self::baseDir() . '/functional.sqlite';
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new TwigBundle();
        if ($this->full) {
            yield new SecurityBundle();
            yield new TwigExtraBundle();
            yield new NowoUiKitBundle();
            yield new NowoFormKitBundle();
        }
        if ($this->transactionBundle) {
            yield new NowoTransactionDoctrineBundle();
        }
        yield new NowoCrmKitBundle();
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 3);
    }

    public function getCacheDir(): string
    {
        return self::baseDir() . '/' . $this->id . '/cache';
    }

    public function getLogDir(): string
    {
        return self::baseDir() . '/' . $this->id . '/log';
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', 'nowo_crm_kit_ui');
        $routes->import('.', 'nowo_crm_kit_api');
    }

    public static function baseDir(): string
    {
        return sys_get_temp_dir() . '/nowo-crm-kit-tests';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'test'                  => true,
            'secret'                => 'S0-' . $this->id,
            'http_method_override'  => false,
            'handle_all_throwables' => true,
            'php_errors'            => ['log' => true],
            'cache'                 => ['app' => 'cache.adapter.array'],
            'router'                => ['utf8' => true],
            'translator'            => ['fallbacks' => ['en']],
        ]);
        $container->extension('twig', ['strict_variables' => true]);
        if ($this->full) {
            $container->extension('framework', [
                'csrf_protection' => true,
                'form'            => ['enabled' => true],
                'session'         => ['storage_factory_id' => 'session.storage.factory.mock_file'],
                'assets'          => ['enabled' => true],
            ]);
            $container->extension('security', [
                'providers' => ['memory' => ['memory' => ['users' => TestServices::USERS]]],
                'firewalls' => ['main' => ['lazy' => true, 'provider' => 'memory', 'http_basic' => null]],
            ]);
        }
        ($this->configure)($container, self::defaultDatabaseUrl());
        $bundle = $this->bundleConfig;
        unset($bundle['access_checker']);
        $container->extension('nowo_crm_kit', $bundle);
        $container->services()->set('logger', NullLogger::class);
        TestServices::register($container);
    }
}
