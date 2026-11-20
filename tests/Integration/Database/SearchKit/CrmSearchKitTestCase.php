<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database\SearchKit;

use Doctrine\ORM\Tools\SchemaTool;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Service\RecordInput;
use Nowo\CrmKitBundle\Domain\Service\RecordServiceInterface;
use Nowo\CrmKitBundle\Infrastructure\Doctrine\DoctrineTransactionRunner;
use Nowo\CrmKitBundle\Tests\Fixtures\TestContext;
use Nowo\CrmKitBundle\Tests\Fixtures\TestServices;
use Nowo\CrmKitBundle\Tests\Integration\Database\CrmTestCase;
use Nowo\CrmKitBundle\Tests\Integration\Kernel\Host;
use Nowo\CrmKitBundle\Tests\Integration\Kernel\TestKernel;
use Nowo\SearchKitBundle\Model\SearchReference;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Nowo\SearchKitBundle\Test\EntitySearchProviderTestCase;
use Nowo\SearchKitBundle\Test\QueryCounter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function array_replace_recursive;
use function bin2hex;
use function class_alias;
use function class_exists;
use function random_bytes;
use function str_starts_with;

if (!class_exists(EntitySearchProviderTestCase::class)) {
    class_alias(SearchKitAbsentTestCase::class, EntitySearchProviderTestCase::class);
}

/**
 * SearchKit's provider contract (EntitySearchProviderTestCase) against the CRM's SearchKit groups, on the test
 * kernel's database (SQLite, or NOWO_TEST_DATABASE_URL: MySQL, MariaDB, PostgreSQL in CI). A SearchKit tenant is a CRM
 * tenant; a viewer is the actor of the test ports with `view` in its tenant. Seeded rows are ownerless (visible to
 * every viewer); hidden rows are refused by the record decision (`view:<id>` denied), archived rows are in the trash.
 */
abstract class CrmSearchKitTestCase extends EntitySearchProviderTestCase
{
    protected const TENANT_A = 'acme';

    protected const TENANT_B = 'beta';

    protected TestKernel $kernel;

    /**
     * The service id of the definition under test.
     */
    abstract protected function definitionId(): string;

    protected function setUp(): void
    {
        if (!class_exists(QueryCounter::class)) {
            self::markTestSkipped('nowo-tech/search-kit-bundle >= 1.9 is not installed (PHP >= 8.3).');
        }
        $this->bootCrm();
    }

    protected function tearDown(): void
    {
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
        (new Filesystem())->remove(TestKernel::baseDir());
        parent::tearDown();
    }

    /**
     * Boots a kernel on the test ports and creates an empty schema.
     *
     * @param array<string, mixed> $config
     */
    protected function bootCrm(array $config = []): void
    {
        /** @var array<string, mixed> $merged */
        $merged       = array_replace_recursive(TestServices::PORTS, ['ui' => ['enabled' => true], 'pipeline' => ['enabled' => true]], $config);
        $this->kernel = new TestKernel('searchkit_' . bin2hex(random_bytes(6)), Host::standard()->bundleConfig($merged), Host::standard()->configure());
        $this->kernel->boot();
        $manager = $this->runner()->manager();
        CrmTestCase::boundLockWaits($manager->getConnection());
        $metadata = [];
        foreach ($manager->getMetadataFactory()->getAllMetadata() as $class) {
            if (str_starts_with($class->getName(), 'Nowo\CrmKitBundle\\') && !$class->isMappedSuperclass) {
                $metadata[] = $class;
            }
        }
        $tool = new SchemaTool($manager);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    protected function definition(): EntitySearchDefinition
    {
        $definition = $this->container()->get($this->definitionId());
        self::assertInstanceOf(EntitySearchDefinition::class, $definition);

        return $definition;
    }

    protected function queryCounter(): QueryCounter
    {
        $counter = $this->container()->get('test.search_kit_counter');
        self::assertInstanceOf(QueryCounter::class, $counter);

        return $counter;
    }

    protected function tenants(): array
    {
        return [self::TENANT_A, self::TENANT_B];
    }

    protected function actAs(int|string|null $tenant, string $userIdentifier): void
    {
        $ctx         = $this->ctx();
        $ctx->tenant = $tenant === null ? null : (string) $tenant;
        $ctx->actor  = $userIdentifier;
        if ($tenant !== null) {
            $ctx->grant((string) $tenant, $userIdentifier, 'view');
        }
    }

    protected function disableFeature(): void
    {
        $tenant = $this->ctx()->tenant;
        self::assertNotNull($tenant);
        $this->ctx()->enabled[$tenant] = false;
    }

    /**
     * Runs `$work` as a seeder with every grant in the tenant, then restores the viewer.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    protected function asSeeder(int|string $tenant, callable $work): mixed
    {
        $ctx                    = $this->ctx();
        [$oldTenant, $oldActor] = [$ctx->tenant, $ctx->actor];
        $ctx->tenant            = (string) $tenant;
        $ctx->actor             = 'seeder';
        $ctx->grant((string) $tenant, 'seeder', 'view', 'edit', 'manage', 'amounts_view');
        try {
            return $work();
        } finally {
            [$ctx->tenant, $ctx->actor] = [$oldTenant, $oldActor];
        }
    }

    /**
     * Creates an ownerless company or contact of the tenant named `$text`.
     */
    protected function record(int|string $tenant, SubjectType $type, string $text): string
    {
        $values = $type === SubjectType::Company ? ['name' => $text] : ['first_name' => $text, 'last_name' => 'Seed'];

        return $this->asSeeder($tenant, fn (): string => $this->service(RecordServiceInterface::class)->create(new RecordInput($type, $values)));
    }

    /**
     * A recent reference of a route.
     *
     * @param array<string, string> $parameters
     */
    protected function referenceTo(string $route, array $parameters): SearchReference
    {
        $router = $this->container()->get('router');
        self::assertInstanceOf(UrlGeneratorInterface::class, $router);

        return new SearchReference($router->generate($route, $parameters), $this->definition()->group(), $route, $parameters);
    }

    protected function ctx(): TestContext
    {
        $ctx = $this->container()->get('test.context');
        self::assertInstanceOf(TestContext::class, $ctx);

        return $ctx;
    }

    protected function runner(): DoctrineTransactionRunner
    {
        $runner = $this->container()->get(DoctrineTransactionRunner::class);
        self::assertInstanceOf(DoctrineTransactionRunner::class, $runner);

        return $runner;
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
        $service = $this->container()->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }

    protected function container(): ContainerInterface
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }

    /**
     * Executes a statement on the bundle's connection.
     *
     * @param list<mixed> $params
     */
    protected function sql(string $statement, array $params = []): void
    {
        $this->runner()->manager()->getConnection()->executeStatement($statement, $params);
    }
}
