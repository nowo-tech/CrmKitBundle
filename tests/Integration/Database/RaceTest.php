<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use Nowo\CrmKitBundle\Application\Port\CrmStoreInterface;
use Nowo\CrmKitBundle\Application\Shared\WriteConflictException;
use Nowo\CrmKitBundle\Domain\Enum\DuplicatePolicy;
use Nowo\CrmKitBundle\Domain\Enum\ImportFormat;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Model\Contact;
use Nowo\CrmKitBundle\Domain\Service\ImportServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\MergeServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\RecordInput;
use Nowo\CrmKitBundle\Domain\Service\RecordServiceInterface;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Nowo\CrmKitBundle\Infrastructure\Doctrine\DoctrineTransactionRunner;
use Nowo\CrmKitBundle\Tests\Fixtures\TestContext;
use Nowo\CrmKitBundle\Tests\Fixtures\TestServices;
use Nowo\CrmKitBundle\Tests\Integration\Kernel\Host;
use Nowo\CrmKitBundle\Tests\Integration\Kernel\TestKernel;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Throwable;

use function array_replace_recursive;
use function count;

/**
 * Races (contract section 15, REQ-RUNTIME-003): a second kernel on its own connection writes while the first holds
 * its locks; the second waits, gives up with a lock conflict (409 + CONFLICT_001 + Retry-After + X-Conflict-Code),
 * and after the first commits a replay sees the new state (merged, claimed, stale, at the limit, duplicate) — never
 * a lost update, a duplicate or a 500. The injected races (a racing e-mail, a version moved under the flush) run on
 * every database.
 */
final class RaceTest extends CrmTestCase
{
    public function testARacingEmailIsAFormErrorAndAMovedVersionIsStale(): void
    {
        $this->boot();
        $this->admin();
        // A racing writer's row refuses ours and then disappears (rolled back with our unit): one more run stores ours.
        $this->ctx()->emailRaces = 1;
        $this->contact('Ada', 'L', 'ada@x.test');
        self::assertSame(1, $this->int("SELECT COUNT(*) FROM nowo_crm_contact WHERE email_key = 'ada@x.test'"));
        $this->ctx()->emailRaces = 2;
        try {
            $this->contact('Eve', 'L', 'eve@x.test');
            self::fail('stored');
        } catch (WriteConflictException $e) {
            self::assertSame('crm_write_conflict', $e->errorCode);
        }
        try {
            $this->contact('Other', 'L', 'ADA@x.test');
            self::fail('stored twice');
        } catch (InvalidInputException $e) {
            self::assertSame(['email' => 'error.email_taken'], $e->errors);
        }
        $id                        = $this->company('Acme');
        $this->ctx()->bumpVersions = true;
        try {
            $this->records()->update($id, new RecordInput(SubjectType::Company, ['city' => 'Madrid']), 1);
            self::fail('lost update');
        } catch (ConflictException $e) {
            self::assertSame(ConflictException::STALE, $e->errorCode);
        }
        self::assertSame('', $this->text('SELECT COALESCE(city, \'\') FROM nowo_crm_company'));
        $this->ctx()->emailRaces = 1;
        $bob                     = $this->contact('Bob', 'B');
        try {
            $this->records()->update($bob, new RecordInput(SubjectType::Contact, ['email' => 'bob@x.test']), 1);
            $this->addToAssertionCount(1);
        } catch (Throwable $e) {
            self::fail('an update does not insert: ' . $e::class);
        }
    }

    public function testCrossedMergesOnTwoConnections(): void
    {
        if (!$this->twoConnections()) {
            return;
        }
        $a     = $this->company('Acme');
        $b     = $this->company('ACME');
        $other = $this->second();
        $this->holdRecords(SubjectType::Company, [$a, $b], function () use ($other, $a, $b): void {
            $this->expectLockConflict(static fn () => self::svc($other, MergeServiceInterface::class)->merge(SubjectType::Company, $b, $a, [], 1, 1));
        });
        $this->service(MergeServiceInterface::class)->merge(SubjectType::Company, $a, $b, [], 1, 1);
        try {
            self::svc($other, MergeServiceInterface::class)->merge(SubjectType::Company, $b, $a, [], 2, 1);
            self::fail('merged twice');
        } catch (ConflictException $e) {
            self::assertContains($e->errorCode, [ConflictException::MERGE_STALE, ConflictException::MERGED]);
        }
        self::assertSame(1, $this->int("SELECT COUNT(*) FROM nowo_crm_company WHERE status = 'active'"));
        self::assertSame(1, $this->int("SELECT value FROM nowo_crm_counter WHERE resource = 'companies'"));
    }

