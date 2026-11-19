<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application;

use DateTimeZone;
use Error;
use Exception;
use Nowo\CrmKitBundle\Application\Port\CrmActorDataStoreInterface;
use Nowo\CrmKitBundle\Application\Shared\TransactionRunnerInterface;
use Nowo\CrmKitBundle\Domain\Event\ActorAnonymised;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Port\CrmAuditSinkInterface;
use Nowo\CrmKitBundle\Domain\Service\CrmActorPersonalDataInterface;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\AuditEntry;
use Nowo\CrmKitBundle\Domain\Value\ExactId;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use ReflectionProperty;
use Throwable;

use function ksort;

use const SORT_STRING;

/**
 * Export and anonymisation of a host person across every tenant (0.2.1, contract section 16.5): every CRM reference
 * to their actor id as owner, note author, created_by / updated_by, timeline actor, consent recorder, import uploader,
 * merge actor and owner inside timeline and merge snapshots. A SYSTEM API: no tenant, no actor, no permission check.
 * Anonymisation runs in batches of {@see self::BATCH} rows, one write unit each (a lock conflict replays the batch or
 * answers 409 through the runner); events and audit entries are released after the last commit, per tenant. Records
 * and everyone else's data stay; running it again changes nothing. Stateless.
 */
final readonly class ActorPersonalData implements CrmActorPersonalDataInterface
{
    /** Rows per batch. */
    public const BATCH = 200;

    /** What every reference to an anonymised person becomes. */
    public const PLACEHOLDER = ActorRef::ANONYMISED;

    /**
     * Wires the collaborators.
     */
    public function __construct(
        private CrmActorDataStoreInterface $store,
        private TransactionRunnerInterface $runner,
        private CrmAuditSinkInterface $audit,
        private ClockInterface $clock,
        private ?EventDispatcherInterface $events = null,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function exportActor(string $actorId): array
    {
        $this->check($actorId);
        $tenants = [];
        foreach (CrmActorDataStoreInterface::KINDS as $kind) {
            $after = null;
            do {
                $page = $this->runner->read(fn (): array => $this->store->scan($kind, $actorId, $after, self::BATCH));
                foreach ($page['rows'] as $row) {
                    $tenants[$row['tenant']][$kind][] = $row['key'];
                }
                $after = $page['next'];
            } while ($after !== null);
        }
        ksort($tenants, SORT_STRING);

        return ['actor' => $actorId, 'tenants' => $tenants];
    }

    /**
     * {@inheritDoc}
     */
    public function anonymiseActor(string $actorId): int
    {
        $this->check($actorId);
        $counts  = [];
        $failure = null;
        try {
            foreach (CrmActorDataStoreInterface::KINDS as $kind) {
                $after = null;
                do {
                    // One write unit per batch; the batch's rows are its result, so a replayed attempt counts once.
                    $page = $this->runner->write(fn (): array => $this->store->scan($kind, $actorId, $after, self::BATCH, self::PLACEHOLDER));
                    foreach ($page['rows'] as $row) {
                        $counts[$row['tenant']] = ($counts[$row['tenant']] ?? 0) + 1;
                    }
                    $after = $page['next'];
                } while ($after !== null);
            }
        } catch (Throwable $e) {
            $failure = $e;
        }
        // Even when a later batch failed, the batches already committed are audited and announced (audit VD-3), each
        // tenant on its own: one failing audit or listener never loses the others (audit VD2-5).
        [$total, $released] = $this->release($counts);
        if ($failure instanceof Throwable) {
            // The original failure is what the caller sees; a release failure is attached to the end of its chain.
            throw $released instanceof Throwable ? $this->chain($failure, $released) : $failure;
        }
        if ($released instanceof Throwable) {
            throw $released;
        }

        return $total;
    }

    /**
     * Audits and announces the committed references per tenant; their total and the first release failure.
     *
     * @param array<array-key, int> $counts tenant => references replaced and committed
     *
     * @return array{int, Throwable|null}
     */
    private function release(array $counts): array
    {
        ksort($counts, SORT_STRING);
        $now     = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $total   = 0;
        $failure = null;
        foreach ($counts as $tenant => $count) {
            $total += $count;
            try {
                $ref = new TenantRef((string) $tenant);
                $this->audit->record(new AuditEntry($ref, null, 'crm.actor.anonymised', null, null, ['references' => $count], $now));
                $this->events?->dispatch(new ActorAnonymised($ref, $now, $count));
            } catch (Throwable $e) {
                $failure ??= $e;
            }
        }

        return [$total, $failure];
    }

    /**
     * `$failure` with `$cause` attached as the previous exception of the last one in its chain.
     */
    private function chain(Throwable $failure, Throwable $cause): Throwable
    {
        $last = $failure;
        while ($last->getPrevious() instanceof Throwable) {
            $last = $last->getPrevious();
        }
        (new ReflectionProperty($last instanceof Exception ? Exception::class : Error::class, 'previous'))->setValue($last, $cause);

        return $failure;
    }

    /**
     * A usable actor id, never the placeholder itself.
     */
    private function check(string $actorId): void
    {
        if (!ExactId::isValid($actorId) || $actorId === self::PLACEHOLDER) {
            throw InvalidInputException::field('actor', 'error.invalid');
        }
    }
}
