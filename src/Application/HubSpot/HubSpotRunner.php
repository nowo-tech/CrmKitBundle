<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application\HubSpot;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Nowo\CrmKitBundle\Application\CrmContext;
use Nowo\CrmKitBundle\Application\HubSpot\Port\HubSpotReaderInterface;
use Nowo\CrmKitBundle\Application\HubSpot\Port\HubSpotStoreInterface;
use Nowo\CrmKitBundle\Application\Shared\DuplicateRowException;
use Nowo\CrmKitBundle\Application\Shared\Effects;
use Nowo\CrmKitBundle\Application\WriteSupport;
use Nowo\CrmKitBundle\Domain\Enum\ActivityKind;
use Nowo\CrmKitBundle\Domain\Enum\HubSpotObject;
use Nowo\CrmKitBundle\Domain\Enum\ImportStatus;
use Nowo\CrmKitBundle\Domain\Event\ImportCompleted;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Model\CrmRecord;
use Nowo\CrmKitBundle\Domain\Model\HubSpot\HubSpotConnection;
use Nowo\CrmKitBundle\Domain\Model\Import;
use Nowo\CrmKitBundle\Domain\Model\ImportRow;
use Nowo\CrmKitBundle\Domain\Model\Tag;
use Nowo\CrmKitBundle\Domain\Model\Tagging;
use Nowo\CrmKitBundle\Domain\Port\CrmActorDirectoryInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmActorEmailLookupInterface;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotAssociation;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotIdPage;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotOwnerPage;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotPage;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotRecord;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\EmailAddress;
use Nowo\CrmKitBundle\Domain\Value\HubSpotMapping;
use Nowo\CrmKitBundle\Domain\Value\HubSpotObjectId;
use Nowo\CrmKitBundle\Domain\Value\HubSpotToken;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

use function array_map;
use function array_values;
use function count;
use function hrtime;
use function intdiv;
use function is_array;
use function is_string;
use function json_decode;
use function method_exists;
use function min;

/**
 * Runs a HubSpot import one page at a time (0.10.0, design section 10). A step reads the import without a lock, asks
 * HubSpot outside any transaction, then settles the page in one write unit that re-checks the claim and the cursor
 * under the import row's lock (compare-and-set), writes the records, references, affiliations or taggings, the import
 * rows (kind and HubSpot id only) and the next cursor together; a page that fails commits nothing and is asked again.
 * HubSpot failures pause the import with a reason and, for a rate limit or an outage, a time; a worker waits and
 * retries a short burst limit instead, a web request never sleeps. Stateless (the token lives for one step).
 *
 * @internal
 */
