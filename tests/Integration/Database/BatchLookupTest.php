<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Application\RecordPurger;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Event\ContactErased;
use Nowo\CrmKitBundle\Domain\Event\CrmEvent;
use Nowo\CrmKitBundle\Domain\Event\HostLinkRemoved;
use Nowo\CrmKitBundle\Domain\Event\RecordsMerged;
use Nowo\CrmKitBundle\Domain\Event\RecordsPurged;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Read\RolesAndTags;
use Nowo\CrmKitBundle\Domain\Service\CrmBatchLookupInterface;
use Nowo\CrmKitBundle\Domain\Service\CrmPersonalDataInterface;
use Nowo\CrmKitBundle\Domain\Service\CrmReaderInterface;
use Nowo\CrmKitBundle\Domain\Service\HostLinkServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\MergeServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\RecordInput;
use Nowo\CrmKitBundle\Domain\Service\TagServiceInterface;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\HostObjectRef;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Event\CrmKitEvents;
use Nowo\CrmKitBundle\Tests\Fixtures\QueryLog;
use ReflectionObject;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function array_fill;
use function array_filter;
use function array_keys;
use function array_map;
use function array_slice;
use function array_values;
use function get_debug_type;
use function is_string;
use function range;
use function sort;
use function sprintf;

/**
 * Batch lookups for host jobs (0.2.1): one query per call (per chunk of 500), tenant isolation, the stored
 * normalisation, primary affiliation first, trashed / merged / erased records left out; and the lifecycle events the
 * jobs listen to, dispatched after the commit only, with ids in their payloads.
 */
