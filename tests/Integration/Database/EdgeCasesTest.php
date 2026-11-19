<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Application\CrmAccess;
use Nowo\CrmKitBundle\Application\Port\CrmStoreInterface;
use Nowo\CrmKitBundle\Domain\Enum\ConsentSource;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\DuplicatePolicy;
use Nowo\CrmKitBundle\Domain\Enum\FieldType;
use Nowo\CrmKitBundle\Domain\Enum\ImportFormat;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Model\Company;
use Nowo\CrmKitBundle\Domain\Service\ConsentServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\CrmSettingsInterface;
use Nowo\CrmKitBundle\Domain\Service\FieldServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\HostLinkServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\ImportServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\ListQuery;
use Nowo\CrmKitBundle\Domain\Service\NoteServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\RecordInput;
use Nowo\CrmKitBundle\Domain\Service\TagServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\TimelineInterface;
use Nowo\CrmKitBundle\Domain\Value\AuditEntry;
use Nowo\CrmKitBundle\Domain\Value\HostObjectRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Nowo\CrmKitBundle\Infrastructure\Doctrine\DoctrineCrmStore;
use ReflectionMethod;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The rarer paths: unique keys refused by a racing writer on every unique table (one more run, or the matching
 * answer), the store's exact re-check, empty inputs, purge of every child kind.
 */
final class EdgeCasesTest extends CrmTestCase
{
    public function testRacingWritersOnEveryUniqueKey(): void
    {
        $this->boot();
        $this->admin();
        $acme = $this->company('Acme');
        $ada  = $this->contact('Ada', 'L');
        $race = function (string $what, int $times = 1): void {
            $this->ctx()->races[$what] = $times;
        };

        $race('FieldDefinition');
        $this->expectErrors(['key' => 'error.field_key_taken'], fn () => $this->service(FieldServiceInterface::class)->define(SubjectType::Company, 'tier', 'Tier', FieldType::Text, [], false, 1));
        $race('HostLink');
        $this->service(HostLinkServiceInterface::class)->link(SubjectType::Company, $acme, new HostObjectRef('project', 'p1'));
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_host_link'));
        $race('Affiliation');
        $this->records()->affiliate($ada, $acme, null, true);
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_affiliation'));
        $race('CrmSettings');
        try {
            $this->service(CrmSettingsInterface::class)->update(10, 2, 2, 2, 0);
            self::fail('stored');
        } catch (ConflictException $e) {
            self::assertSame(ConflictException::SETTINGS_STALE, $e->errorCode);
        }
        $tags = $this->service(TagServiceInterface::class);
        $race('Tag');
        $this->expectErrors(['name' => 'error.tag_taken'], static fn () => $tags->create('VIP', 'red'));
        $vip = $tags->create('VIP', 'red');
        $race('TagRename');
        $this->expectErrors(['name' => 'error.tag_taken'], static fn () => $tags->change($vip, 'Gold', 'red', 1));
        $race('Tagging');
        $tags->assign(SubjectType::Company, $acme, [$vip]);
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_tagging'));
        self::assertSame(0, array_sum($this->ctx()->races));
        foreach (['HostLink' => fn () => $this->service(HostLinkServiceInterface::class)->link(SubjectType::Company, $acme, new HostObjectRef('project', 'p2')), 'Tagging' => static fn () => $tags->assign(SubjectType::Contact, $ada, [$vip]), 'Affiliation' => fn (): string => $this->records()->affiliate($ada, $this->company('Beta'), null, false)] as $what => $call) {
            $race($what, 2);
            try {
                $call();
                self::fail('stored ' . $what);
            } catch (ConflictException $e) {
                self::assertSame('crm_write_conflict', $e->errorCode);
            }
        }
    }

    public function testImportChunksReplayOnARacingEmail(): void
    {
        $this->boot();
        $this->admin();
        $imports = $this->service(ImportServiceInterface::class);
        $one     = $imports->upload(SubjectType::Contact, ImportFormat::Csv, 'a.csv', "email,last_name\na@x.test,A\n");
        $imports->confirm($one, ['email' => 'email', 'last_name' => 'last_name'], DuplicatePolicy::Skip, 1);
        $this->ctx()->emailRaces = 1;
        self::assertTrue($imports->run($one));
        self::assertSame(1, $imports->preview($one)->counts['created']);
        $two = $imports->upload(SubjectType::Contact, ImportFormat::Csv, 'b.csv', "email,last_name\nb@x.test,B\n");
        $imports->confirm($two, ['email' => 'email', 'last_name' => 'last_name'], DuplicatePolicy::Skip, 1);
        $this->ctx()->emailRaces = 2;
        try {
            $imports->run($two);
            self::fail('stored');
        } catch (ConflictException $e) {
            self::assertSame('crm_write_conflict', $e->errorCode);
        }
    }