final readonly class HubSpotRunner
{
    /** A step settled a page and more follow. */
    public const MORE = 'more';

    /** The import finished. */
    public const DONE = 'done';

    /** The import paused (a time, a person or a new token resumes it). */
    public const PAUSED = 'paused';

    /** The claim went to another runner. */
    public const LOST = 'lost';

    /** The import failed (disconnected, account changed, too many records). */
    public const FAILED = 'failed';

    /** Wait after an outage before the next attempt (seconds). */
    private const OUTAGE_WAIT = 60;

    /** Burst wait without `Retry-After` (seconds; HubSpot's window is ten). */
    private const BURST_WAIT = 10;

    /**
     * Wires the collaborators.
     */
    public function __construct(
        private WriteSupport $support,
        private HubSpotStoreInterface $store,
        private HubSpotReaderInterface $reader,
        private HubSpotTokenOpener $tokens,
        private HubSpotRecordWriter $writer,
        private ClockInterface $clock,
        private CrmActorDirectoryInterface $directory,
        private ?CrmActorEmailLookupInterface $emails = null,
        private ?LoggerInterface $logger = null,
        private int $pageSize = 100,
        private int $maxRecords = 50000,
        private int $maxAttempts = 5,
        private int $maxRetryAfter = 60,
    ) {
    }

    /**
     * One page of the import whose claim `$token` holds, as `$actor` (attended: a person in a web request, who never
     * waits; unattended: the resume command or the background handler, which waits out short rate limits).
     */
    public function step(TenantRef $tenant, string $id, string $token, string $actor, bool $attended): string
    {
        $now    = $this->now();
        $import = $this->support->read(fn (): ?Import => $this->support->store->import($tenant, $id));
        if (!$import instanceof Import || $import->getStatus() !== ImportStatus::Running || $import->getClaimToken() !== $token) {
            return self::LOST;
        }
        $state      = HubSpotRunState::fromArray($import->getCursor());
        $connection = $this->support->read(fn (): ?HubSpotConnection => $this->store->connection($tenant));
        if (!$connection instanceof HubSpotConnection || $connection->getId() !== $import->getConnectionId()) {
            return $this->fail($id, $now, 'hubspot_disconnected');
        }
        if ($connection->getAccountId()->value !== $state->account) {
            return $this->fail($id, $now, 'hubspot_account_changed');
        }
        $secret = $connection->isActive() ? $this->tokens->open($tenant, $connection, $now) : null;
        if (!$secret instanceof HubSpotToken) {
            return $this->pause($id, $token, $now, null, HubSpotException::TOKEN_INVALID);
        }
        $context = new CrmContext($tenant, new ActorRef($actor), $now);
        $object  = $state->object();
        $started = hrtime(true);

        try {
            $result = match (true) {
                $object instanceof HubSpotObject          => $this->records($context, $import, $state, $object, $secret, $token, $attended),
                $state->stage === HubSpotRunState::OWNERS => $this->owners($context, $import, $state, $secret, $token, $attended),
                default                                   => $this->lists($context, $import, $state, $secret, $token, $attended),
            };
        } catch (HubSpotException $e) {
            $result = $this->failure($tenant, $connection, $id, $token, $e, $now);
        }
        $this->logger?->info('nowo_crm_kit: HubSpot import page', ['import' => $id, 'stage' => $state->stage, 'page' => $state->page, 'result' => $result, 'ms' => intdiv(hrtime(true) - $started, 1000000)]);

        return $result;
    }

    /**
     * A page of companies or contacts (with the contacts' company associations).
     *
     * @throws HubSpotException
     */
    private function records(CrmContext $context, Import $import, HubSpotRunState $state, HubSpotObject $object, HubSpotToken $secret, string $token, bool $attended): string
    {
        $mapping = HubSpotMapping::fromFlat($import->getMapping());
        /** @var HubSpotPage $page */
        $page = $this->ask(fn (): HubSpotPage => $this->reader->records($secret, $object, $mapping->requested($object), $state->cursor(), $this->pageSize), $attended);
        if (($state->seen[$object->value] ?? 0) + count($page->records) > $this->maxRecords) {
            return $this->fail($import->getId(), $context->now, 'hubspot_too_many');
        }
        $associations = [];
        if ($object === HubSpotObject::Contact && $page->records !== []) {
            $ids = array_map(static fn (HubSpotRecord $r): HubSpotObjectId => $r->id, $page->records);
            /** @var list<HubSpotAssociation> $associations */
            $associations = $this->ask(fn (): array => $this->reader->associations($secret, $ids), $attended);
        }

        return $this->unit($context, $import, $state, $token, function (Effects $effects, int $rowsDone) use ($context, $import, $state, $object, $page, $associations, $mapping, $attended): array { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $settled = $this->writer->settle($context, $state->account, $object, $page->records, $associations, $mapping->of($object), $state->owners, $import->getDuplicatePolicy(), $import->getId(), $attended);
            $counts  = [];
            foreach ($settled['rows'] as $i => [$outcome, $recordId, $message, $hid]) {
                $row = new ImportRow($this->support->id(), $import->getId(), $context->tenant->value, $rowsDone + $i + 1, ['object' => $object->refType(), 'id' => $hid], $object->subjectType());
                $row->settle($outcome, $recordId, $message);
                $this->support->store->add($row); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
                $counts[$outcome->value] = ($counts[$outcome->value] ?? 0) + 1;
            }

            return [$state->advance($page->next, count($page->records), $settled['skipped']), count($settled['rows']), $counts];
        });
    }

    /**
     * A page of owners: their e-mails become the owner map (HubSpot owner id ⇒ actor id).
     *
     * @throws HubSpotException
     */
    private function owners(CrmContext $context, Import $import, HubSpotRunState $state, HubSpotToken $secret, string $token, bool $attended): string
    {
        /** @var HubSpotOwnerPage $page */
        $page   = $this->ask(fn (): HubSpotOwnerPage => $this->reader->owners($secret, $state->cursor(), $this->pageSize), $attended);
        $emails = [];
        foreach ($page->owners as $owner) {
            if ($owner->email instanceof EmailAddress) {
                $emails[$owner->email->key()] = $owner->email;
            }
        }
        $actors = $this->actors($context->tenant, array_values($emails));
        $map    = [];
        foreach ($page->owners as $owner) {
            $actor = $owner->email instanceof EmailAddress ? ($actors[$owner->email->key()] ?? null) : null;
            if ($actor instanceof ActorRef) {
                $map['o' . $owner->id->value] = $actor->value;
            }
        }

        return $this->unit($context, $import, $state, $token, static fn (): array => [$state->advance($page->next, owners: $map), 0, []]);
    }

    /**
     * A page of a list's members: those with a live record of the same account get the list's tag (add only).
     *
     * @throws HubSpotException
     */
    private function lists(CrmContext $context, Import $import, HubSpotRunState $state, HubSpotToken $secret, string $token, bool $attended): string
    {
        $list   = $state->currentList();
        $tag    = $list === null ? null : (HubSpotMapping::fromFlat($import->getMapping())->lists[$list['id']] ?? null);
        $object = $list === null ? null : HubSpotObject::from($list['object']);
        if ($list === null || $tag === null || !$object instanceof HubSpotObject) {
            // A list left out of the mapping (or none left): nothing to read.
            return $this->unit($context, $import, $state, $token, static fn (): array => [$state->advance(null), 0, []]);
        }
        /** @var HubSpotIdPage $page */
        $page = $this->ask(fn (): HubSpotIdPage => $this->reader->members($secret, new HubSpotObjectId($list['id']), $state->cursor(), $this->pageSize), $attended);

        return $this->unit($context, $import, $state, $token, function () use ($context, $state, $page, $object, $tag): array { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $tagged = 0;
            $store  = $this->support->store;
            if ($store->tag($context->tenant, $tag) instanceof Tag) {
                $refs    = $this->store->refs($context->tenant, $state->account, $object->refType(), array_map(static fn (HubSpotObjectId $i): string => $i->value, $page->ids));
                $targets = [];
                foreach ($refs as $ref) {
                    if (!$ref->isErased()) {
                        $targets[] = $ref->getTargetId();
                    }
                }
                $type = $object->subjectType();
                $have = [];
                foreach ($store->taggings($context->tenant, $type, $targets) as $tagging) {
                    $have[$tagging->getSubjectId() . '|' . $tagging->getTagId()] = true;
                }
                foreach ($store->records($type, $context->tenant, $targets) as $record) {
                    if ($record instanceof CrmRecord && $record->isActive() && !isset($have[$record->getId() . '|' . $tag])) {
                        $store->add(new Tagging($this->support->id(), $context->tenant->value, $tag, $type, $record->getId(), $context->now)); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
                        $this->support->activity($context, $type, $record->getId(), ActivityKind::Tagged, ['count' => 1]);
                        ++$tagged;
                    }
                }
            }

            return [$state->advance($page->next, tagged: $tagged), 0, []];
        });
    }

    /**
     * Settles a page in one write unit: the claim and the cursor are re-checked under the import row's lock, then the
     * page's work, the import rows, the counters and the next cursor commit together (a racing unique key replays it
     * once). The last page finishes the import in the same unit.
     *
     * @param callable(Effects, int): array{HubSpotRunState, int, array<string, int>} $work next cursor, rows, counts
     */
    private function unit(CrmContext $context, Import $import, HubSpotRunState $state, string $token, callable $work): string
    {
        $run = function (Effects $effects) use ($context, $import, $state, $token, $work): string { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $locked = $this->store->lockCursor($import->getId(), $token);
            if ($locked === null || !is_string($locked['cursor']) || HubSpotRunState::fromArray($this->decode($locked['cursor']))->json() !== $state->json()) {
                return self::LOST;
            }
            [$next, $rows, $counts] = $work($effects, $locked['rows']);
            $now                    = $this->now();
            $this->store->saveCursor($import->getId(), $next->json());
            $this->support->store->advanceImport($import->getId(), $rows, $counts, $now);
            if (!$next->isDone()) {
                return self::MORE;
            }
            $this->support->store->finishImport($import->getId(), ImportStatus::Done, $now, ImportStatus::Running);
            $final = $this->support->store->import($context->tenant, $import->getId())?->counts() ?? [];
            $effects->event(new ImportCompleted($context->tenant, $context->actor, $now, $import->getSubjectType(), $import->getId(), $final));
            $this->support->audit($effects, $context, 'crm.import.completed', $import->getSubjectType(), $import->getId(), $final + ['associations_skipped' => $next->skipped, 'tagged' => $next->tagged]);

            return self::DONE;
        };

        try {
            return $this->support->write($run);
        } catch (DuplicateRowException) {
            // Another writer stored the same reference, tagging or affiliation first: the page settles again, where it
            // now finds them.
            try {
                return $this->support->write($run);
            } catch (DuplicateRowException $e) {
                throw new ConflictException(ConflictException::WRITE_CONFLICT, null, $e);
            }
        }
    }

    /**
     * Asks HubSpot; an unattended run waits out a burst limit or an outage (`Retry-After`, else 1, 2, 4… s) up to
     * `max_attempts`, a web request never waits.
     *
     * @template T
     *
     * @param callable(): T $call
     *
     * @throws HubSpotException
     *
     * @return T
     */
    private function ask(callable $call, bool $attended): mixed
    {
        for ($attempt = 1;; ++$attempt) {
            try {
                return $call();
            } catch (HubSpotException $e) {
                $wait = $e->retryAfter ?? (1 << min(5, $attempt - 1));
                // A clock that cannot wait (a plain PSR clock) never retries: the import pauses instead.
                if ($attended || !$e->isTransient() || $attempt >= $this->maxAttempts || $wait > $this->maxRetryAfter || !method_exists($this->clock, 'sleep')) {
                    throw $e;
                }
                $this->sleep($wait);
            }
        }
    }

    /**
     * What a HubSpot failure does to the import: a pause with a reason (and for a rate limit or an outage, a time);
     * a refused token also marks the connection invalid.
     */
    private function failure(TenantRef $tenant, HubSpotConnection $connection, string $id, string $token, HubSpotException $e, DateTimeImmutable $now): string
    {
        $until = match (true) {
            $e->errorCode === HubSpotException::RATE_LIMITED && $e->daily => $now->setTime(0, 0)->add(new DateInterval('P1DT1M')),
            $e->errorCode === HubSpotException::RATE_LIMITED              => $now->add(new DateInterval('PT' . ($e->retryAfter ?? self::BURST_WAIT) . 'S')),
            $e->errorCode === HubSpotException::UNAVAILABLE               => $now->add(new DateInterval('PT' . self::OUTAGE_WAIT . 'S')),
            default                                                       => null,
        };
        if ($e->errorCode === HubSpotException::TOKEN_INVALID) {
            $this->support->write(function () use ($tenant, $connection, $now): void { // @igor-ignore - the runner calls this closure at once and keeps nothing
                $this->store->invalidate($tenant, $connection->getId(), $now);
            });
        }

        return $this->pause($id, $token, $now, $until, $e->errorCode);
    }

    /**
     * Releases the claim with the reason and the time it may run again.
     */
    private function pause(string $id, string $token, DateTimeImmutable $now, ?DateTimeImmutable $until, string $code): string
    {
        $this->support->write(fn (): bool => $this->support->store->pauseImportUntil($id, $token, $now, $until, $code));

        return self::PAUSED;
    }

    /**
     * Ends the import as failed.
     */
    private function fail(string $id, DateTimeImmutable $now, string $code): string
    {
        $this->support->write(function () use ($id, $now, $code): void { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->support->store->failImport($id, $now, $code);
        });

        return self::FAILED;
    }

    /**
     * The actors of the owners' e-mails: the host's lookup, else the directory's owners filtered by e-mail; only keys
     * that were asked for, compared with `===` (REQ-SEC-006 rule 4).
     *
     * @param list<EmailAddress> $emails
     *
     * @return array<string, ActorRef>
     */
    private function actors(TenantRef $tenant, array $emails): array
    {
        if ($emails === []) {
            return [];
        }
        $wanted = [];
        foreach ($emails as $email) {
            $wanted[$email->key()] = true;
        }
        $found = [];
        if ($this->emails instanceof CrmActorEmailLookupInterface) {
            foreach ($this->emails->actorsByEmails($tenant, $emails) as $key => $actor) {
                if (isset($wanted[(string) $key]) && $actor instanceof ActorRef && !ActorRef::isReserved($actor->value)) {
                    $found[(string) $key] = $actor;
                }
            }

            return $found;
        }
        foreach ($this->directory->owners($tenant) as $profile) {
            $key = $profile->email === null ? null : EmailAddress::lookupKey($profile->email);
            if ($key !== null && isset($wanted[$key]) && !ActorRef::isReserved($profile->ref->value)) {
                $found[$key] = $profile->ref;
            }
        }

        return $found;
    }

    /**
     * Waits through the clock's `sleep()` (Symfony's clocks; a mock clock in tests only moves its time).
     */
    private function sleep(int $seconds): void
    {
        if (method_exists($this->clock, 'sleep')) {
            $this->clock->sleep($seconds);
        }
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * A stored cursor's JSON.
     *
     * @return array<array-key, mixed>|null
     */
    private function decode(string $json): ?array
    {
        $data = json_decode($json, true, 32);

        return is_array($data) ? $data : null;
    }
}