    public function testConcurrentEditsOfOneVersion(): void
    {
        if (!$this->twoConnections()) {
            return;
        }
        $id    = $this->company('Acme');
        $other = $this->second();
        $this->holdRecords(SubjectType::Company, [$id], function () use ($other, $id): void {
            $this->expectLockConflict(static fn () => self::svc($other, RecordServiceInterface::class)->update($id, new RecordInput(SubjectType::Company, ['city' => 'Bilbao']), 1));
        });
        $this->records()->update($id, new RecordInput(SubjectType::Company, ['city' => 'Madrid']), 1);
        try {
            self::svc($other, RecordServiceInterface::class)->update($id, new RecordInput(SubjectType::Company, ['city' => 'Bilbao']), 1);
            self::fail('lost update');
        } catch (ConflictException $e) {
            self::assertSame(ConflictException::STALE, $e->errorCode);
        }
        self::assertSame('Madrid', $this->text('SELECT city FROM nowo_crm_company'));
    }

    public function testTwoImportsConfirmedAtOnceAndOneEmail(): void
    {
        if (!$this->twoConnections()) {
            return;
        }
        $imports = $this->service(ImportServiceInterface::class);
        $id      = $imports->upload(SubjectType::Contact, ImportFormat::Csv, 'a.csv', "email,last_name\nada@x.test,A\n");
        $other   = $this->second();
        $runner  = $this->runner();
        $runner->manager()->getConnection()->beginTransaction();
        try {
            self::assertTrue($this->service(CrmStoreInterface::class)->claimImport(new TenantRef('acme'), $id, 1, 'first', '{"email":"email","last_name":"last_name"}', 'skip', $this->clock()->now()));
            $this->expectLockConflict(static fn () => self::svc($other, ImportServiceInterface::class)->confirm($id, ['email' => 'email', 'last_name' => 'last_name'], DuplicatePolicy::Skip, 1));
        } finally {
            $runner->manager()->getConnection()->commit();
        }
        try {
            self::svc($other, ImportServiceInterface::class)->confirm($id, ['email' => 'email', 'last_name' => 'last_name'], DuplicatePolicy::Skip, 1);
            self::fail('claimed twice');
        } catch (ConflictException $e) {
            self::assertSame(ConflictException::IMPORT_RUNNING, $e->errorCode);
        }

        // The same person in two imports: the second run waits for the first one's row, then settles it as a duplicate.
        $second = self::svc($other, ImportServiceInterface::class)->upload(SubjectType::Contact, ImportFormat::Csv, 'b.csv', "email,last_name\nADA@x.test,B\n");
        self::svc($other, ImportServiceInterface::class)->confirm($second, ['email' => 'email', 'last_name' => 'last_name'], DuplicatePolicy::Skip, 1);
        $runner->manager()->getConnection()->beginTransaction();
        try {
            $contact = new Contact('01900000-0000-7000-8000-0000000000aa', 'acme', $this->clock()->now());
            $contact->apply(['last_name' => 'A', 'email' => 'ada@x.test']);
            $runner->manager()->persist($contact);
            $runner->manager()->flush();
            $this->expectLockConflict(static fn () => self::svc($other, ImportServiceInterface::class)->run($second));
        } finally {
            $runner->manager()->getConnection()->commit();
            $runner->manager()->clear();
        }
        self::assertTrue(self::svc($other, ImportServiceInterface::class)->run($second));
        self::assertSame(1, self::svc($other, ImportServiceInterface::class)->preview($second)->counts['duplicate']);
        self::assertSame(1, $this->int("SELECT COUNT(*) FROM nowo_crm_contact WHERE email_key = 'ada@x.test'"));
    }

