<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use Closure;
use Nowo\CrmKitBundle\Application\HubSpot\HubSpotRunner;
use Nowo\CrmKitBundle\Application\HubSpot\Port\HubSpotStoreInterface;
use Nowo\CrmKitBundle\Domain\Enum\HubSpotObject;
use Nowo\CrmKitBundle\Domain\Enum\ImportStatus;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Service\HubSpotConnectionServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\HubSpotImportServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\ImportServiceInterface;
use Nowo\CrmKitBundle\Domain\Value\HubSpotImportScope;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Nowo\CrmKitBundle\Infrastructure\Doctrine\DoctrineTransactionRunner;
use Nowo\CrmKitBundle\Tests\Fixtures\HubSpot\FakeHubSpot;
use Nowo\CrmKitBundle\Tests\Fixtures\TestContext;
use Nowo\CrmKitBundle\Tests\Fixtures\TestServices;
use Nowo\CrmKitBundle\Tests\Integration\Kernel\Host;
use Nowo\CrmKitBundle\Tests\Integration\Kernel\TestKernel;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\MockHttpClient;

use function array_replace_recursive;

/**
 * HubSpot races (0.10.0, REQ-RUNTIME-003, two connections on MySQL 8.4, MariaDB 11.4 and PostgreSQL 16): a page that
 * meets the import row locked by another runner commits nothing and settles once afterwards; a stale claim settles
 * nothing; two first connects of a tenant store one connection; two starts store one import. On SQLite the stale-claim
 * case runs on one connection.
 */
final class HubSpotRaceTest extends HubSpotTestCase
{
    public function testAStaleClaimSettlesNothing(): void
    {
        $fake = $this->hubspot();
        $fake->seedContacts(3);
        $this->connect($fake);
        $id = $this->startAndConfirm();
        self::assertSame(HubSpotRunner::LOST, $this->service(HubSpotRunner::class)->step(new TenantRef('acme'), $id, 'someone-else', 'ana', true));
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_contact'));
        self::assertTrue($this->imports()->run($id));
        self::assertSame(3, $this->int('SELECT COUNT(*) FROM nowo_crm_import_row WHERE import_id = ?', [$id]));
    }

    public function testTwoRunnersSettleEachPageOnce(): void
    {
        if (!self::serverDatabase()) {
            $this->addToAssertionCount(1);

            return;
        }
        $fake = $this->hubspot();
        $fake->seedContacts(15);
        $this->connect($fake);
        $id    = $this->startAndConfirm();
        $other = $this->second($fake);
        // This connection holds the import row: the other runner's page waits, times out and commits nothing.
        $this->hold(fn (): mixed => $this->connection()->fetchOne('SELECT id FROM nowo_crm_import WHERE id = ? FOR UPDATE', [$id]), function () use ($other, $id): void {
            $this->expectLockConflict(static fn () => self::svc($other, ImportServiceInterface::class)->run($id, 1));
        });
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_contact'));
        while (!self::svc($other, ImportServiceInterface::class)->run($id, 5)) {
            // the other runner joins the claim and finishes
        }
        self::assertTrue($this->imports()->run($id));
        self::assertSame([15, 15, 15], [$this->int('SELECT COUNT(*) FROM nowo_crm_contact'), $this->int("SELECT COUNT(*) FROM nowo_crm_external_ref WHERE object_type = 'contact'"), $this->int('SELECT COUNT(*) FROM nowo_crm_import_row WHERE import_id = ?', [$id])]);
        self::assertSame(ImportStatus::Done, $this->imports()->preview($id)->status);
    }

    public function testTwoFirstConnectsStoreOneConnection(): void
    {
        if (!self::serverDatabase()) {
            $this->addToAssertionCount(1);

            return;
        }
        $fake  = $this->hubspot();
        $other = $this->second($fake);
        $this->hold(fn (): mixed => $this->connection()->executeStatement("INSERT INTO nowo_crm_hubspot_connection (id, tenant_id, kind, status, account_id, label, token_last4, token_key_id, token_sealed, scopes, scopes_verified, validated_at, created_at, updated_at, version) VALUES ('01890000-0000-7000-8000-0000000000aa', 'acme', 'private_app', 'active', '4242', 'First', 'abcd', 'k1', 'AAAA', '[]', TRUE, '2026-11-18 10:00:00', '2026-11-18 10:00:00', '2026-11-18 10:00:00', 1)"), function () use ($other, $fake): void {
            $this->expectLockConflict(static fn () => self::svc($other, HubSpotConnectionServiceInterface::class)->connect($fake->token, null, null));
        }, true);
        try {
            self::svc($other, HubSpotConnectionServiceInterface::class)->connect($fake->token, null, null);
            self::fail('the first connect won');
        } catch (ConflictException $e) {
            self::assertSame(ConflictException::STALE, $e->errorCode);
        }
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_hubspot_connection'));
    }

