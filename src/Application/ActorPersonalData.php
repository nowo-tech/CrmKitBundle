<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application;

use DateTimeZone;
use Nowo\CrmKitBundle\Application\Port\CrmActorDataStoreInterface;
use Nowo\CrmKitBundle\Application\Shared\TransactionRunnerInterface;
use Nowo\CrmKitBundle\Domain\Event\ActorAnonymised;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Port\CrmAuditSinkInterface;
use Nowo\CrmKitBundle\Domain\Value\AuditEntry;
use Nowo\CrmKitBundle\Domain\Value\ExactId;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

use function ksort;

use const SORT_STRING;

/**
 * Export and anonymisation of a host person across every tenant (0.2.1, contract section 16.5): every CRM reference
 * to their actor id as owner, note author, created_by / updated_by, timeline actor, consent recorder, import uploader,
 * merge actor and owner inside timeline and merge snapshots. A SYSTEM API: no tenant, no actor, no permission check.
 * Anonymisation runs in batches of {@see self::BATCH} rows, one write unit each (a lock conflict replays the batch or
 * answers 409 through the runner); events and audit entries are released after the last commit, per tenant. Records
 * and everyone else's data stay; running it again changes nothing. Stateless.
 *
 * @internal
 */
final readonly class ActorPersonalData
{
    /** Rows per batch. */
    public const BATCH = 200;

    /** What every reference to an anonymised person becomes. */
    public const PLACEHOLDER = 'anonymised';

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
     * Every reference to the person: tenant ⇒ kind ⇒ row keys (record, note, timeline entry… ids).
     *
     * @return array{actor: string, tenants: array<string, array<string, list<string>>>}
     */
    public function export(string $actorId): array
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
     * Replaces every reference to the person by {@see self::PLACEHOLDER}; the references replaced (0 when run again).
     */
    public function anonymise(string $actorId): int
    {
        $this->check($actorId);
        $counts = [];
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
        ksort($counts, SORT_STRING);
        $now   = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $total = 0;
        foreach ($counts as $tenant => $count) {
            $ref = new TenantRef((string) $tenant);
            $this->audit->record(new AuditEntry($ref, null, 'crm.actor.anonymised', null, null, ['references' => $count], $now));
            $this->events?->dispatch(new ActorAnonymised($ref, $now, $count));
            $total += $count;
        }

        return $total;
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