    public function testImportCompanyMatchingIsExact(): void
    {
        $this->boot();
        $this->admin();
        $two  = $this->company('Aacme');
        $acme = $this->company('Acme');
        $ada  = $this->contact('Ada', 'L', 'ada@x.test');
        $this->records()->affiliate($ada, $acme, null, true);
        $imports = $this->service(ImportServiceInterface::class);
        $id      = $imports->upload(SubjectType::Contact, ImportFormat::Csv, 'a.csv', "email,last_name,company\nada@x.test,L,ACME\nzed@x.test,Z,acme\n");
        $imports->confirm($id, ['email' => 'email', 'last_name' => 'last_name', 'company' => 'company'], DuplicatePolicy::Update, 1);
        $imports->run($id);
        self::assertSame(2, $this->int('SELECT COUNT(*) FROM nowo_crm_affiliation WHERE company_id = ?', [$acme]));
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_affiliation WHERE company_id = ?', [$two]));
    }

    public function testPurgeDeletesEveryChildKind(): void
    {
        $this->boot();
        $this->admin();
        $acme = $this->company('Acme');
        $ada  = $this->contact('Ada', 'L');
        $this->records()->affiliate($ada, $acme, null, true);
        $this->service(HostLinkServiceInterface::class)->link(SubjectType::Contact, $ada, new HostObjectRef('project', 'p1'));
        $this->service(ConsentServiceInterface::class)->record($ada, 'newsletter', true, ConsentSource::Manual);
        $this->records()->trash(SubjectType::Contact, $ada, 2);
        $this->records()->purge(SubjectType::Contact, $ada);
        foreach (['nowo_crm_affiliation', 'nowo_crm_host_link', 'nowo_crm_consent'] as $table) {
            self::assertSame(0, $this->int('SELECT COUNT(*) FROM ' . $table), $table);
        }
    }

    public function testTheStoreRechecksWhatTheDatabaseReturns(): void
    {
        $this->boot();
        $this->admin();
        $store  = $this->service(CrmStoreInterface::class);
        $tenant = new TenantRef('acme');
        self::assertSame([], $store->records(SubjectType::Company, $tenant, []));
        self::assertSame([], $store->lockRecords(SubjectType::Company, $tenant, []));
        self::assertSame([], $store->lockTombstones(SubjectType::Contact, $tenant, []));
        self::assertSame([], $store->taggings($tenant, SubjectType::Company, []));
        self::assertSame([], $store->activities($tenant, [], 10, null));
        self::assertSame(0, $store->deleteMergesBefore($tenant, new DateTimeImmutable(), 10));
        for ($i = 0; $i < 3; ++$i) {
            $this->company('C' . $i);
        }
        $first = $store->batch($tenant, new ListQuery(SubjectType::Company), null, 1);
        self::assertCount(2, $this->runner()->read(static fn (): array => $store->batch($tenant, new ListQuery(SubjectType::Company), $first[0]->getId(), 10)));
        $exact = new ReflectionMethod(DoctrineCrmStore::class, 'exact');
        $mine  = new Company('01900000-0000-7000-8000-000000000001', 'acme', new DateTimeImmutable());
        $mine->changeOwner('ana');
        $theirs = new Company('01900000-0000-7000-8000-000000000002', 'ACME', new DateTimeImmutable());
        self::assertSame([$mine], $exact->invoke($store, [$mine, $theirs, 'junk'], Company::class, $tenant));
        self::assertSame([], $exact->invoke($store, [$mine], Company::class, $tenant, 'Ana'));
        self::assertSame([], $exact->invoke($store, 'not rows', Company::class, $tenant));
    }

    public function testOnlyOnePurgeRunsAtATime(): void
    {
        $this->boot();
        $this->admin();
        $console = new Application($this->kernel);
        $purge   = new CommandTester($console->find('nowo:crm-kit:purge'));
        $now     = $this->clock()->now()->getTimestamp();
        $this->connection()->executeStatement("INSERT INTO nowo_crm_counter (tenant_id, resource, value) VALUES ('__crm__', '__purge__', ?)", [$now]);
        self::assertSame(0, $purge->execute([]));
        self::assertStringContainsString('Another purge is running', $purge->getDisplay());
        $this->clock()->modify('+2 hours');
        self::assertSame(0, $purge->execute([]));
        self::assertStringContainsString('records: 0', $purge->getDisplay(), 'a claim older than an hour is taken over');
        self::assertSame(0, $this->int("SELECT value FROM nowo_crm_counter WHERE tenant_id = '__crm__'"), 'released after the run');
    }