    public function testCreatesAtThePlanLimit(): void
    {
        if (!$this->twoConnections()) {
            return;
        }
        $this->ctx()->limits['companies'] = 2;
        $this->company('A');
        $other                = $this->second();
        $otherContext         = self::svc($other, TestContext::class, 'test.context');
        $otherContext->limits = ['companies' => 2];
        $runner               = $this->runner();
        $runner->manager()->getConnection()->beginTransaction();
        try {
            self::assertTrue($this->service(CrmStoreInterface::class)->takeCounter(new TenantRef('acme'), 'companies', 2, static fn (): int => 1));
            $this->expectLockConflict(static fn () => self::svc($other, RecordServiceInterface::class)->create(new RecordInput(SubjectType::Company, ['name' => 'C'])));
        } finally {
            $runner->manager()->getConnection()->commit();
        }
        try {
            self::svc($other, RecordServiceInterface::class)->create(new RecordInput(SubjectType::Company, ['name' => 'C']));
            self::fail('over the limit');
        } catch (ConflictException $e) {
            self::assertSame(ConflictException::LIMIT, $e->errorCode);
        }
    }

    public function testAnHttpWriteWaitingOnALockAnswers409WithTheCodes(): void
    {
        if (!$this->twoConnections()) {
            return;
        }
        $id      = $this->company('Acme');
        $other   = $this->second();
        $browser = $other->getContainer()->get('test.client');
        self::assertInstanceOf(KernelBrowser::class, $browser);
        $browser->disableReboot();
        $form = $browser->request('GET', '/crm/companies/' . $id . '/edit')->filter('[data-testid="crm-record-form"] form')->form();
        $this->holdRecords(SubjectType::Company, [$id], static function () use ($browser, $form): void {
            $browser->submit($form, ['crm_record[city]' => 'Bilbao']);
            $response = $browser->getResponse();
            self::assertSame(409, $response->getStatusCode());
            self::assertSame('CONFLICT_001', $response->headers->get('X-Conflict-Code'));
            self::assertNotNull($response->headers->get('Retry-After'));
        });
        $browser->submit($form, ['crm_record[city]' => 'Bilbao']);
        self::assertSame(303, $browser->getResponse()->getStatusCode(), 'the connection is usable afterwards');
        self::assertSame('Bilbao', $this->text('SELECT city FROM nowo_crm_company'));
    }

    private function twoConnections(): bool
    {
        if (!self::serverDatabase()) {
            $this->addToAssertionCount(1); // two connections need a database server

            return false;
        }
        $this->boot();
        $this->admin();

        return true;
    }

    /**
     * A second kernel on its own connection, acting as `leo` with every grant, lock waits bounded to 1 s.
     */
    private function second(): TestKernel
    {
        /** @var array<string, mixed> $config */
        $config          = array_replace_recursive(TestServices::PORTS, ['ui' => ['enabled' => true]]);
        $kernel          = $this->bootKernel(Host::standard()->bundleConfig($config), Host::standard()->configure());
        $context         = self::svc($kernel, TestContext::class, 'test.context');
        $context->actor  = 'leo';
        $context->owners = $this->ctx()->owners;
        $context->grant('acme', 'leo', 'view', 'edit', 'manage');
        $runner = self::svc($kernel, DoctrineTransactionRunner::class);
        self::boundLockWaits($runner->manager()->getConnection(), 1);

        return $kernel;
    }

    /**
     * Runs `$during` while this connection holds `FOR UPDATE` locks on the records (then rolls back).
     *
     * @param list<string> $ids
     * @param callable(): void $during
     */
    private function holdRecords(SubjectType $type, array $ids, callable $during): void
    {
        $runner     = $this->runner();
        $connection = $runner->manager()->getConnection();
        $connection->beginTransaction();
        try {
            self::assertCount(count($ids), $this->service(CrmStoreInterface::class)->lockRecords($type, new TenantRef('acme'), $ids));
            $during();
        } finally {
            $connection->rollBack();
            $runner->manager()->clear();
        }
    }

    /**
     * @param callable(): mixed $call
     */
    private function expectLockConflict(callable $call): void
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
