<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use Nowo\CrmKitBundle\Application\CrmReader;
use Nowo\CrmKitBundle\Domain\Enum\RecordStatus;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Read\LinkView;
use Nowo\CrmKitBundle\Domain\Read\RecordView;
use Nowo\CrmKitBundle\Domain\Service\CrmReaderInterface;
use Nowo\CrmKitBundle\Domain\Service\HostLinkServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\ListQuery;
use Nowo\CrmKitBundle\Domain\Service\RecordInput;
use Nowo\CrmKitBundle\Domain\Service\TagServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\TimelineInterface;
use Nowo\CrmKitBundle\Domain\Value\ActorProfile;
use Nowo\CrmKitBundle\Domain\Value\HostObjectRef;
use Nowo\CrmKitBundle\Domain\Value\PanelItem;
use Nowo\TransactionContract\TransientStorageFailure;

use function array_map;
use function count;

/**
 * Reads (contract section 7): list filters, sort and pages; duplicates; timeline of a company with its contacts; host
 * links resolved by the host (hidden when unresolved, look-alike keys ignored); panels (a failing one skipped, a
 * transient failure rethrown); search for the SearchKit bridge.
 */
final class ReaderTest extends CrmTestCase
{
    public function testListsFilterSortAndPage(): void
    {
        $this->boot();
        $this->admin();
        $vip = $this->service(TagServiceInterface::class)->create('VIP', 'red');
        $a   = $this->records()->create(new RecordInput(SubjectType::Company, ['name' => 'Alpha 100%', 'tax_id' => 'X-1', 'email' => 'Info@Alpha.test'], 'leo', null, [$vip]));
        $this->company('beta_b');
        $this->company('Gamma', ['website' => 'https://gamma.test']);
        self::assertSame(['Alpha 100%', 'beta_b', 'Gamma'], $this->labels(new ListQuery(SubjectType::Company)));
        self::assertSame(['Gamma', 'beta_b', 'Alpha 100%'], $this->labels(new ListQuery(SubjectType::Company, direction: 'desc')));
        self::assertSame(['Alpha 100%'], $this->labels(new ListQuery(SubjectType::Company, text: '100%')));
        self::assertSame([], $this->labels(new ListQuery(SubjectType::Company, text: 'a%a')), 'LIKE wildcards are literal');
        self::assertSame(['beta_b'], $this->labels(new ListQuery(SubjectType::Company, text: 'A_B')));
        self::assertSame(['Alpha 100%'], $this->labels(new ListQuery(SubjectType::Company, text: 'x1')), 'tax id without separators');
        self::assertSame(['Alpha 100%'], $this->labels(new ListQuery(SubjectType::Company, text: 'info@')));
        self::assertSame(['Gamma'], $this->labels(new ListQuery(SubjectType::Company, text: 'gamma.test')));
        self::assertSame(['Alpha 100%'], $this->labels(new ListQuery(SubjectType::Company, tagId: $vip)));
        self::assertSame(['Alpha 100%'], $this->labels(new ListQuery(SubjectType::Company, ownerId: 'leo')));
        self::assertSame([], $this->labels(new ListQuery(SubjectType::Company, ownerId: 'Leo')));
        $page = $this->reader()->list(new ListQuery(SubjectType::Company, sort: 'created', page: 2, perPage: 2));
        self::assertSame([3, 1], [$page->total, count($page->rows)]);
        self::assertSame(3, $this->reader()->list(new ListQuery(SubjectType::Company, sort: 'updated'))->total);
        self::assertSame('name', (new ListQuery(SubjectType::Company, sort: 'evil'))->sort);
        $this->records()->trash(SubjectType::Company, $a, 1);
        self::assertSame(['Alpha 100%'], $this->labels(new ListQuery(SubjectType::Company, status: RecordStatus::Trashed)));
        self::assertCount(1, $this->reader()->tags());
        self::assertSame(['Ana', 'Leo'], array_map(static fn (ActorProfile $o): string => $o->name, $this->reader()->owners()));
    }

    public function testDuplicateHints(): void
    {
        $this->boot();
        $this->admin();
        $a = $this->company('Acme', ['website' => 'https://acme.test', 'tax_id' => 'B1']);
        $this->company('ACME');
        $this->company('Acme Spain', ['website' => 'http://www.acme.test']);
        $this->company('Other', ['tax_id' => 'b-1']);
        $this->company('Unrelated');
        self::assertSame(['ACME', 'Acme Spain', 'Other'], $this->sorted(array_map(static fn (RecordView $v): string => $v->label, $this->reader()->duplicates(SubjectType::Company, $a))));
        $ada = $this->contact('Ada', 'Lovelace');
        $this->contact('ada', 'LOVELACE');
        self::assertCount(1, $this->reader()->duplicates(SubjectType::Contact, $ada));
    }