    public function testTwoStartsStoreOneImport(): void
    {
        if (!self::serverDatabase()) {
            $this->addToAssertionCount(1);

            return;
        }
        $fake = $this->hubspot();
        $this->connect($fake);
        $other = $this->second($fake);
        $this->hold(fn (): mixed => $this->service(HubSpotStoreInterface::class)->lockConnection(new TenantRef('acme')), function () use ($other): void {
            $this->expectLockConflict(static fn () => self::svc($other, HubSpotImportServiceInterface::class)->start(new HubSpotImportScope([HubSpotObject::Company])));
        });
        $this->hubImports()->start(new HubSpotImportScope([HubSpotObject::Company]));
        try {
            self::svc($other, HubSpotImportServiceInterface::class)->start(new HubSpotImportScope([HubSpotObject::Company]));
            self::fail('one open import per tenant');
        } catch (ConflictException $e) {
            self::assertSame(ConflictException::IMPORT_RUNNING, $e->errorCode);
        }
        self::assertSame(1, $this->int("SELECT COUNT(*) FROM nowo_crm_import WHERE format = 'hubspot'"));
    }

    /**
     * Runs `$during` while this connection holds what `$take` locks (then rolls back, or commits).
     */
    private function hold(Closure $take, Closure $during, bool $commit = false): void
    {
        $runner     = $this->service(DoctrineTransactionRunner::class);
        $connection = $runner->manager()->getConnection();
        $connection->beginTransaction();
        try {
            $take();
            $during();
        } finally {
            $commit ? $connection->commit() : $connection->rollBack();
            $runner->manager()->clear();
        }
    }

    /**
     * A second kernel on its own connection with the same HubSpot account, acting as `leo` with every grant, lock waits
     * bounded to 1 s.
     */
    private function second(FakeHubSpot $fake): TestKernel
    {
        /** @var array<string, mixed> $config */
        $config = array_replace_recursive(TestServices::PORTS, ['ui' => ['enabled' => true], 'import' => ['hubspot' => ['enabled' => true, 'page_size' => 10]], 'secrets' => ['keys' => ['k1' => self::KEY], 'current_key' => 'k1']]);
        $base   = Host::standard()->configure();
        $kernel = $this->bootKernel(Host::standard()->bundleConfig($config), static function (ContainerConfigurator $container, string $url) use ($base): void {
            $base($container, $url);
            $s = $container->services();
            $s->set('test.hubspot', FakeHubSpot::class)->public();
            $s->set('test.hubspot_client', MockHttpClient::class)->decorate('nowo_crm_kit.hubspot.http_client')->args([new Reference('test.hubspot'), 'https://api.hubapi.com']);
        });
        $mine = self::svc($kernel, FakeHubSpot::class, 'test.hubspot');
        foreach (['token', 'account', 'scopes', 'contacts', 'companies', 'owners', 'lists', 'associations'] as $field) {
            $mine->{$field} = $fake->{$field};
        }
        $context         = self::svc($kernel, TestContext::class, 'test.context');
        $context->actor  = 'leo';
        $context->owners = $this->ctx()->owners;
        $context->grant('acme', 'leo', 'view', 'edit', 'manage');
        self::boundLockWaits(self::svc($kernel, DoctrineTransactionRunner::class)->manager()->getConnection(), 1);

        return $kernel;
    }

    private function expectLockConflict(Closure $call): void
    {
        try {
            $call();
            self::fail('no lock conflict');
        } catch (ConcurrencyConflict $e) {
            self::assertFalse($e->committed);
        }
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private static function svc(TestKernel $kernel, string $class, ?string $id = null): object
    {
        $container = $kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        $service = $container->get($id ?? $class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