final class BatchLookupTest extends CrmTestCase
{
    public function testEveryLookupIsOneQueryScopedToTheTenantWithActiveRecordsOnly(): void
    {
        $this->boot(['roles' => ['catalogue' => ['customer', 'supplier', 'partner']]]);
        $this->everything('acme');
        $links = $this->service(HostLinkServiceInterface::class);
        $tags  = $this->service(TagServiceInterface::class);

        // Companies: two share acme.test (one through www.), one is trashed, one has another domain.
        $acme    = $this->records()->create(new RecordInput(SubjectType::Company, ['name' => 'Acme', 'website' => 'https://www.Acme.test/about'], 'leo'));
        $acme2   = $this->company('Acme Labs', ['website' => 'http://acme.test']);
        $trashed = $this->company('Old Acme', ['website' => 'https://acme.test/old']);
        $beta    = $this->company('Beta', ['website' => 'https://beta.test']);
        // Contacts: active ones, a trashed one, a merged tombstone and an erased one.
        $ada = $this->contact('Ada', 'Lovelace', 'Ada@Acme.test');
        $bob = $this->contact('Bob', 'Byte', 'bob@x.test');
        $tom = $this->contact('Tom', 'Trash', 'tom@x.test');
        $dup = $this->contact('Bob', 'Dup', 'dup@x.test');
        $eve = $this->contact('Eve', 'Gone', 'eve@x.test');

        $this->records()->affiliate($ada, $trashed, null, false);
        $this->clock()->sleep(1);
        $this->records()->affiliate($ada, $beta, null, false);
        $this->clock()->sleep(1);
        $this->records()->affiliate($ada, $acme2, null, false);
        $this->clock()->sleep(1);
        $this->records()->affiliate($ada, $acme, 'CTO', true);
        $this->records()->affiliate($bob, $beta, null, true);
        $this->records()->affiliate($tom, $beta, null, true);

        $issue1 = new HostObjectRef('issue', '1');
        $issue2 = new HostObjectRef('issue', 'a:b');
        $links->link(SubjectType::Contact, $ada, $issue1);
        $links->link(SubjectType::Company, $acme, $issue1);
        $links->link(SubjectType::Contact, $tom, $issue1);
        $links->link(SubjectType::Company, $trashed, $issue1);
        $links->link(SubjectType::Contact, $bob, $issue2);
        $links->link(SubjectType::Contact, $eve, $issue2);

        $this->records()->setRoles(SubjectType::Company, $acme, ['supplier', 'customer']);
        $this->records()->setRoles(SubjectType::Contact, $ada, ['partner']);
        $vip   = $tags->create('VIP', 'red');
        $beta2 = $tags->create('Beta list', 'blue');
        $tags->assign(SubjectType::Company, $acme, [$vip, $beta2]);
        // A role no longer configured stays in the table and is left out.
        $this->connection()->insert('nowo_crm_role_assignment', ['id' => '01900000-0000-7000-8000-00000000aaaa', 'tenant_id' => 'acme', 'subject_type' => 'company', 'subject_id' => $acme, 'role_key' => 'legacy', 'created_at' => '2026-11-18 10:00:00']);

        $reader = $this->service(CrmReaderInterface::class);
        $this->records()->trash(SubjectType::Company, $trashed, $reader->record(SubjectType::Company, $trashed)->version);
        $this->records()->trash(SubjectType::Contact, $tom, $reader->record(SubjectType::Contact, $tom)->version);
        $this->service(MergeServiceInterface::class)->merge(SubjectType::Contact, $bob, $dup, [], $reader->record(SubjectType::Contact, $bob)->version, $reader->record(SubjectType::Contact, $dup)->version);
        $this->service(CrmPersonalDataInterface::class)->erase($eve);

        // Another tenant with the same keys and host objects.
        $this->ctx()->tenant = 'globex';
        $this->everything('globex');
        $gAda  = $this->contact('Ada', 'Twin', 'ada@acme.test');
        $gAcme = $this->company('Globex Acme', ['website' => 'https://acme.test']);
        $this->records()->affiliate($gAda, $gAcme, null, true);
        $links->link(SubjectType::Contact, $gAda, $issue1);
        $this->ctx()->tenant = 'acme';
        $this->ctx()->actor  = null; // a backend job: no actor, no grants

        $lookup  = $this->service(CrmBatchLookupInterface::class);
        $tenant  = new TenantRef('acme');
        $counter = $this->queries();
        $counter->take();

        self::assertSame(
            ['ada@acme.test' => [$ada], 'bob@x.test' => [$bob], 'tom@x.test' => [], 'dup@x.test' => [], 'eve@x.test' => [], 'nobody@x.test' => []],
            $lookup->contactsByEmails($tenant, [' ADA@acme.TEST ', 'bob@x.test', 'tom@x.test', 'dup@x.test', 'eve@x.test', 'nobody@x.test', 'Ada@Acme.test', '']),
        );
        self::assertCount(1, $counter->take(), 'one query');

        $expected = [$acme, $acme2];
        sort($expected);
        self::assertSame(
            ['acme.test' => $expected, 'beta.test' => [$beta], 'gamma.test' => []],
            $lookup->companiesByDomains($tenant, ['www.ACME.test', 'https://acme.test/contact', 'beta.test', 'gamma.test', '42', 'not a domain']),
        );
        self::assertCount(1, $counter->take(), 'one query');

        $linked = $lookup->subjectsLinkedToMany($tenant, [$issue1, $issue2, new HostObjectRef('issue', '3'), new HostObjectRef('project', '1')]);
        self::assertSame(['issue:1', 'issue:a:b', 'issue:3', 'project:1'], array_keys($linked));
        self::assertSame(['contact:' . $ada, 'company:' . $acme], array_map(static fn (SubjectRef $s): string => $s->key(), $linked['issue:1']), 'active only, link order, this tenant');
        self::assertNull($linked['issue:1'][0]->owner);
        self::assertSame('leo', $linked['issue:1'][1]->owner?->value);
        self::assertSame(['contact:' . $bob], array_map(static fn (SubjectRef $s): string => $s->key(), $linked['issue:a:b']));
        self::assertSame([[], []], [$linked['issue:3'], $linked['project:1']]);
        self::assertCount(1, $counter->take(), 'one query');

        self::assertSame(
            [$ada => [$acme, $beta, $acme2], $bob => [$beta], $tom => [], $gAda => []],
            $lookup->affiliationsOf($tenant, [$ada, $bob, $tom, $gAda, 'not-an-id', $ada]),
            'primary first, then by affiliation date (beta before acme2 although its id is higher); trashed companies and contacts and other tenants left out',
        );
        self::assertCount(1, $counter->take(), 'one query');

        $both = $lookup->rolesAndTagsOf($tenant, [SubjectRef::of(SubjectType::Company, $acme), SubjectRef::of(SubjectType::Contact, $ada), SubjectRef::of(SubjectType::Contact, $acme), SubjectRef::of(SubjectType::Contact, $gAda)]);
        self::assertEquals([
            'company:' . $acme => new RolesAndTags(['customer', 'supplier'], ['beta list', 'vip']),
            'contact:' . $ada  => new RolesAndTags(['partner'], []),
            'contact:' . $acme => new RolesAndTags(),
            'contact:' . $gAda => new RolesAndTags(),
        ], $both);
        self::assertCount(1, $counter->take(), 'one query (UNION ALL)');

        // Rows still present, any status (active, trashed, tombstone, erased); unknown and other tenants' left out.
        $asked   = [SubjectRef::of(SubjectType::Contact, $eve), SubjectRef::of(SubjectType::Company, $acme, 'leo'), SubjectRef::of(SubjectType::Company, $trashed), SubjectRef::of(SubjectType::Contact, $dup), SubjectRef::of(SubjectType::Contact, $tom), SubjectRef::of(SubjectType::Contact, $gAda), SubjectRef::of(SubjectType::Contact, $acme), SubjectRef::of(SubjectType::Company, '01900000-0000-7000-8000-00000000ffff'), SubjectRef::of(SubjectType::Company, $acme)];
        $present = $lookup->existing($tenant, $asked);
        self::assertSame(['contact:' . $eve, 'company:' . $acme, 'company:' . $trashed, 'contact:' . $dup, 'contact:' . $tom], array_map(static fn (SubjectRef $s): string => $s->key(), $present), 'input order, first occurrence');
        self::assertSame($asked[1], $present[1], 'the input objects as given');
        self::assertCount(1, $counter->take(), 'one query (UNION ALL of both types)');
        self::assertSame([], $lookup->existing($tenant, [SubjectRef::of(SubjectType::Contact, $acme)]));
        self::assertCount(1, $counter->take(), 'one type: one SELECT');

        // The other tenant sees its own rows only.
        $globex = new TenantRef('globex');
        self::assertSame(['ada@acme.test' => [$gAda]], $lookup->contactsByEmails($globex, ['ada@acme.test']));
        self::assertSame(['acme.test' => [$gAcme]], $lookup->companiesByDomains($globex, ['acme.test']));
        self::assertSame(['contact:' . $gAda], array_map(static fn (SubjectRef $s): string => $s->key(), $lookup->subjectsLinkedToMany($globex, [$issue1])['issue:1']));
        self::assertSame([$gAda => [$gAcme], $ada => []], $lookup->affiliationsOf($globex, [$gAda, $ada]));
        // Exact tenant: a look-alike tenant id finds nothing.
        self::assertSame(['ada@acme.test' => []], $lookup->contactsByEmails(new TenantRef('Acme'), ['ada@acme.test']));
        $counter->take();

        // Nothing usable: no query at all.
        self::assertSame([], $lookup->contactsByEmails($tenant, ['', '   ']));
        self::assertSame([], $lookup->companiesByDomains($tenant, ['']));
        self::assertSame([], $lookup->subjectsLinkedToMany($tenant, []));
        self::assertSame([], $lookup->affiliationsOf($tenant, ['x']));
        self::assertSame([], $lookup->rolesAndTagsOf($tenant, []));
        self::assertSame([], $lookup->existing($tenant, []));
        self::assertSame([], $counter->take());
    }

