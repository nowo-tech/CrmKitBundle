<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database\Api;

use DateTimeImmutable;
use JsonSerializable;
use Nowo\CrmKitBundle\Api\CrmApiCode;
use Nowo\CrmKitBundle\Api\CrmApiPrincipal;
use Nowo\CrmKitBundle\Api\Input\CrmApiCompanyLink;
use Nowo\CrmKitBundle\Api\Input\CrmApiCursor;
use Nowo\CrmKitBundle\Api\Input\CrmApiDealQuery;
use Nowo\CrmKitBundle\Api\Input\CrmApiNextActionDraft;
use Nowo\CrmKitBundle\Api\Input\CrmApiPageRequest;
use Nowo\CrmKitBundle\Api\Input\CrmApiRecordDraft;
use Nowo\CrmKitBundle\Api\Input\CrmApiSearchQuery;
use Nowo\CrmKitBundle\Api\Input\IdempotencyKey;
use Nowo\CrmKitBundle\Api\Operation\CrmApiErrors;
use Nowo\CrmKitBundle\Api\Read\CrmApiItem;
use Nowo\CrmKitBundle\Api\Read\CrmApiWritten;
use Nowo\CrmKitBundle\Application\Pipeline\DealInput;
use Nowo\CrmKitBundle\Application\Pipeline\DealService;
use Nowo\CrmKitBundle\Application\Pipeline\FunnelReader;
use Nowo\CrmKitBundle\Application\Pipeline\FunnelService;
use Nowo\CrmKitBundle\Application\Pipeline\NextActionService;
use Nowo\CrmKitBundle\Domain\Enum\FunnelKind;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Service\NoteServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\RecordInput;
use Nowo\CrmKitBundle\Domain\Value\RecordId;
use Nowo\CrmKitBundle\Tests\Fixtures\TestTaskPort;
use stdClass;

use function array_filter;
use function array_map;
use function count;
use function is_string;
use function json_encode;
use function range;
use function str_pad;
use function str_repeat;

use const STR_PAD_LEFT;

/**
 * The fixes of audit VS (0.3.0): owner decisions Q1 (a hidden company match creates the company) and Q2 (the record
 * decision of every row in lists, searches, labels and timelines), VS-1, VS-2, VS-5, the next-action dedupe and race 10.
 */
final class ApiAuditVsTest extends ApiTestCase
{
    public function testAHiddenCompanyMatchCreatesTheCompanyAndAHiddenContactStaysRefused(): void
    {
        $this->bootApi();
        $p                     = $this->principal();
        $hidden                = $this->company('Acme', ['tax_id' => 'B1']);
        $this->ctx()->denied[] = 'view:' . $hidden;
        $made                  = $this->writes()->createCompany($p, $this->acme(), $this->key(), new CrmApiRecordDraft(SubjectType::Company, ['name' => 'Acme', 'tax_id' => 'B1']));
        self::assertSame([201, true], [$made->status, $made->changed]);
        self::assertSame(2, $this->int('SELECT COUNT(*) FROM nowo_crm_company'));
        $mail                  = $this->contact('Ada', 'L', 'ada@x.test');
        $this->ctx()->denied[] = 'view:' . $mail;
        $this->refused(CrmApiCode::HIDDEN_DUPLICATE, fn (): CrmApiWritten => $this->writes()->createContact($p, $this->acme(), $this->key(), new CrmApiRecordDraft(SubjectType::Contact, ['last_name' => 'X', 'email' => 'ada@x.test'])));
    }

