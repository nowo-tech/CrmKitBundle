<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database\Api;

use Nowo\CrmKitBundle\Api\CrmApiCode;
use Nowo\CrmKitBundle\Api\CrmApiOperation;
use Nowo\CrmKitBundle\Api\Input\CrmApiCompanyLink;
use Nowo\CrmKitBundle\Api\Input\CrmApiRecordChanges;
use Nowo\CrmKitBundle\Api\Input\CrmApiRecordDraft;
use Nowo\CrmKitBundle\Api\Input\IdempotencyKey;
use Nowo\CrmKitBundle\Api\Input\RecordVersion;
use Nowo\CrmKitBundle\Api\Operation\CrmApiIdempotency;
use Nowo\CrmKitBundle\Api\Operation\CrmApiRecordOperations;
use Nowo\CrmKitBundle\Api\Read\CrmApiItem;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Value\RecordId;
use Nowo\CrmKitBundle\Infrastructure\Doctrine\DoctrineTransactionRunner;
use Nowo\CrmKitBundle\Tests\Fixtures\TestApiState;
use Nowo\CrmKitBundle\Tests\Fixtures\TestContext;
use Nowo\CrmKitBundle\Tests\Fixtures\TestServices;
use Nowo\CrmKitBundle\Tests\Integration\Kernel\Host;
use Nowo\CrmKitBundle\Tests\Integration\Kernel\TestKernel;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Throwable;

use function array_replace_recursive;
use function hash;
use function str_starts_with;

/**
 * Races of the API on two connections (0.3.0, design §17.3; MySQL 8.4, MariaDB 11.4, PostgreSQL 16): a second kernel
 * writes while the first holds a lock or an open claim. It waits, gives up with a lock conflict (nothing committed:
 * CONFLICT_001 with Retry-After and X-Conflict-Code), and once the first commits it replays, refuses a reused key or
 * writes once — never a duplicate, a lost update or a 500.
 */
final class ApiRaceTest extends ApiTestCase
{
    private const CONFIG = [
        'security' => ['access_checker' => 'test.areas'],
        'api'      => ['enabled' => true, 'http' => ['enabled' => true, 'authenticator' => 'test.api_authenticator']],
        'pipeline' => ['enabled' => true],
    ];