    public function testAPurgeWhoseClaimWasTakenOverStops(): void
    {
        $this->boot();
        $this->admin();
        $this->service(CrmSettingsInterface::class)->update(7, 1, 1, 1, 0);
        $this->company('Acme');
        $this->ctx()->tenant = 'beta';
        $this->ctx()->grant('beta', 'ana', 'view', 'edit', 'manage');
        $this->company('Beta');
        $this->clock()->modify('+6 years');
        // While the run audits acme, another run takes the claim over: the run stops before beta and leaves it alone.
        $this->recorder()->onRecord = function (AuditEntry $entry): void {
            if ($entry->action === 'crm.retention.purged') {
                $this->connection()->executeStatement("UPDATE nowo_crm_counter SET value = 7 WHERE tenant_id = '__crm__'");
            }
        };
        $purge = new CommandTester((new Application($this->kernel))->find('nowo:crm-kit:purge'));
        self::assertSame(0, $purge->execute([]));
        self::assertStringContainsString('Another purge is running', $purge->getDisplay());
        self::assertSame(7, $this->int("SELECT value FROM nowo_crm_counter WHERE tenant_id = '__crm__'"), 'the other run keeps its claim');
        self::assertSame(1, $this->int("SELECT COUNT(*) FROM nowo_crm_activity WHERE tenant_id = 'beta'"), 'beta was not purged');
        self::assertSame(0, $this->int("SELECT COUNT(*) FROM nowo_crm_activity WHERE tenant_id = 'acme'"), 'acme was');
    }

    public function testEditsKeepTheirOwnEmailAndAFormerOwner(): void
    {
        $this->boot();
        $this->admin();
        $vip = $this->service(TagServiceInterface::class)->create('VIP', 'red');
        $ada = $this->records()->create(new RecordInput(SubjectType::Contact, ['last_name' => 'L', 'email' => 'ada@x.test'], 'leo'));
        // A racing tagging refuses the first run: the e-mail holder is the edited contact itself, so no form error.
        $this->ctx()->races['Tagging'] = 1;
        self::assertSame(2, $this->records()->update($ada, new RecordInput(SubjectType::Contact, ['last_name' => 'L', 'email' => 'ada@x.test'], 'leo', null, [$vip]), 1));
        // The owner left the directory: an edit that keeps it is not refused; a change to an unknown owner is.
        unset($this->ctx()->owners['acme']['leo']);
        self::assertSame(3, $this->records()->update($ada, new RecordInput(SubjectType::Contact, ['last_name' => 'Lovelace'], 'leo'), 2));
        $this->expectErrors(['owner' => 'error.owner_unknown'], fn (): int => $this->records()->update($ada, new RecordInput(SubjectType::Contact, ['last_name' => 'L'], 'zed'), 3));
    }

    public function testEveryWriteIsAudited(): void
    {
        $this->boot();
        $this->admin();
        $acme = $this->company('Acme');
        $ada  = $this->contact('Ada', 'L');
        $tags = $this->service(TagServiceInterface::class);
        $vip  = $tags->create('VIP', 'red');
        $tags->assign(SubjectType::Company, $acme, [$vip]);
        $notes = $this->service(NoteServiceInterface::class);
        $note  = $notes->add(SubjectType::Company, $acme, 'n');
        $notes->edit($note, 'm', false, 1);
        $links = $this->service(HostLinkServiceInterface::class);
        $links->unlink($links->link(SubjectType::Company, $acme, new HostObjectRef('project', 'p1')));
        $this->records()->unaffiliate($this->records()->affiliate($ada, $acme, null, true));
        $this->service(TimelineInterface::class)->record(SubjectType::Company, $acme, 'host.event');
        foreach (['crm.tags.changed', 'crm.note.added', 'crm.note.edited', 'crm.link.added', 'crm.link.removed', 'crm.affiliation.saved', 'crm.affiliation.removed', 'crm.timeline.host_entry'] as $action) {
            self::assertContains($action, $this->recorder()->actions());
        }
    }

    public function testSmallGatesAndLookups(): void
    {
        $this->boot();
        $this->admin();
        $access = $this->service(CrmAccess::class);
        self::assertTrue($access->can(CrmPermission::View));
        $this->ctx()->tenant = null;
        self::assertFalse($access->can(CrmPermission::View));
        $this->ctx()->tenant = 'acme';
        try {
            $this->service(NoteServiceInterface::class)->add(SubjectType::Company, 'nope', 'x');
            self::fail('found');
        } catch (NotAvailableException) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * @param array<string, string> $errors
     * @param callable(): mixed $call
     */
    private function expectErrors(array $errors, callable $call): void
    {
        try {
            $call();
            self::fail('accepted');
        } catch (InvalidInputException $e) {
            self::assertSame($errors, $e->errors);
        }
    }
}