    public function testListenerDeniedRecordsAppearNowhere(): void
    {
        $this->bootApi();
        $p      = $this->principal();
        $acme   = $this->company('Acme');
        $denied = $this->contact('Dora', 'Denied', 'dora@x.test');
        $shown  = $this->contact('Sam', 'Shown', 'sam@x.test');
        foreach ([$denied, $shown] as $contact) {
            $this->records()->affiliate($contact, $acme, 'Staff', false);
            $this->service(NoteServiceInterface::class)->add(SubjectType::Contact, $contact, 'Secret note of ' . $contact);
        }
        $this->ctx()->denied[] = 'view:' . $denied;

        // API search, with its rescans past denied rows.
        $hits = $this->reads()->search($p, $this->acme(), new CrmApiSearchQuery([SubjectType::Contact], page: new CrmApiPageRequest(null, 1)));
        self::assertSame(['Sam Shown'], array_map(static fn (CrmApiItem $i): mixed => $i->text['label'], $this->items($hits->items)));
        self::assertNull($hits->nextCursor);
        // Affiliation labels on the company, and the company timeline (VS-1): neither the denied contact's id nor its notes.
        $company = (string) json_encode($this->reads()->company($p, $this->acme(), new RecordId($acme)));
        self::assertStringNotContainsString($denied, $company);
        self::assertStringContainsString($shown, $company);
        $timeline = (string) json_encode($this->reads()->timeline($p, $this->acme(), SubjectType::Company, new RecordId($acme), new CrmApiPageRequest(null, 100)));
        self::assertStringNotContainsString($denied, $timeline);
        self::assertStringContainsString('Secret note of ' . $shown, $timeline);
        self::assertCount(1, $this->reads()->affiliations($p, $this->acme(), SubjectType::Company, new RecordId($acme), new CrmApiPageRequest())->items);

        // A record an entry names that is no longer active is not named either.
        $this->records()->trash(SubjectType::Contact, $shown, $this->int('SELECT version FROM nowo_crm_contact WHERE id = ?', [$shown]));
        self::assertStringNotContainsString($shown, (string) json_encode($this->reads()->timeline($p, $this->acme(), SubjectType::Company, new RecordId($acme), new CrmApiPageRequest(null, 100))));
        $this->records()->restore(SubjectType::Contact, $shown);

        // The 0.2 web pages: the list, the company page and the CSV export.
        $this->client->request('GET', '/crm/contacts');
        $page = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('Dora', $page);
        self::assertStringContainsString('Sam', $page);
        $this->client->request('GET', '/crm/companies/' . $acme);
        self::assertStringNotContainsString('Dora', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', '/crm/contacts/export.csv');
        $csv = $this->client->getInternalResponse()->getContent();
        self::assertStringNotContainsString('dora@x.test', $csv);
        self::assertStringContainsString('sam@x.test', $csv);
    }

    public function testScansStopAfterTheirBound(): void
    {
        $this->bootApi();
        $p = $this->principal();
        // More denied rows than MAX_SCAN_ROUNDS batches of a one-row page: the page comes back empty with a cursor that
        // continues after the last row scanned, and the visible row is found on the following pages.
        foreach (range(1, 22) as $i) {
            $this->ctx()->denied[] = 'view:' . $this->contact('Aaa' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'Hidden');
        }
        $this->contact('Zed', 'Shown');
        $page = $this->reads()->search($p, $this->acme(), new CrmApiSearchQuery([SubjectType::Contact], page: new CrmApiPageRequest(null, 1)));
        self::assertSame([], $page->items);
        self::assertNotNull($page->nextCursor);
        $next = $this->reads()->search($p, $this->acme(), new CrmApiSearchQuery([SubjectType::Contact], page: new CrmApiPageRequest(new CrmApiCursor($page->nextCursor), 1)));
        self::assertCount(1, $next->items);

        // The same bound on the deal list.
        $acme                = $this->company('Acme');
        $funnel              = $this->service(FunnelReader::class)->funnel($this->service(FunnelService::class)->create(FunnelKind::Deal, null, 'Sales', ['open' => 'Lead']));
        $deals               = $this->service(DealService::class);
        $this->ctx()->denied = [];
        $first               = $deals->create(new DealInput('Visible', $funnel->id, companyId: $acme));
        foreach (range(1, 22) as $i) {
            $this->clock()->modify('+1 second');
            $this->ctx()->denied[] = 'view:' . $deals->create(new DealInput('Hidden ' . $i, $funnel->id, companyId: $acme));
        }
        $dealPage = $this->deals()->search($p, $this->acme(), new CrmApiDealQuery(page: new CrmApiPageRequest(null, 1)));
        self::assertSame([], $dealPage->items);
        self::assertNotNull($dealPage->nextCursor);
        $cursor = $dealPage->nextCursor;
        $found  = null;
        for ($i = 0; $i < 5 && $cursor !== null && $found === null; ++$i) {
            $after  = $this->deals()->search($p, $this->acme(), new CrmApiDealQuery(page: new CrmApiPageRequest(new CrmApiCursor($cursor), 1)));
            $found  = ($after->items[0] ?? null) instanceof CrmApiItem ? $after->items[0]->fact('id') : null;
            $cursor = $after->nextCursor;
        }
        self::assertSame($first, $found);
    }

    public function testAContactCreateAffiliatesInItsOwnUnit(): void
    {
        $this->bootApi();
        $p    = $this->principal();
        $acme = $this->company('Acme');
        // A refused affiliation (an unknown purpose, a company the person may not edit) writes nothing at all.
        $this->refused(CrmApiCode::INPUT_REFUSED, fn (): CrmApiWritten => $this->writes()->createContact($p, $this->acme(), $this->key(), new CrmApiRecordDraft(SubjectType::Contact, ['last_name' => 'A'], company: new CrmApiCompanyLink(new RecordId($acme), purposes: ['nope']))));
        $this->ctx()->denied[] = 'edit:' . $acme;
        $this->refused(CrmApiCode::DENIED, fn (): CrmApiWritten => $this->writes()->createContact($p, $this->acme(), $this->key(), new CrmApiRecordDraft(SubjectType::Contact, ['last_name' => 'A'], company: new CrmApiCompanyLink(new RecordId($acme), primaryContact: true))));
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_contact'));
        $this->ctx()->denied = [];
        // A replay writes nothing: a removed affiliation stays removed.
        $draft = new CrmApiRecordDraft(SubjectType::Contact, ['last_name' => 'A'], company: new CrmApiCompanyLink(new RecordId($acme), 'CEO', true, true, ['billing']));
        $made  = $this->writes()->createContact($p, $this->acme(), new IdempotencyKey('c-aff'), $draft);
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_affiliation'));
        $record = $made->result instanceof CrmApiItem ? $made->result->fact('record') : null;
        self::assertInstanceOf(CrmApiItem::class, $record);
        $this->relations()->removeAffiliation($p, $this->acme(), $this->key(), new RecordId(is_string($record->fact('id')) ? $record->fact('id') : ''), new RecordId($acme));
        self::assertTrue($this->writes()->createContact($p, $this->acme(), new IdempotencyKey('c-aff'), $draft)->replayed);
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_affiliation'));
    }

    public function testNextActionsReadTasksWithTheTokenAndNeverAdoptAnotherSubject(): void
    {
        $this->bootApi(['pipeline' => ['task_port' => 'test.task_port']]);
        $port = $this->kernel->getContainer()->get('test.task_port');
        self::assertInstanceOf(TestTaskPort::class, $port);
        $context   = new stdClass();
        $principal = new CrmApiPrincipal($this->principal()->user, $this->principal()->actor, $this->principal()->grant, $this->principal()->reach, 'tok-1', 'Agent', hostContext: $context);
        $acme      = $this->company('Acme');
        $beta      = $this->company('Beta');
        $this->nextActions()->set($principal, $this->acme(), new IdempotencyKey('na-1'), new CrmApiNextActionDraft(SubjectType::Company, new RecordId($acme), 'Call', new DateTimeImmutable('2026-11-25 09:00:00')));
        self::assertContains($context, $port->stateContexts, 'task states are read with the token (VS-5)');
        // The same dedupe key for another subject (an idempotency claim that expired) is refused, never adopted.
        $actions = $this->service(NextActionService::class);
        $actions->set(SubjectType::Company, $acme, 'Visit', new DateTimeImmutable('2026-11-26'), null, str_repeat('9', 64));
        $this->refused(CrmApiCode::IDEMP_MISMATCH, static fn (): mixed => CrmApiErrors::run(static fn () => $actions->set(SubjectType::Company, $beta, 'Visit', new DateTimeImmutable('2026-11-26'), null, str_repeat('9', 64))));
    }

    public function testAWriteReplayedAfterADeadlockClaimsAndWritesOnce(): void
    {
        $this->bootApi();
        $this->ctx()->races['Deadlock'] = 1;
        $made                           = $this->writes()->createCompany($this->principal(), $this->acme(), new IdempotencyKey('race-10'), new CrmApiRecordDraft(SubjectType::Company, ['name' => 'Acme']));
        self::assertSame([201, false], [$made->status, $made->replayed]);
        self::assertSame(0, $this->ctx()->races['Deadlock']);
        self::assertSame([1, 1, 1], [$this->int('SELECT COUNT(*) FROM nowo_crm_company'), $this->int('SELECT COUNT(*) FROM nowo_crm_api_idempotency'), $this->int("SELECT value FROM nowo_crm_counter WHERE resource = 'companies'")]);
        self::assertCount(1, array_filter($this->recorder()->actions(), static fn (string $a): bool => $a === 'crm.company.created'));
        self::assertTrue($this->writes()->createCompany($this->principal(), $this->acme(), new IdempotencyKey('race-10'), new CrmApiRecordDraft(SubjectType::Company, ['name' => 'Acme']))->replayed);
        self::assertSame(1, count($this->recorder()->actions()) - count(array_filter($this->recorder()->actions(), static fn (string $a): bool => $a !== 'crm.company.created')));
        $input                          = new RecordInput(SubjectType::Company, ['name' => 'Beta']);
        $this->ctx()->races['Deadlock'] = 1;
        self::assertNotSame('', $this->records()->create($input), 'the pages replay too');
    }

    /**
     * @param list<JsonSerializable> $items
     *
     * @return list<CrmApiItem>
     */
    private function items(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            self::assertInstanceOf(CrmApiItem::class, $item);
            $out[] = $item;
        }

        return $out;
    }
}
