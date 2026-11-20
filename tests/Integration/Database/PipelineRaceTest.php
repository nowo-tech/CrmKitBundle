<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Application\Pipeline\DealInput;
use Nowo\CrmKitBundle\Application\Pipeline\DealService;
use Nowo\CrmKitBundle\Application\Pipeline\FunnelReader;
use Nowo\CrmKitBundle\Application\Pipeline\FunnelService;
use Nowo\CrmKitBundle\Application\Pipeline\NextActionService;
use Nowo\CrmKitBundle\Application\Pipeline\Port\PipelineStoreInterface;
use Nowo\CrmKitBundle\Application\Port\CrmStoreInterface;
use Nowo\CrmKitBundle\Domain\Enum\FunnelKind;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\CrmTaskRefusedException;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Nowo\CrmKitBundle\Infrastructure\Doctrine\DoctrineTransactionRunner;
use Nowo\CrmKitBundle\Tests\Fixtures\TestContext;
use Nowo\CrmKitBundle\Tests\Fixtures\TestServices;
use Nowo\CrmKitBundle\Tests\Fixtures\TestTaskPort;
use Nowo\CrmKitBundle\Tests\Integration\Kernel\Host;
use Nowo\CrmKitBundle\Tests\Integration\Kernel\TestKernel;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Symfony\Component\DependencyInjection\ContainerInterface;

use function array_replace_recursive;
use function str_repeat;

/**
 * Funnel races (REQ-RUNTIME-003, two connections on MySQL / MariaDB / PostgreSQL): two moves of one deal (one change per
 * committed move, the other stale), creates at the `deals` plan limit, and a next action whose CRM unit meets a lock
 * after a host task port committed (CONFLICT_002; the same key's retry adopts the task: one task, one row). On SQLite
 * the port refusals and the adoption run without the second connection.
 */
final class PipelineRaceTest extends CrmTestCase
{
    private const CONFIG = ['pipeline' => ['enabled' => true, 'task_port' => 'test.task_port']];

    public function testPortRefusalsWriteNothingAndAKeyIsAdopted(): void
    {
        $this->boot(self::CONFIG);
        $this->admin();
        $acme         = $this->company('Acme');
        $port         = $this->port($this->kernel);
        $actions      = $this->service(NextActionService::class);
        $port->refuse = 'TOKEN_001';
        try {
            $actions->set(SubjectType::Company, $acme, 'Call', new DateTimeImmutable('2026-11-20'), null, str_repeat('1', 64));
            self::fail('stored');
        } catch (CrmTaskRefusedException $e) {
            self::assertSame(['TOKEN_001', false], [$e->errorCode, $e->transient]);
        }
        $port->refuse    = 'no shape';
        $port->transient = true;
        try {
            $actions->set(SubjectType::Company, $acme, 'Call', new DateTimeImmutable('2026-11-20'), null, str_repeat('1', 64));
            self::fail('stored');
        } catch (CrmTaskRefusedException $e) {
            self::assertSame([null, true], [$e->errorCode, $e->transient]);
        }
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_next_action'));
        $port->refuse = null;
        $id           = $actions->set(SubjectType::Company, $acme, 'Call', new DateTimeImmutable('2026-11-20'), null, str_repeat('1', 64));
        self::assertSame($id, $actions->set(SubjectType::Company, $acme, 'Call', new DateTimeImmutable('2026-11-20'), null, str_repeat('1', 64)));
        self::assertSame(3, $port->calls, 'a stored key never asks the port again');
        $list = $actions->list(SubjectType::Company, $acme);
        self::assertSame([true, null, null], [$list[0]->taskHidden, $list[0]->taskType, $list[0]->taskId]);
        $port->visible = ['task-1'];
        $list          = $actions->list(SubjectType::Company, $acme);
        self::assertSame([false, 'host', 'task-1', '/tasks/task-1'], [$list[0]->taskHidden, $list[0]->taskType, $list[0]->taskId, $list[0]->taskUrl]);
    }

    public function testTwoMovesOfOneDeal(): void
    {
        if (!$this->twoConnections()) {
            return;
        }
        [$deal, $stages] = $this->deal();
        $other           = $this->second();
        $this->holdDeal($deal, function () use ($other, $deal, $stages): void {
            $this->expectLockConflict(static fn () => self::svc($other, DealService::class)->moveStage($deal, $stages[1], null, null, 1), false);
        });
        self::assertTrue($this->service(DealService::class)->moveStage($deal, $stages[1], null, null, 1));
        try {
            self::svc($other, DealService::class)->moveStage($deal, $stages[2], 'price', null, 1);
            self::fail('a move from a stale version');
        } catch (ConflictException $e) {
            self::assertSame(ConflictException::STALE, $e->errorCode);
        }
        self::assertTrue(self::svc($other, DealService::class)->moveStage($deal, $stages[2], 'price'));
        self::assertSame(3, $this->int('SELECT COUNT(*) FROM nowo_crm_stage_change WHERE item_id = ?', [$deal]), 'one change per committed move');
        self::assertSame('lost', $this->text('SELECT status FROM nowo_crm_deal WHERE id = ?', [$deal]));
    }

