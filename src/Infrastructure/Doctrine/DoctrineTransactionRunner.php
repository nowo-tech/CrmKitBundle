<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Doctrine;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\CrmKitBundle\Application\Shared\DuplicateRowException;
use Nowo\CrmKitBundle\Application\Shared\StaleRowException;
use Nowo\CrmKitBundle\Application\Shared\TransactionRunnerInterface;
use Nowo\TransactionDoctrine\ConnectionRepairer;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnitInterface;
use Throwable;

use function str_starts_with;

/**
 * The bundle's units of work on its entity manager (contract sections 15 and 16).
 *
 * - `write()`: a transaction-doctrine write unit (replay after a deadlock / lock-wait timeout, `ConcurrencyConflict`
 *   ⇒ 409 + Retry-After after the last attempt) around one explicit transaction that flushes before the commit; a
 *   unique key refusal becomes {@see DuplicateRowException}, an optimistic lock failure {@see StaleRowException}.
 *   A write is refused inside a transaction that is already running (a host's on the same connection): the bundle
 *   releases its events and audit after its own commit, which an outer transaction could still roll back.
 * - `read()`: runs and clears (inside a running transaction it only runs).
 * - Both forget the bundle's entities when the unit ends. On the private entity manager a refused unit closes it
 *   (Doctrine's `wrapInTransaction`) and it is reset through the registry before the next unit (worker mode: no
 *   managed entity and no broken manager survive a call).
 *
 * **(0.11.0)** Both managers share one transaction path with the lost-savepoint pattern (transaction-doctrine's
 * {@see ConnectionRepairer}).
 *
 * Never flushes the host's entity manager (rule 6): with `entity_manager: null` it is the default one, and the
 * runner opens, flushes and commits its transaction itself; on a failure it rolls back and forgets only the
 * bundle's entities, so the manager stays open and the host's entities stay managed. The only closed shared manager
 * is one Doctrine closed during a failed flush (then it is unusable for the host too, and reset before the next
 * unit). It also refuses to write while the host has pending changes of its own (audit ST-M4). A configured name
 * that resolves to the registry's default manager (for example `default`) is the same shared manager and gets the
 * same safeguards. A deadlock replay keeps the host's entity manager (transaction-doctrine ^1.8.1 puts it back to what
 * the database holds instead of clearing it).
 */