    public function testLongListsRunOneQueryPerChunk(): void
    {
        $this->boot();
        $this->everything('acme');
        $ada                = $this->contact('Ada', 'Lovelace', 'ada@x.test');
        $emails             = array_map(static fn (int $i): string => sprintf('user%d@x.test', $i), range(1, 1200));
        $emails[]           = 'ada@x.test';
        $ids                = array_map(static fn (int $i): string => sprintf('01900000-0000-7000-8000-%012d', $i), range(1, 1001));
        $hosts              = array_map(static fn (int $i): HostObjectRef => new HostObjectRef('issue', (string) $i), range(1, 501));
        $this->ctx()->actor = null;
        $lookup             = $this->service(CrmBatchLookupInterface::class);
        $tenant             = new TenantRef('acme');
        $counter            = $this->queries();
        $counter->take();

        $found = $lookup->contactsByEmails($tenant, $emails);
        self::assertCount(1201, $found);
        self::assertSame([$ada], $found['ada@x.test']);
        self::assertCount(3, $counter->take(), '1201 addresses: 3 chunks of at most 500');
        self::assertSame(array_fill(0, 1001, []), array_values($lookup->affiliationsOf($tenant, $ids)));
        self::assertCount(3, $counter->take());
        self::assertCount(501, $lookup->subjectsLinkedToMany($tenant, $hosts));
        self::assertCount(2, $counter->take());
        self::assertCount(501, $lookup->rolesAndTagsOf($tenant, array_map(static fn (string $id): SubjectRef => SubjectRef::of(SubjectType::Contact, $id), array_slice($ids, 0, 501))));
        self::assertCount(2, $counter->take());
        self::assertSame([], $lookup->existing($tenant, array_map(static fn (string $id): SubjectRef => SubjectRef::of(SubjectType::Company, $id), $ids)));
        self::assertCount(3, $counter->take(), '1001 subjects: 3 chunks');
    }