    public function testIfMatchAndARecordLockOnTwoConnections(): void
    {
        if (!$this->servers()) {
            return;
        }
        $id    = new RecordId($this->company('Acme'));
        $other = $this->second();
        $ops   = $this->svc($other, CrmApiRecordOperations::class);
        // A lock held by the first connection: the second's update gives up, nothing committed.
        $this->hold('SELECT id FROM nowo_crm_company WHERE id = ? FOR UPDATE', [$id->value], function () use ($ops, $id): void {
            try {
                $ops->updateCompany($this->principal(), $this->acme(), new IdempotencyKey('lock-1'), $id, new RecordVersion(1), new CrmApiRecordChanges(SubjectType::Company, ['city' => 'Madrid']));
                self::fail('no lock conflict');
            } catch (ConcurrencyConflict $e) {
                self::assertFalse($e->committed);
            }
        });
        // The same through the JSON API: 409, CONFLICT_001, Retry-After, X-Conflict-Code.
        $client = $other->getContainer()->get('test.client');
        self::assertInstanceOf(KernelBrowser::class, $client);
        $client->disableReboot();
        $this->svc($other, TestApiState::class, 'test.api_state')->principal = $this->principal();
        $this->hold('SELECT id FROM nowo_crm_company WHERE id = ? FOR UPDATE', [$id->value], static function () use ($client, $id): void {
            $client->request('PATCH', '/api/crm/v1/companies/' . $id->value, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'lock-2', 'HTTP_IF_MATCH' => '1'], '{"city":"Madrid"}');
            $response = $client->getResponse();
            self::assertSame([409, CrmApiCode::CONFLICT_UNCOMMITTED, CrmApiCode::CONFLICT_UNCOMMITTED], [$response->getStatusCode(), $response->headers->get('X-Conflict-Code'), $response->headers->get('X-Error-Code')]);
            self::assertNotNull($response->headers->get('Retry-After'));
        });
        // Two updates of one base version with different keys: one is stored, the other is CRM_001.
        $this->writes()->updateCompany($this->principal(), $this->acme(), new IdempotencyKey('u-a'), $id, new RecordVersion(1), new CrmApiRecordChanges(SubjectType::Company, ['city' => 'Madrid']));
        $refusal = $this->refused(CrmApiCode::STALE, fn () => $ops->updateCompany($this->principal(), $this->acme(), new IdempotencyKey('u-b'), $id, new RecordVersion(1), new CrmApiRecordChanges(SubjectType::Company, ['city' => 'Paris'])));
        self::assertSame(['current_version' => 2], $refusal->extra);
        self::assertSame('Madrid', $this->text('SELECT city FROM nowo_crm_company WHERE id = ?', [$id->value]));
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_api_idempotency'));
    }

    public function testTheSameKeyAtOnce(): void
    {
        if (!$this->servers()) {
            return;
        }
        $other = $this->second();
        $ops   = $this->svc($other, CrmApiRecordOperations::class);
        $draft = new CrmApiRecordDraft(SubjectType::Company, ['name' => 'Acme']);
        $p     = $this->principal();
        $scope = CrmApiIdempotency::scope($p->channel, $p->tokenId);
        $key   = hash('sha256', "acme\nsame");
        $print = CrmApiIdempotency::fingerprint(CrmApiOperation::CompanyCreate, $draft->canonical());
        $id    = $this->company('Acme (first writer)');
        // The first writer's claim is open: the second waits on the key, gives up (retry is safe) …
        $this->hold('INSERT INTO nowo_crm_api_idempotency (scope_hash, key_hash, tenant_id, operation, fingerprint, outcome, result_ids, created_at, expires_at) VALUES (?, ?, ?, ?, ?, NULL, NULL, ?, ?)', [$scope, $key, 'acme', 'company.create', $print, '2026-11-18 10:00:00.000000', '2026-11-19 10:00:00'], function () use ($ops, $p, $draft): void {
            try {
                $ops->createCompany($p, $this->acme(), new IdempotencyKey('same'), $draft);
                self::fail('no lock conflict');
            } catch (ConcurrencyConflict $e) {
                self::assertFalse($e->committed);
            }
        }, 'UPDATE nowo_crm_api_idempotency SET outcome = 201, result_ids = ? WHERE scope_hash = ? AND key_hash = ?', ['{"record":"' . $id . '"}', $scope, $key]);
        // … and once the first committed, the same key and input replay its record; another input is IDEMP_002.
        $replay = $ops->createCompany($p, $this->acme(), new IdempotencyKey('same'), $draft);
        self::assertTrue($replay->replayed);
        self::assertInstanceOf(CrmApiItem::class, $replay->result);
        $record = $replay->result->fact('record');
        self::assertInstanceOf(CrmApiItem::class, $record);
        self::assertSame($id, $record->fact('id'));
        $this->refused(CrmApiCode::IDEMP_MISMATCH, fn () => $ops->createCompany($p, $this->acme(), new IdempotencyKey('same'), new CrmApiRecordDraft(SubjectType::Company, ['name' => 'Other'])));
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_company'));

        // A first writer that rolled back leaves nothing: the second writes once.
        $this->hold('INSERT INTO nowo_crm_api_idempotency (scope_hash, key_hash, tenant_id, operation, fingerprint, outcome, result_ids, created_at, expires_at) VALUES (?, ?, ?, ?, ?, NULL, NULL, ?, ?)', [$scope, hash('sha256', "acme\nrolled"), 'acme', 'company.create', $print, '2026-11-18 10:00:00.000000', '2026-11-19 10:00:00'], static fn (): null => null);
        self::assertFalse($ops->createCompany($p, $this->acme(), new IdempotencyKey('rolled'), new CrmApiRecordDraft(SubjectType::Company, ['name' => 'Beta']))->replayed);
        self::assertSame(2, $this->int('SELECT COUNT(*) FROM nowo_crm_company'));
    }

    public function testDuplicateFirstCreatesOnTwoConnections(): void
    {
        if (!$this->servers()) {
            return;
        }
        $other = $this->second();
        $ops   = $this->svc($other, CrmApiRecordOperations::class);
        $p     = $this->principal();
        $this->company('Seed');
        // The counter row serialises the creates of a type: a held counter makes the second give up.
        $this->hold("SELECT value FROM nowo_crm_counter WHERE tenant_id = 'acme' AND resource = 'companies' FOR UPDATE", [], function () use ($ops, $p): void {
            try {
                $ops->createCompany($p, $this->acme(), new IdempotencyKey('dup-0'), new CrmApiRecordDraft(SubjectType::Company, ['name' => 'Acme', 'tax_id' => 'B1']));
                self::fail('no lock conflict');
            } catch (ConcurrencyConflict $e) {
                self::assertFalse($e->committed);
            }
        });
        $first  = $this->writes()->createCompany($p, $this->acme(), new IdempotencyKey('dup-1'), new CrmApiRecordDraft(SubjectType::Company, ['name' => 'Acme', 'tax_id' => 'B1']));
        $second = $ops->createCompany($p, $this->acme(), new IdempotencyKey('dup-2'), new CrmApiRecordDraft(SubjectType::Company, ['name' => 'Acme Corp', 'tax_id' => 'b-1']));
        self::assertInstanceOf(CrmApiItem::class, $first->result);
        self::assertInstanceOf(CrmApiItem::class, $second->result);
        self::assertTrue($second->result->fact('duplicate'));
        self::assertEquals($first->result->fact('record'), $second->result->fact('record'));
        $this->writes()->createContact($p, $this->acme(), new IdempotencyKey('mail-1'), new CrmApiRecordDraft(SubjectType::Contact, ['last_name' => 'A', 'email' => 'a@x.test']));
        $mail = $ops->createContact($p, $this->acme(), new IdempotencyKey('mail-2'), new CrmApiRecordDraft(SubjectType::Contact, ['last_name' => 'B', 'email' => 'A@x.test']));
        self::assertInstanceOf(CrmApiItem::class, $mail->result);
        self::assertTrue($mail->result->fact('duplicate'));
        self::assertSame([2, 1], [$this->int("SELECT COUNT(*) FROM nowo_crm_company WHERE tax_id_key IS NOT NULL OR name_key = 'seed'"), $this->int('SELECT COUNT(*) FROM nowo_crm_contact')]);
    }

    public function testAContactCreateLocksItsCompanyBeforeTheCounter(): void
    {
        if (!$this->servers()) {
            return;
        }
        $acme  = $this->company('Acme');
        $other = $this->second();
        $ops   = self::svc($other, CrmApiRecordOperations::class);
        $draft = new CrmApiRecordDraft(SubjectType::Contact, ['last_name' => 'Ada'], company: new CrmApiCompanyLink(new RecordId($acme), 'CEO'));
        // Company X held by the first connection (as an import or a contact merge holds it before the counter): the
        // create waits on the company, before the counter, and gives up without writing.
        $this->hold('SELECT id FROM nowo_crm_company WHERE id = ? FOR UPDATE', [$acme], function () use ($ops, $draft): void {
            try {
                $ops->createContact($this->principal(), $this->acme(), new IdempotencyKey('aff-lock'), $draft);
                self::fail('no lock conflict');
            } catch (ConcurrencyConflict $e) {
                self::assertFalse($e->committed);
            }
            // The counter row was never taken by the waiting create: this connection can still take it.
            $this->connection()->fetchAllAssociative("SELECT value FROM nowo_crm_counter WHERE tenant_id = 'acme' AND resource = 'contacts' FOR UPDATE");
        });
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_contact'));
        self::assertFalse($ops->createContact($this->principal(), $this->acme(), new IdempotencyKey('aff-lock'), $draft)->replayed);
        self::assertSame([1, 1], [$this->int('SELECT COUNT(*) FROM nowo_crm_contact'), $this->int('SELECT COUNT(*) FROM nowo_crm_affiliation')]);
    }

    private function servers(): bool
    {
        if (!self::serverDatabase()) {
            $this->addToAssertionCount(1); // two connections need a database server

            return false;
        }
        $this->bootApi();

        return true;
    }

    /**
     * Runs `$during` while the first connection holds a statement's locks in an open transaction; then commits after
     * `$commit` (when given) or rolls back.
     *
     * @param list<mixed> $params
     * @param list<mixed> $commitParams
     */
    private function hold(string $sql, array $params, callable $during, ?string $commit = null, array $commitParams = []): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();
        try {
            if (str_starts_with($sql, 'SELECT')) {
                $connection->fetchAllAssociative($sql, $params);
            } else {
                $connection->executeStatement($sql, $params);
            }
            $during();
            if ($commit !== null) {
                $connection->executeStatement($commit, $commitParams);
                $connection->commit();

                return;
            }
        } catch (Throwable $e) {
            $connection->rollBack();

            throw $e;
        }
        $connection->rollBack();
    }

    private function second(): TestKernel
    {
        /** @var array<string, mixed> $config */
        $config          = array_replace_recursive(TestServices::PORTS, ['ui' => ['enabled' => true]], self::CONFIG);
        $kernel          = $this->bootKernel(Host::standard()->bundleConfig($config), Host::standard()->configure());
        $context         = $this->svc($kernel, TestContext::class, 'test.context');
        $context->owners = $this->ctx()->owners;
        $context->grant('acme', 'ana', 'view', 'edit', 'manage', 'amounts_view');
        self::boundLockWaits($this->svc($kernel, DoctrineTransactionRunner::class)->manager()->getConnection(), 1);

        return $kernel;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function svc(TestKernel $kernel, string $class, ?string $id = null): object
    {
        $container = $kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        $service = $container->get($id ?? $class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