    public function testTimelineLinksAndPanels(): void
    {
        $this->boot();
        $this->admin();
        $acme = $this->company('Acme');
        $ada  = $this->contact('Ada', 'L');
        $this->records()->affiliate($ada, $acme, null, true);
        $links = $this->service(HostLinkServiceInterface::class);
        $l1    = $links->link(SubjectType::Company, $acme, new HostObjectRef('project', 'p1'));
        self::assertSame($l1, $links->link(SubjectType::Company, $acme, new HostObjectRef('project', 'p1')), 'idempotent');
        $links->link(SubjectType::Company, $acme, new HostObjectRef('project', 'p2'));
        try {
            $links->link(SubjectType::Company, $acme, new HostObjectRef('project', 'P1'));
            self::fail('linked an object the resolver does not confirm');
        } catch (InvalidInputException $e) {
            self::assertSame(['hostId' => 'error.host_object_unknown'], $e->errors);
        }
        $links->link(SubjectType::Company, $acme, new HostObjectRef('invoice', 'F-1'), 'Invoice F-1');
        $links->link(SubjectType::Company, $acme, new HostObjectRef('ticket', 'T-9'));
        self::assertSame(['Invoice F-1', 'Website', 'Shop', 'ticket T-9'], array_map(static fn (LinkView $l): string => $l->label, $this->reader()->links(SubjectType::Company, $acme)), 'P2 look-alike ignored');
        self::assertCount(1, $links->subjectsLinkedTo(new HostObjectRef('project', 'p1')));
        self::assertSame([], $links->subjectsLinkedTo(new HostObjectRef('project', 'p9')));
        $this->service(TimelineInterface::class)->record(SubjectType::Contact, $ada, 'host.ticket_opened', ['ticket' => 'T-9'], '/tickets/9');
        $timeline = $this->reader()->timeline(SubjectType::Company, $acme);
        self::assertSame('host', $timeline[0]->kind->value);
        self::assertSame('Ada L', $timeline[0]->subjectLabel);
        self::assertEquals(['_label' => 'host.ticket_opened', '_url' => '/tickets/9', '%ticket%' => 'T-9'], $timeline[0]->params, 'MySQL JSON orders the keys');
        self::assertSame('Ana', $timeline[0]->actorName);
        $older = $this->reader()->timeline(SubjectType::Company, $acme, 2, $timeline[1]->id);
        self::assertCount(2, $older);
        self::assertNotSame($timeline[1]->id, $older[0]->id);

        $panels = $this->reader()->panels(SubjectType::Company, $acme);
        self::assertSame('projects', $panels[0]['key']);
        self::assertSame(['Project p1', 'Project p2'], array_map(static fn (PanelItem $i): string => $i->title, $panels[0]['panel']->items));
        self::assertSame([], $this->reader()->panels(SubjectType::Contact, $ada));
        $this->ctx()->panelFails = true;
        self::assertSame([], $this->reader()->panels(SubjectType::Company, $acme));
        $this->ctx()->panelTransient = true;
        $this->expectException(TransientStorageFailure::class);
        $this->reader()->panels(SubjectType::Company, $acme);
    }

    public function testHostEntriesAreValidated(): void
    {
        $this->boot();
        $this->admin();
        $acme     = $this->company('Acme');
        $timeline = $this->service(TimelineInterface::class);
        foreach ([['Bad Key', [], null], ['k', ['Bad' => 1], null], ['k', [], '//evil'], ['k', [], '/\\evil'], ['k', ['a' => str_repeat('x', 201)], null]] as [$key, $params, $url]) {
            try {
                $timeline->record(SubjectType::Company, $acme, $key, $params, $url);
                self::fail('accepted ' . $key);
            } catch (InvalidInputException) {
                $this->addToAssertionCount(1);
            }
        }
        try {
            $this->service(HostLinkServiceInterface::class)->link(SubjectType::Company, $acme, new HostObjectRef('project', 'p1'), "bad\x07");
            self::fail('accepted');
        } catch (InvalidInputException) {
            $this->addToAssertionCount(1);
        }
        $links = $this->service(HostLinkServiceInterface::class);
        $id    = $links->link(SubjectType::Company, $acme, new HostObjectRef('project', 'p1'), ' Main ');
        $links->unlink($id);
        self::assertContains('HostLinkRemoved', $this->recorder()->eventNames());
        $this->expectException(NotAvailableException::class);
        $links->unlink($id);
    }

    public function testSearch(): void
    {
        $this->boot();
        $this->admin();
        $acme = $this->company('Acme');
        $this->company('Acme Two');
        $ada = $this->contact('Ada', 'L', 'ada@x.test');
        $this->contact('Adam', 'K');
        $this->records()->affiliate($ada, $acme, null, true);
        $reader = $this->service(CrmReader::class);
        self::assertSame(2, $reader->search(SubjectType::Company, 'acme', 1)[1] ?? null);
        self::assertCount(1, $reader->search(SubjectType::Contact, 'ada', 5, $acme)[0] ?? []);
        self::assertNull($reader->search(SubjectType::Company, 'acme', 5, $acme), 'a company scope narrows contacts only');
        self::assertNull($reader->search(SubjectType::Contact, 'ada', 5, '01900000-0000-7000-8000-00000000ffff'));
        self::assertNull($reader->search(SubjectType::Contact, 'ada', 5, 'nope'));
        $this->actAs('zoe');
        self::assertNull($reader->search(SubjectType::Company, 'acme', 5));
    }

    private function reader(): CrmReaderInterface
    {
        return $this->service(CrmReaderInterface::class);
    }

    /**
     * @return list<string>
     */
    private function labels(ListQuery $query): array
    {
        return array_map(static fn (RecordView $v): string => $v->label, $this->reader()->list($query)->rows);
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
