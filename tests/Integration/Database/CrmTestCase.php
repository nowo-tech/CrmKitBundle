<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Service\RecordInput;
use Nowo\CrmKitBundle\Domain\Service\RecordServiceInterface;
use Nowo\CrmKitBundle\Infrastructure\Doctrine\DoctrineTransactionRunner;
use Nowo\CrmKitBundle\Tests\Fixtures\TestContext;
use Nowo\CrmKitBundle\Tests\Fixtures\TestRecorder;
use Nowo\CrmKitBundle\Tests\Fixtures\TestServices;
use Nowo\CrmKitBundle\Tests\Integration\Kernel\Host;
use Nowo\CrmKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\CrmKitBundle\Tests\Integration\Kernel\TestKernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function array_replace_recursive;
use function getenv;
use function is_numeric;
use function is_scalar;
use function is_string;
use function str_starts_with;

/**
 * Functional base: a kernel on the test ports (tenant `acme`, actor `ana`), the bundle's schema created on the
 * configured database (SQLite, or the CI matrix through NOWO_TEST_DATABASE_URL), a browser on the kept kernel (no
 * reboot between requests, as a FrankenPHP worker with kernel reset false), and helpers.
 */
abstract class CrmTestCase extends KernelTestCase
{
    protected TestKernel $kernel;

    protected KernelBrowser $client;

    protected function host(): Host
    {
        return Host::standard();
    }

    /**
     * Boots the kernel and creates an empty schema.
     *
     * @param array<string, mixed> $config
     */
    protected function boot(array $config = []): void
    {
        $this->bootWithUiKit($config);
    }

    /**
     * {@see self::boot()} with a `nowo_ui_kit` configuration of the host (e.g. its `icon_set`); empty = UiKit defaults.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $uiKit
     */
    protected function bootWithUiKit(array $config, array $uiKit = []): void
    {
        $host = $this->host();
        /** @var array<string, mixed> $merged */
        $merged    = array_replace_recursive(TestServices::PORTS, ['ui' => ['enabled' => true]], $config);
        $configure = $host->configure();
        if ($uiKit !== []) {
            $base      = $configure;
            $configure = static function (ContainerConfigurator $container, string $url) use ($base, $uiKit): void {
                $base($container, $url);
                $container->extension('nowo_ui_kit', $uiKit);
            };
        }
        $this->kernel = $this->bootKernel($host->bundleConfig($merged), $configure);
        $this->schema();
        $client = $this->kernel->getContainer()->get('test.client');
        self::assertInstanceOf(KernelBrowser::class, $client);
        $client->disableReboot();
        $this->client                = $client;
        $this->ctx()->owners['acme'] = ['ana' => 'Ana', 'leo' => 'Leo'];
    }

    public static function serverDatabase(): bool
    {
        $url = getenv('NOWO_TEST_DATABASE_URL');

        return is_string($url) && $url !== '';
    }

    /**
     * Bounds the lock waits of a session (a test that waits on itself fails fast).
     */
    public static function boundLockWaits(Connection $connection, int $seconds = 10): void
    {
        $platform = $connection->getDatabasePlatform();
        if ($platform instanceof PostgreSQLPlatform) {
            $connection->executeStatement("SET lock_timeout = '" . $seconds . "s'");
        } elseif ($platform instanceof AbstractMySQLPlatform) {
            $connection->executeStatement('SET SESSION innodb_lock_wait_timeout = ' . $seconds);
        }
    }

    protected function schema(): void
    {
        self::boundLockWaits($this->connection());
        $em       = $this->em();
        $metadata = [];
        foreach ($em->getMetadataFactory()->getAllMetadata() as $class) {
            if (str_starts_with($class->getName(), 'Nowo\CrmKitBundle\\') && !$class->isMappedSuperclass) {
                $metadata[] = $class;
            }
        }
        $tool = new SchemaTool($em);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    protected function runner(): DoctrineTransactionRunner
    {
        $runner = $this->kernel->getContainer()->get(DoctrineTransactionRunner::class);
        self::assertInstanceOf(DoctrineTransactionRunner::class, $runner);

        return $runner;
    }

    protected function em(): EntityManagerInterface
    {
        return $this->runner()->manager();
    }

    protected function connection(): Connection
    {
        return $this->em()->getConnection();
    }

    protected function platform(): string
    {
        $platform = $this->connection()->getDatabasePlatform();

        return match (true) {
            $platform instanceof AbstractMySQLPlatform => 'mysql',
            $platform instanceof PostgreSQLPlatform    => 'postgresql',
            default                                    => 'sqlite',
        };
    }

    /**
     * @param list<mixed> $params
     */
    protected function int(string $sql, array $params = []): int
    {
        $value = $this->connection()->fetchOne($sql, $params);

        return is_numeric($value) ? (int) $value : -1;
    }

    /**
     * @param list<mixed> $params
     */
    protected function text(string $sql, array $params = []): string
    {
        $value = $this->connection()->fetchOne($sql, $params);

        return is_scalar($value) ? (string) $value : '';
    }

    protected function ctx(): TestContext
    {
        $context = $this->kernel->getContainer()->get('test.context');
        self::assertInstanceOf(TestContext::class, $context);

        return $context;
    }

    protected function recorder(): TestRecorder
    {
        $recorder = $this->kernel->getContainer()->get('test.recorder');
        self::assertInstanceOf(TestRecorder::class, $recorder);

        return $recorder;
    }

    protected function clock(): MockClock
    {
        $clock = $this->kernel->getContainer()->get('test.clock');
        self::assertInstanceOf(MockClock::class, $clock);

        return $clock;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    protected function service(string $id): object
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        $service = $container->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }

    /**
     * Acts as an actor of `acme` (or the given tenant) with the given grants.
     */
    protected function actAs(string $actor, string ...$grants): void
    {
        $this->ctx()->actor = $actor;
        $this->ctx()->grant($this->ctx()->tenant ?? 'acme', $actor, ...$grants);
    }

    protected function records(): RecordServiceInterface
    {
        return $this->service(RecordServiceInterface::class);
    }

    /**
     * Creates a company as an actor with every grant.
     *
     * @param array<string, string|null> $values
     */
    protected function company(string $name = 'Acme Tools', array $values = []): string
    {
        return $this->records()->create(new RecordInput(SubjectType::Company, ['name' => $name] + $values));
    }

    /**
     * Creates a contact.
     *
     * @param array<string, string|null> $values
     */
    protected function contact(string $first = 'Ada', string $last = 'Lovelace', ?string $email = null, array $values = []): string
    {
        return $this->records()->create(new RecordInput(SubjectType::Contact, ['first_name' => $first, 'last_name' => $last, 'email' => $email] + $values));
    }

    /**
     * Every grant for `ana`.
     */
    protected function admin(): void
    {
        $this->actAs('ana', 'view', 'edit', 'manage');
    }

    /**
     * The action CSRF token (`crm_action[_token]`) of the session, read from a page that shows an action form.
     */
    protected function actionToken(string $page): string
    {
        $token = $this->client->request('GET', $page)->filter('input[name="crm_action[_token]"]')->first()->attr('value');
        self::assertIsString($token);

        return $token;
    }

    protected function httpStatus(string $method, string $path): int
    {
        $this->client->request($method, $path);

        return $this->client->getResponse()->getStatusCode();
    }
}