    public function testCreatesAtTheDealsLimit(): void
    {
        if (!$this->twoConnections(self::CONFIG)) {
            return;
        }
        $this->ctx()->limits['deals']                                 = 2;
        [, $stages, $funnel, $acme]                                   = $this->deal();
        $other                                                        = $this->second(self::CONFIG);
        self::svc($other, TestContext::class, 'test.context')->limits = ['deals' => 2];
        $connection                                                   = $this->runner()->manager()->getConnection();
        $connection->beginTransaction();
        try {
            self::assertTrue($this->service(CrmStoreInterface::class)->takeCounter(new TenantRef('acme'), 'deals', 2, static fn (): int => 1));
            $this->expectLockConflict(static fn () => self::svc($other, DealService::class)->create(new DealInput('B', $funnel, companyId: $acme)), false);
        } finally {
            $connection->commit();
        }
        try {
            self::svc($other, DealService::class)->create(new DealInput('C', $funnel, companyId: $acme));
            self::fail('over the limit');
        } catch (ConflictException $e) {
            self::assertSame(ConflictException::LIMIT, $e->errorCode);
        }
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_deal'));
        self::assertNotSame([], $stages);
    }

    public function testANextActionLockAfterTheHostTaskCommittedIsConflict002(): void
    {
        if (!$this->twoConnections(self::CONFIG)) {
            return;
        }
        [$deal] = $this->deal();
        $other  = $this->second(self::CONFIG);
        $key    = str_repeat('9', 64);
        $this->holdDeal($deal, function () use ($other, $deal, $key): void {
            $this->expectLockConflict(static fn () => self::svc($other, NextActionService::class)->set(SubjectType::Deal, $deal, 'Call', new DateTimeImmutable('2026-11-20'), null, $key), true);
        });
        $id = self::svc($other, NextActionService::class)->set(SubjectType::Deal, $deal, 'Call', new DateTimeImmutable('2026-11-20'), null, $key);
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_next_action'));
        self::assertSame(['task-1'], [$this->text('SELECT task_id FROM nowo_crm_next_action WHERE id = ?', [$id])]);
        self::assertCount(1, $this->port($other)->tasks, 'the retry adopted the task');
        // The built-in list never committed anything before its unit: a lock there stays CONFLICT_001.
        $builtIn = $this->second(['pipeline' => ['enabled' => true]]);
        $this->holdDeal($deal, function () use ($builtIn, $deal): void {
            $this->expectLockConflict(static fn () => self::svc($builtIn, NextActionService::class)->set(SubjectType::Deal, $deal, 'Mail', new DateTimeImmutable('2026-11-20'), null, str_repeat('8', 64)), false);
        });
    }

    /**
     * A deal funnel and one deal of a company.
     *
     * @return array{string, list<string>, string, string}
     */
    private function deal(): array
    {
        $acme   = $this->company('Acme');
        $funnel = $this->service(FunnelService::class)->create(FunnelKind::Deal, null, 'Sales');
        $stages = [];
        foreach ($this->service(FunnelReader::class)->funnel($funnel)->stages as $stage) {
            $stages[] = $stage->id;
        }

        return [$this->service(DealService::class)->create(new DealInput('A', $funnel, companyId: $acme)), $stages, $funnel, $acme];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function twoConnections(array $config = []): bool
    {
        if (!self::serverDatabase()) {
            $this->addToAssertionCount(1); // two connections need a database server

            return false;
        }
        $this->boot($config === [] ? ['pipeline' => ['enabled' => true]] : $config);
        $this->admin();

        return true;
    }

    /**
     * A second kernel on its own connection, acting as `leo` with every grant, lock waits bounded to 1 s.
     *
     * @param array<string, mixed> $override
     */
    private function second(array $override = ['pipeline' => ['enabled' => true]]): TestKernel
    {
        /** @var array<string, mixed> $config */
        $config          = array_replace_recursive(TestServices::PORTS, ['ui' => ['enabled' => true]], $override);
        $kernel          = $this->bootKernel(Host::standard()->bundleConfig($config), Host::standard()->configure());
        $context         = self::svc($kernel, TestContext::class, 'test.context');
        $context->actor  = 'leo';
        $context->owners = $this->ctx()->owners;
        $context->grant('acme', 'leo', 'view', 'edit', 'manage');
        self::boundLockWaits(self::svc($kernel, DoctrineTransactionRunner::class)->manager()->getConnection(), 1);

        return $kernel;
    }

    /**
     * Runs `$during` while this connection holds the deal `FOR UPDATE` (then rolls back).
     *
     * @param callable(): void $during
     */
    private function holdDeal(string $id, callable $during): void
    {
        $runner     = $this->runner();
        $connection = $runner->manager()->getConnection();
        $connection->beginTransaction();
        try {
            self::assertNotNull($this->service(PipelineStoreInterface::class)->deal(new TenantRef('acme'), $id, true));
            $during();
        } finally {
            $connection->rollBack();
            $runner->manager()->clear();
        }
    }

    /**
     * @param callable(): mixed $call
     */
    private function expectLockConflict(callable $call, bool $committed): void
    {
        try {
            $call();
            self::fail('no lock conflict');
        } catch (ConcurrencyConflict $e) {
            self::assertSame($committed, $e->committed);
        }
    }

    private function port(TestKernel $kernel): TestTaskPort
    {
        return self::svc($kernel, TestTaskPort::class, 'test.task_port');
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
