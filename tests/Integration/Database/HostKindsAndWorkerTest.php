<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use Doctrine\ORM\Tools\SchemaTool;
use LogicException;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Service\CrmReaderInterface;
use Nowo\CrmKitBundle\Domain\Service\ListQuery;
use Nowo\CrmKitBundle\Domain\Service\NoteServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\RecordInput;
use Nowo\CrmKitBundle\Tests\Fixtures\HostEntity\HostNote;
use Nowo\CrmKitBundle\Tests\Integration\Kernel\Host;
use Nowo\SearchKitBundle\Contract\SearchProviderInterface;
use Nowo\SearchKitBundle\Model\SearchQuery;

use function gc_collect_cycles;
use function memory_get_usage;

/**
 * Host kinds (REQ-ARCH-001) and worker mode (REQ-RUNTIME-002): the single-EM host keeps its own entities and pending
 * changes; one kept-alive kernel serves two tenants and two actors without crossed data, without a managed entity
 * left after a request, with flat memory; the SearchKit providers answer per call.
 */
final class HostKindsAndWorkerTest extends CrmTestCase
{
    private ?Host $hostKind = null;

    protected function host(): Host
    {
        return $this->hostKind ?? Host::standard();
    }

    public function testTheSingleEntityManagerHostKeepsItsOwnWork(): void
    {
        $this->hostKind = Host::singleEntityManager();
        $this->boot();
        $this->admin();
        $em   = $this->em();
        $tool = new SchemaTool($em);
        $tool->dropSchema([$em->getClassMetadata(HostNote::class)]);
        $tool->createSchema([$em->getClassMetadata(HostNote::class)]);
        $note = new HostNote('01900000-0000-7000-8000-000000000001', 'host');
        $em->persist($note);
        $em->flush();
        $id = $this->company('Acme');
        self::assertTrue($em->contains($note), 'the host entity stays managed');
        $note->text = 'changed';
        try {
            $this->company('Other');
            self::fail('flushed the host work');
        } catch (LogicException $e) {
            self::assertStringContainsString('will not flush the host', $e->getMessage());
        }
        $em->flush();
        $this->service(NoteServiceInterface::class)->add(SubjectType::Company, $id, 'x');
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_note'));

        // A refused write (a stale edit) rolls back without closing the host's manager or detaching its entity.
        try {
            $this->records()->update($id, new RecordInput(SubjectType::Company, ['city' => 'Madrid']), 99);
            self::fail('lost update');
        } catch (ConflictException $e) {
            self::assertSame(ConflictException::STALE, $e->errorCode);
        }
        self::assertTrue($em->isOpen(), 'the host manager stays open');
        self::assertSame($em, $this->em(), 'and is not reset');
        self::assertTrue($em->contains($note), 'the host entity stays managed');
        self::assertSame('', $this->text("SELECT COALESCE(city, '') FROM nowo_crm_company WHERE id = ?", [$id]));
        $this->service(NoteServiceInterface::class)->add(SubjectType::Company, $id, 'after');
        self::assertSame(2, $this->int('SELECT COUNT(*) FROM nowo_crm_note'));
        self::assertTrue($em->contains($note));
    }

    public function testTheDddHost(): void
    {
        $this->hostKind = Host::ddd();
        $this->boot();
        $this->admin();
        $this->company('Acme');
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM ddd_crm_company'));
    }

    public function testOneKernelServesTwoTenantsWithoutCrossedData(): void
    {
        $this->boot();
        $this->ctx()->grant('acme', 'ana', 'view', 'edit');
        $this->ctx()->grant('beta', 'bob', 'view', 'edit');
        $this->ctx()->owners['beta'] = ['bob' => 'Bob'];
        $profiles                    = [['acme', 'ana', 'Acme'], ['beta', 'bob', 'Beta']];
        foreach ($profiles as [$tenant, $actor, $name]) {
            $this->ctx()->tenant = $tenant;
            $this->ctx()->actor  = $actor;
            $this->company($name . ' Corp');
        }
        $baseline = 0;
        for ($i = 0; $i < 120; ++$i) {
            [$tenant, $actor, $name] = $profiles[$i % 2];
            $this->ctx()->tenant     = $tenant;
            $this->ctx()->actor      = $actor;
            $page                    = $this->client->request('GET', '/crm/companies');
            self::assertSame(200, $this->client->getResponse()->getStatusCode());
            self::assertStringContainsString($name . ' Corp', $page->text());
            self::assertStringNotContainsString(($tenant === 'acme' ? 'Beta' : 'Acme') . ' Corp', $page->text());
            if ($i % 5 === 0) {
                $form = $this->client->request('GET', '/crm/contacts/new')->filter('[data-testid="crm-record-form"] form')->form();
                $this->client->submit($form, ['crm_record[last_name]' => $name . ' person ' . $i]);
                self::assertSame(303, $this->client->getResponse()->getStatusCode());
            }
            self::assertSame(0, $this->em()->getUnitOfWork()->size(), 'no managed entity survives a request');
            if ($i === 20) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
        }
        gc_collect_cycles();
        self::assertLessThan($baseline + 8 * 1024 * 1024, memory_get_usage(), 'flat memory');
        self::assertSame(12, $this->int("SELECT COUNT(*) FROM nowo_crm_contact WHERE tenant_id = 'acme'"));
        self::assertSame(12, $this->int("SELECT COUNT(*) FROM nowo_crm_contact WHERE tenant_id = 'beta'"));
        self::assertSame(0, $this->int("SELECT COUNT(*) FROM nowo_crm_contact WHERE tenant_id = 'acme' AND display_name LIKE 'Beta%'"));
    }

    public function testTheSearchKitProviders(): void
    {
        $this->boot();
        $this->admin();
        $acme = $this->company('Acme');
        $ada  = $this->contact('Ada', 'L', 'ada@x.test', ['city' => 'Madrid']);
        $this->records()->affiliate($ada, $acme, null, true);
        $companies = $this->kernel->getContainer()->get('nowo_crm_kit.search.company');
        $contacts  = $this->kernel->getContainer()->get('nowo_crm_kit.search.contact');
        self::assertInstanceOf(SearchProviderInterface::class, $companies);
        self::assertInstanceOf(SearchProviderInterface::class, $contacts);
        $group = $companies->search(new SearchQuery('acm', null, 5, null, false, 'ana'));
        self::assertSame(['crm_companies', 'Acme', 1, '/crm/companies?q=acm'], [$group?->key, $group?->items[0]->title, $group?->total, $group?->moreUrl]);
        $people = $contacts->search(new SearchQuery('ada', null, 5, $acme, false, 'ana'));
        self::assertSame('ada@x.test · Madrid', $people?->items[0]->subtitle);
        self::assertNull($contacts->search(new SearchQuery('zzz', null, 5, null, false, 'ana')));
        self::assertCount(1, $this->service(CrmReaderInterface::class)->list(new ListQuery(SubjectType::Company))->rows);
    }
}
