<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\CrmKitBundle\Application\Port\CrmStoreInterface;
use Nowo\CrmKitBundle\Domain\Enum\ActivityDirection;
use Nowo\CrmKitBundle\Domain\Enum\ActivityKind;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Service\CrmPersonalDataInterface;
use Nowo\CrmKitBundle\Domain\Service\EngagementInput;
use Nowo\CrmKitBundle\Domain\Service\EngagementServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\NoteServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\PinnedNotesInterface;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Nowo\CrmKitBundle\Infrastructure\Doctrine\DoctrineTransactionRunner;
use Nowo\CrmKitBundle\Tests\Fixtures\TestContext;
use Nowo\CrmKitBundle\Tests\Fixtures\TestServices;
use Nowo\CrmKitBundle\Tests\Integration\Kernel\Host;
use Nowo\CrmKitBundle\Tests\Integration\Kernel\TestKernel;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Symfony\Component\DependencyInjection\ContainerInterface;

use function array_replace_recursive;

/**
 * 0.11.0 (REQ-RUNTIME-003): two sessions on their own connections race on the same record. Pins serialise on the
 * record's lock, so the pin limit holds (never two pinned notes); two edits of one activity at the same version give
 * one write and one stale conflict; an activity logged while the contact is being erased waits, gives up with a lock
 * conflict (409), and after the erasure is refused (never an activity of an erased person). Needs a database server.
 */
final class ActivitiesRaceTest extends CrmTestCase
{
    private const CONFIG = ['activities' => ['enabled' => true]];

    public function testTwoPinsOnOneRecordKeepOnePinnedNote(): void
    {
        if (!$this->twoConnections()) {
            return;
        }
        $ada   = $this->contact();
        $notes = $this->service(NoteServiceInterface::class);
        $one   = $notes->add(SubjectType::Contact, $ada, 'One');
        $two   = $notes->add(SubjectType::Contact, $ada, 'Two');
        $other = $this->second();
        $this->holdRecord($ada, function () use ($other, $two): void {
            $this->expectLockConflict(static fn () => self::svc($other, PinnedNotesInterface::class)->pin($two, true, 1));
        });
        $this->service(PinnedNotesInterface::class)->pin($one, true, 1);
        self::svc($other, PinnedNotesInterface::class)->pin($two, true, 1);
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_note WHERE subject_id = ? AND pinned = ?', [$ada, true]));
        self::assertSame($two, $this->text('SELECT id FROM nowo_crm_note WHERE subject_id = ? AND pinned = ?', [$ada, true]));
        try {
            $this->service(PinnedNotesInterface::class)->pin($one, true, 2);
            self::fail('pinned from a stale page');
        } catch (ConflictException $e) {
            self::assertSame(ConflictException::STALE, $e->errorCode);
        }
    }

    public function testTwoEditsOfOneActivityAtTheSameVersion(): void
    {
        if (!$this->twoConnections()) {
            return;
        }
        $ada   = $this->contact();
        $id    = $this->engagements()->log(SubjectType::Contact, $ada, $this->call('First'));
        $other = $this->second();
        $this->holdRecord($ada, function () use ($other, $id): void {
            $this->expectLockConflict(static fn () => self::svc($other, EngagementServiceInterface::class)->edit($id, self::callInput('Theirs'), 1));
        });
        $this->engagements()->edit($id, $this->call('Mine'), 1);
        try {
            self::svc($other, EngagementServiceInterface::class)->edit($id, self::callInput('Theirs'), 1);
            self::fail('lost update');
        } catch (ConflictException $e) {
            self::assertSame(ConflictException::STALE, $e->errorCode);
        }
        self::assertSame('Mine', $this->text('SELECT title FROM nowo_crm_engagement WHERE id = ?', [$id]));
    }

    public function testAnActivityLoggedWhileTheContactIsErased(): void
    {
        if (!$this->twoConnections()) {
            return;
        }
        $ada   = $this->contact();
        $other = $this->second();
        $this->holdRecord($ada, function () use ($other, $ada): void {
            $this->expectLockConflict(static fn () => self::svc($other, EngagementServiceInterface::class)->log(SubjectType::Contact, $ada, self::callInput('Late')));
        });
        $this->service(CrmPersonalDataInterface::class)->erase($ada);
        try {
            self::svc($other, EngagementServiceInterface::class)->log(SubjectType::Contact, $ada, self::callInput('Late'));
            self::fail('an activity of an erased person');
        } catch (ConflictException $e) {
            self::assertSame(ConflictException::TRASHED, $e->errorCode);
        }
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_engagement'));
    }

    private function call(string $title): EngagementInput
    {
        return self::callInput($title);
    }

    private static function callInput(string $title): EngagementInput
    {
        return new EngagementInput(ActivityKind::Call, new DateTimeImmutable('2026-11-18 09:00', new DateTimeZone('UTC')), $title, null, ActivityDirection::Out);
    }

    private function engagements(): EngagementServiceInterface
    {
        return $this->service(EngagementServiceInterface::class);
    }

    private function twoConnections(): bool
    {
        if (!self::serverDatabase()) {
            $this->addToAssertionCount(1); // two connections need a database server

            return false;
        }
        $this->boot(self::CONFIG);
        $this->admin();

        return true;
    }

    /**
     * A second kernel on its own connection, acting as `leo` with every grant, lock waits bounded to 1 s.
     */
    private function second(): TestKernel
    {
        /** @var array<string, mixed> $config */
        $config          = array_replace_recursive(TestServices::PORTS, ['ui' => ['enabled' => true]], self::CONFIG);
        $kernel          = $this->bootKernel(Host::standard()->bundleConfig($config), Host::standard()->configure());
        $context         = self::svc($kernel, TestContext::class, 'test.context');
        $context->actor  = 'leo';
        $context->owners = $this->ctx()->owners;
        $context->grant('acme', 'leo', 'view', 'edit', 'manage');
        self::boundLockWaits(self::svc($kernel, DoctrineTransactionRunner::class)->manager()->getConnection(), 1);

        return $kernel;
    }

    /**
     * Runs `$during` while this connection holds the contact's `FOR UPDATE` lock (then rolls back).
     *
     * @param callable(): void $during
     */
    private function holdRecord(string $id, callable $during): void
    {
        $runner     = $this->runner();
        $connection = $runner->manager()->getConnection();
        $connection->beginTransaction();
        try {
            self::assertCount(1, $this->service(CrmStoreInterface::class)->lockRecords(SubjectType::Contact, new TenantRef('acme'), [$id]));
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
