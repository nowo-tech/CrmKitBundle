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
use Nowo\TransactionDoctrine\WriteUnit\WriteUnitInterface;

use function str_starts_with;

/**
 * The bundle's units of work on its entity manager (contract sections 15 and 16).
 *
 * - `write()`: a transaction-doctrine write unit (replay after a deadlock / lock-wait timeout, `ConcurrencyConflict`
 *   ⇒ 409 + Retry-After after the last attempt) around one explicit transaction that flushes before the commit; a
 *   unique key refusal becomes {@see DuplicateRowException}, an optimistic lock failure {@see StaleRowException}.
 *   A write inside a running transaction (its own, or a host's on a shared connection) runs and flushes into it.
 * - `read()`: runs and clears.
 * - Both clear the entity manager when the outermost unit ends, and a closed entity manager is reset through the
 *   registry before the next unit (worker mode: no managed entity and no broken manager survive a call).
 *
 * Never flushes the host's entity manager (rule 6): with `entity_manager: null` it is the default one, used only in
 * explicit transactions; there the runner forgets only the bundle's entities and refuses to write while the host has
 * pending changes of its own (audit ST-M4). A configured name that resolves to the registry's default manager (for
 * example `default`) is the same shared manager and gets the same safeguards.
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
    ) {
    }

    /**
     * Runs a write unit.
     */
    public function write(callable $work): mixed
    {
        $manager = $this->manager();
        if ($manager->getConnection()->isTransactionActive()) {
            // Inside a running unit (ours, or a host transaction on a shared connection: a host unit of work): one
            // transaction, one replay layer; our changes are flushed into it.
            $this->refuseForeignChanges($manager);
            $result = $work();
            $manager->flush();

            return $result;
        }
        $this->refuseForeignChanges($manager);

        return $this->writeUnit->run(function () use ($work): mixed { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $manager = $this->manager();
            $this->release($manager);
            try {
                return $manager->wrapInTransaction(static function (EntityManagerInterface $manager) use ($work): mixed { // @igor-ignore - the runner calls this closure at once and keeps nothing
                    $result = $work();
                    $manager->flush();

                    return $result;
                });
            } catch (UniqueConstraintViolationException $e) {
                throw new DuplicateRowException($e);
            } catch (OptimisticLockException $e) {
                throw new StaleRowException($e);
            } finally {
                $this->release($this->manager());
            }
        });
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