    public function testTheRetentionPurgeAnnouncesEveryCommittedBatchWithIdsOnly(): void
    {
        $this->boot();
        $this->everything('acme');
        $kept      = $this->contact('Kept', 'Active', 'kept@x.test');
        $companies = $this->oldTrashed(SubjectType::Company, 1, 0);
        $contacts  = $this->oldTrashed(SubjectType::Contact, RecordPurger::BATCH + 1, 100);
        $this->oldTrashed(SubjectType::Contact, 1, 900, 'globex');
        $seen = new class {
            /** @var list<RecordsPurged> */
            public array $events = [];

            /** @var list<bool> */
            public array $inTransaction = [];

            public int $count = 0;
        };
        $dispatcher = $this->kernel->getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(CrmKitEvents::RECORDS_PURGED, function (RecordsPurged $event) use ($seen): void {
            ++$seen->count;
            $seen->events[]        = $event;
            $seen->inTransaction[] = $this->connection()->isTransactionActive();
        });
        $purger = $this->service(RecordPurger::class);
        $acme   = new TenantRef('acme');

        // A batch that fails inside its transaction: rolled back, nothing announced, nothing deleted.
        $this->ctx()->failOnRemove = true;
        try {
            $purger->purge($acme);
            self::fail('injected failure');
        } catch (RuntimeException) {
        }
        self::assertSame(0, $seen->count, 'no event from a rolled-back batch');
        self::assertNotContains('RecordsPurged', $this->recorder()->eventNames());
        self::assertSame(203, $this->int("SELECT COUNT(*) FROM nowo_crm_contact WHERE tenant_id = 'acme'") + $this->int("SELECT COUNT(*) FROM nowo_crm_company WHERE tenant_id = 'acme'"));

        self::assertSame(['records' => 202, 'tombstones' => 0, 'activities' => 0, 'import_rows' => 0, 'merges' => 0, 'deals' => 0, 'engagements' => 0], $purger->purge($acme));
        self::assertCount(3, $seen->events, 'one event per committed batch: companies, then contacts in batches of 200');
        self::assertSame([false, false, false], $seen->inTransaction, 'dispatched after the commit');
        $keys = array_map(static fn (RecordsPurged $e): array => array_map(static fn (SubjectRef $s): string => $s->key(), $e->subjects), $seen->events);
        self::assertSame([array_map(static fn (string $id): string => 'company:' . $id, $companies), array_map(static fn (string $id): string => 'contact:' . $id, array_slice($contacts, 0, RecordPurger::BATCH)), ['contact:' . $contacts[RecordPurger::BATCH]]], $keys);
        foreach ($seen->events as $event) {
            self::assertSame('acme', $event->tenant->value);
            self::assertNull($event->actor, 'a scheduled job: no actor');
            foreach ($event->subjects as $subject) {
                self::assertNull($subject->owner, 'ids only');
            }
        }
        self::assertEquals([SubjectRef::of(SubjectType::Contact, $kept)], $this->service(CrmBatchLookupInterface::class)->existing($acme, [SubjectRef::of(SubjectType::Contact, $kept), SubjectRef::of(SubjectType::Contact, $contacts[0]), SubjectRef::of(SubjectType::Company, $companies[0])]));
        self::assertSame(1, $this->int("SELECT COUNT(*) FROM nowo_crm_contact WHERE tenant_id = 'globex'"), 'another tenant untouched');
        self::assertNotContains('RecordPurged', $this->recorder()->eventNames(), 'the per-record event stays for manual purges');
    }

    /**
     * Stores `$count` records trashed long ago (direct rows: fast), ids ascending from `$from`.
     *
     * @return list<string>
     */
    private function oldTrashed(SubjectType $type, int $count, int $from, string $tenant = 'acme'): array
    {
        $ids = [];
        for ($i = $from + 1; $i <= $from + $count; ++$i) {
            $id    = sprintf('01900000-0000-7000-8000-%012d', $i);
            $ids[] = $id;
            $row   = ['id' => $id, 'tenant_id' => $tenant, 'custom' => '{}', 'status' => 'trashed', 'version' => 1, 'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-01-01 10:00:00', 'trashed_at' => '2026-01-01 10:00:00'];
            $this->connection()->insert(
                $type === SubjectType::Company ? 'nowo_crm_company' : 'nowo_crm_contact',
                $type === SubjectType::Company ? $row + ['name' => 'Old ' . $i, 'name_key' => 'old ' . $i] : $row + ['last_name' => 'Old', 'display_name' => 'Old', 'sort_key' => 'old'],
            );
        }

        return $ids;
    }