final readonly class DoctrineTransactionRunner implements TransactionRunnerInterface
{
    /** Namespace of the bundle's entities. */
    private const ENTITY_NAMESPACE = 'Nowo\\CrmKitBundle\\Domain\\';

    /**
     * @param string|null $managerName the bundle's entity manager (null: the default one)
     */
    public function __construct(
        private ManagerRegistry $registry,
        private WriteUnitInterface $writeUnit,
        private ?string $managerName = null,
        private ConnectionRepairer $repairer = new ConnectionRepairer(),
    ) {
    }

    /**
     * Runs a write unit.
     *
     * @throws LogicException inside a running transaction, or with pending host changes on a shared manager
     */
    public function write(callable $work): mixed
    {
        $manager = $this->manager();
        if ($manager->getConnection()->isTransactionActive()) {
            throw new LogicException('nowo_crm_kit writes in transactions of its own and releases their events and audit after its commit: call it outside your transaction (with Messenger, from a bus without the doctrine_transaction middleware).');
        }
        $this->refuseForeignChanges($manager);

        return $this->writeUnit->run(function () use ($work): mixed { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $manager = $this->manager();
            $shared  = $this->shared($manager);
            $this->release($manager);
            try {
                return $this->transaction($manager, $work, $shared);
            } catch (UniqueConstraintViolationException $e) {
                throw new DuplicateRowException($e);
            } catch (OptimisticLockException $e) {
                throw new StaleRowException($e);
            } finally {
                // Shared: never reset here (only a failed flush closes it, and the next unit resets it); private: a
                // refused unit closed it, reset now.
                $this->release($shared ? $manager : $this->manager());
            }
        });
    }

    /**
     * One transaction of the unit: begin, work, flush, commit. Any failure rolls back to the nesting level the unit
     * started at (REQ-RUNTIME-003, the lost-savepoint pattern): a rollback that fails itself (a MySQL / MariaDB deadlock
     * inside a nested flush drops the savepoints, so `ROLLBACK TO SAVEPOINT` fails with 1305; a broken link) closes the
     * connection, so the worker's next unit never inherits a half-open transaction, and the original failure is
     * rethrown (never the rollback's). The repair is transaction-doctrine's {@see ConnectionRepairer}. The private entity manager is closed on a failure (Doctrine's
     * `wrapInTransaction` did the same; it is reset before the next unit); the host's shared one stays open.
     *
     * @param callable(): mixed $work
     */
    private function transaction(EntityManagerInterface $manager, callable $work, bool $shared): mixed
    {
        $connection = $manager->getConnection();
        $level      = $connection->getTransactionNestingLevel();
        $connection->beginTransaction();
        try {
            $result = $work();
            $manager->flush();
            $connection->commit();

            return $result;
        } catch (Throwable $e) {
            if (!$shared) {
                $manager->close();
            }
            // transaction-doctrine's repairer: rolls back to the entry level, closes the connection when a rollback fails
            // (a lost savepoint; logged as a warning), clears a rollback-only flag across the close.
            $this->repairer->afterFailure($connection, $level);

            throw $e;
        }
    }

    /**
     * Runs a read.
     */
    public function read(callable $work): mixed
    {
        $manager = $this->manager();
        if ($manager->getConnection()->isTransactionActive()) {
            return $work();
        }
        try {
            return $work();
        } finally {
            $this->release($manager);
        }
    }

    /**
     * Forgets the bundle's managed entities: the whole unit of work on the private entity manager; on a shared one
     * (`entity_manager: null`, or the default manager's name) only the bundle's own entities, so the host's managed
     * entities and pending changes stay (audit ST-M4).
     */
    public function release(EntityManagerInterface $manager): void
    {
        if (!$this->shared($manager)) {
            $manager->clear(); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service

            return;
        }
        foreach ($manager->getUnitOfWork()->getIdentityMap() as $class => $entities) {
            if (!str_starts_with($class, self::ENTITY_NAMESPACE)) {
                continue;
            }
            foreach ($entities as $entity) {
                $manager->detach($entity); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            }
        }
    }

    /**
     * On a shared entity manager the bundle never flushes the host's pending changes (rule 6, audit ST-M4): a write
     * while the host has unflushed inserts, updates or deletes of its own entities is refused.
     *
     * @throws LogicException
     */
    private function refuseForeignChanges(EntityManagerInterface $manager): void
    {
        if (!$this->shared($manager)) {
            return;
        }
        $unitOfWork = $manager->getUnitOfWork();
        $unitOfWork->computeChangeSets();
        foreach ([$unitOfWork->getScheduledEntityInsertions(), $unitOfWork->getScheduledEntityUpdates(), $unitOfWork->getScheduledEntityDeletions()] as $pending) {
            foreach ($pending as $entity) {
                if (!str_starts_with($entity::class, self::ENTITY_NAMESPACE)) {
                    throw new LogicException('nowo_crm_kit shares the default entity manager (doctrine.entity_manager: null or the default manager\'s name) and will not flush the host\'s pending changes: flush them before calling the CRM, or give the bundle its private entity manager.');
                }
            }
        }
    }

    /**
     * Whether the bundle's entity manager is the host's default one: `entity_manager: null`, or a configured name that
     * resolves to the registry's default manager.
     */
    private function shared(EntityManagerInterface $manager): bool
    {
        return $this->managerName === null || $manager === $this->registry->getManager();
    }

    /**
     * The bundle's entity manager, reset first when a failure closed it.
     */
    public function manager(): EntityManagerInterface
    {
        $manager = $this->registry->getManager($this->managerName);
        if ($manager instanceof EntityManagerInterface && !$manager->isOpen()) {
            $manager = $this->registry->resetManager($this->managerName);
        }

        return $manager instanceof EntityManagerInterface ? $manager : throw new LogicException('nowo_crm_kit needs an ORM entity manager.');
    }
}