    public function testLifecycleEventsAreDispatchedAfterTheCommitWithIdsOnly(): void
    {
        $this->boot();
        $this->everything('acme');
        $seen = new class {
            /** @var list<CrmEvent> */
            public array $events = [];

            /** @var list<bool> whether a transaction was open at dispatch */
            public array $inTransaction = [];

            public int $count = 0;
        };
        $dispatcher = $this->kernel->getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        foreach ([CrmKitEvents::CONTACT_ERASED, CrmKitEvents::RECORDS_MERGED, CrmKitEvents::HOST_LINK_REMOVED] as $name) {
            $dispatcher->addListener($name, function (CrmEvent $event) use ($seen): void {
                ++$seen->count;
                $seen->events[]        = $event;
                $seen->inTransaction[] = $this->connection()->isTransactionActive();
            });
        }
        $reader = $this->service(CrmReaderInterface::class);
        $merges = $this->service(MergeServiceInterface::class);
        $ada    = $this->contact('Ada', 'Lovelace', 'ada@x.test');
        $dup    = $this->contact('Ada', 'Dup', 'dup@x.test');
        $eve    = $this->contact('Eve', 'Gone', 'eve@x.test');
        $link   = $this->service(HostLinkServiceInterface::class)->link(SubjectType::Contact, $ada, new HostObjectRef('issue', '7'));

        // Rolled back (another writer moved the version): nothing is announced, nothing changed.
        $this->ctx()->bumpVersions = true;
        try {
            $this->service(CrmPersonalDataInterface::class)->erase($eve);
            self::fail('stale');
        } catch (ConflictException) {
        }
        $this->ctx()->bumpVersions = true;
        try {
            $merges->merge(SubjectType::Contact, $ada, $dup, [], $reader->record(SubjectType::Contact, $ada)->version, $reader->record(SubjectType::Contact, $dup)->version);
            self::fail('stale');
        } catch (ConflictException) {
        }
        $this->ctx()->bumpVersions = false;
        self::assertSame(0, $seen->count, 'no event from a rolled-back unit');
        self::assertNotContains('ContactErased', $this->recorder()->eventNames());
        self::assertNotContains('RecordsMerged', $this->recorder()->eventNames());
        self::assertSame(['eve@x.test' => [$eve], 'dup@x.test' => [$dup]], $this->service(CrmBatchLookupInterface::class)->contactsByEmails(new TenantRef('acme'), ['eve@x.test', 'dup@x.test']));

        $this->service(HostLinkServiceInterface::class)->unlink($link);
        $merges->merge(SubjectType::Contact, $ada, $dup, [], $reader->record(SubjectType::Contact, $ada)->version, $reader->record(SubjectType::Contact, $dup)->version);
        $this->service(CrmPersonalDataInterface::class)->erase($eve);

        self::assertCount(3, $seen->events);
        self::assertSame([false, false, false], $seen->inTransaction, 'dispatched after the commit');
        [$removed, $merged, $erased] = $seen->events;
        self::assertInstanceOf(HostLinkRemoved::class, $removed);
        self::assertSame([SubjectType::Contact, $ada, 'issue', '7'], [$removed->type, $removed->id, $removed->hostType, $removed->hostId]);
        self::assertInstanceOf(RecordsMerged::class, $merged);
        self::assertSame([SubjectType::Contact, $ada, $dup], [$merged->type, $merged->survivorId, $merged->mergedId]);
        self::assertInstanceOf(ContactErased::class, $erased);
        self::assertSame($eve, $erased->contactId);
        foreach ([$removed, $merged, $erased] as $event) {
            self::assertSame('acme', $event->tenant->value);
            self::assertSame('ana', $event->actor?->value);
            foreach ((new ReflectionObject($event))->getProperties() as $property) {
                $value = $property->getValue($event);
                self::assertTrue(is_string($value) || $value instanceof SubjectType || $value instanceof TenantRef || $value instanceof ActorRef || $value instanceof DateTimeImmutable, $property->getName() . ' is an id or a VO, not ' . get_debug_type($value));
            }
        }
        self::assertCount(1, array_filter($this->recorder()->events, static fn (CrmEvent $e): bool => $e instanceof ContactErased), 'once');
    }

    /**
     * Every permission for `ana` in the tenant (the set-up writes go through the checked services).
     */
    private function everything(string $tenant): void
    {
        $this->ctx()->actor = 'ana';
        $this->ctx()->grant($tenant, 'ana', 'view', 'create', 'edit', 'delete', 'manage', 'erase', 'export', 'import');
    }

    private function queries(): QueryLog
    {
        $counter = $this->kernel->getContainer()->get('test.queries');
        self::assertInstanceOf(QueryLog::class, $counter);

        return $counter;
    }
}
