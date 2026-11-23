<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use Closure;
use Nowo\CrmKitBundle\Application\ImportService;
use Nowo\CrmKitBundle\Domain\Enum\DuplicatePolicy;
use Nowo\CrmKitBundle\Domain\Enum\FieldType;
use Nowo\CrmKitBundle\Domain\Enum\HubSpotObject;
use Nowo\CrmKitBundle\Domain\Enum\ImportStatus;
use Nowo\CrmKitBundle\Domain\Enum\RowOutcome;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Service\CrmPersonalDataInterface;
use Nowo\CrmKitBundle\Domain\Service\FieldServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\MergeServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\TagServiceInterface;
use Nowo\CrmKitBundle\Domain\Value\HubSpotImportScope;
use Nowo\CrmKitBundle\Domain\Value\HubSpotMapping;
use Nowo\CrmKitBundle\Domain\Value\HubSpotObjectId;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Throwable;

use function str_contains;

/**
 * HubSpot imports (0.10.0): start ⇒ mapping ⇒ confirm ⇒ pages; owners by e-mail, contact ↔ company affiliations,
 * lists ⇒ tags; a re-run updates and never duplicates; empty HubSpot values never clear CRM values; erased people
 * stay out; merges, purges and the duplicate policy; pauses (429, daily limit, 401, 403, bad answers), resume and
 * failures; nothing of HubSpot's data outside the record tables.
 */
final class HubSpotImportTest extends HubSpotTestCase
{
    public function testAFullImportCreatesRecordsReferencesAffiliationsOwnersAndTags(): void
    {
        $fake                        = $this->hubspot();
        $this->ctx()->emails['acme'] = ['leo' => 'Leo@Owners.example'];
        $fake->owners                = ['501' => 'leo@owners.example', '502' => 'nobody@owners.example', '503' => 'not an address'];
        $fake->seedCompanies(12);
        $fake->seedContacts(15);
        $fake->contacts['2001']['hubspot_owner_id'] = '501';
        $fake->contacts['2002']['hubspot_owner_id'] = '502';
        $fake->associations                         = ['2001' => [['1001', true], ['1002', false], ['9999', false]], '2002' => [['1003', false]]];
        $fake->lists                                = ['77' => ['name' => 'Newsletter', 'type' => '0-1', 'members' => ['2001', '2003', '424242']], '78' => ['name' => 'Key accounts', 'type' => '0-2', 'members' => ['1001']]];
        $this->connect($fake);
        $existing = $this->service(TagServiceInterface::class)->create('Key accounts', 'red');
        $id       = $this->startAndConfirm([HubSpotObject::Contact], ['77', '78'], ['77' => HubSpotMapping::CREATE, '78' => $existing]);
        // The confirm request settled at most pages_per_request (2) pages; the rest is "Continue" (attended).
        $view = $this->imports()->preview($id);
        self::assertSame(ImportStatus::Running, $view->status);
        while (!$this->imports()->run($id, 5)) {
            // one request at a time
        }
        $done = $this->imports()->preview($id, 100);
        self::assertSame(ImportStatus::Done, $done->status);
        self::assertSame(['created' => 27, 'updated' => 0, 'duplicate' => 0, 'invalid' => 0, 'limit' => 0], $done->counts);
        self::assertSame(27, $this->int('SELECT COUNT(*) FROM nowo_crm_external_ref WHERE object_type IN (?, ?)', ['company', 'contact']));
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_external_ref WHERE object_type = ?', ['list']), 'a created list tag gets the list reference; a chosen tag does not');
        $ada = $this->int("SELECT COUNT(*) FROM nowo_crm_affiliation a JOIN nowo_crm_external_ref r ON r.target_id = a.contact_id WHERE r.external_id = '2001'");
        self::assertSame(2, $ada, 'the unknown company 9999 is skipped');
        self::assertSame(1, $this->int("SELECT COUNT(*) FROM nowo_crm_affiliation a JOIN nowo_crm_external_ref r ON r.target_id = a.contact_id WHERE r.external_id = '2001' AND a.is_primary = ?", [true]));
        self::assertSame('leo', $this->text("SELECT c.owner_id FROM nowo_crm_contact c JOIN nowo_crm_external_ref r ON r.target_id = c.id WHERE r.external_id = '2001'"));
        self::assertSame('', $this->text("SELECT COALESCE(c.owner_id, '') FROM nowo_crm_contact c JOIN nowo_crm_external_ref r ON r.target_id = c.id WHERE r.external_id = '2002'"));
        self::assertSame(2, $this->int("SELECT COUNT(*) FROM nowo_crm_tagging t JOIN nowo_crm_tag g ON g.id = t.tag_id WHERE g.name = 'Newsletter'"));
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_tagging WHERE tag_id = ?', [$existing]));
        self::assertSame('https://c1001.example', $this->text("SELECT c.website FROM nowo_crm_company c JOIN nowo_crm_external_ref r ON r.target_id = c.id WHERE r.external_id = '1001'"));
        self::assertSame('ES', $this->text("SELECT c.country FROM nowo_crm_company c JOIN nowo_crm_external_ref r ON r.target_id = c.id WHERE r.external_id = '1001'"));
        // Rows hold the kind and the HubSpot id only.
        foreach ($done->rows as $row) {
            self::assertSame(['object', 'id'], array_keys($row->data));
        }
        self::assertFalse(str_contains($this->text("SELECT data FROM nowo_crm_import_row WHERE data LIKE '%people.example%'"), '@'));
        self::assertContains('ImportCompleted', $this->recorder()->eventNames());
        self::assertContains('crm.import.completed', $this->recorder()->actions());
        // No request ever followed paging.next.link, and every one went to the constant host with the bearer header.
        foreach ($fake->requests as [, $url]) {
            self::assertStringStartsWith('https://api.hubapi.com/', $url);
        }
        foreach ($fake->authorizations as $auth) {
            self::assertSame('Authorization: Bearer ' . $fake->token, $auth);
        }
        // The token is nowhere in the import or its rows.
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_import WHERE run_cursor LIKE ? OR mapping LIKE ? OR file_name LIKE ?', ['%' . $fake->token . '%', '%' . $fake->token . '%', '%' . $fake->token . '%']));
    }

    public function testAReRunUpdatesWithoutDuplicatesAndEmptyValuesNeverClear(): void
    {
        $fake = $this->hubspot();
        $fake->seedContacts(3);
        $fake->contacts['2001']['jobtitle'] = 'Engineer';
        $this->connect($fake);
        $first = $this->startAndConfirm();
        self::assertTrue($this->imports()->run($first));
        $fake->contacts['2001']['jobtitle']  = '';
        $fake->contacts['2001']['firstname'] = 'Renamed';
        $fake->contacts['2002']['lastname']  = str_repeat('L', 200);
        $second                              = $this->startAndConfirm();
        self::assertTrue($this->imports()->run($second));
        $view = $this->imports()->preview($second);
        self::assertSame(['created' => 0, 'updated' => 2, 'duplicate' => 0, 'invalid' => 1, 'limit' => 0], $view->counts);
        self::assertSame('error.too_long', $view->rows[1]->message);
        self::assertSame(3, $this->int('SELECT COUNT(*) FROM nowo_crm_contact'));
        self::assertSame('Engineer', $this->text("SELECT c.job_title FROM nowo_crm_contact c JOIN nowo_crm_external_ref r ON r.target_id = c.id WHERE r.external_id = '2001'"));
        self::assertSame('Renamed', $this->text("SELECT c.first_name FROM nowo_crm_contact c JOIN nowo_crm_external_ref r ON r.target_id = c.id WHERE r.external_id = '2001'"));
    }

    public function testTheDuplicatePolicyDecidesForRecordsWithoutAReference(): void
    {
        $fake = $this->hubspot();
        $fake->seedContacts(2);
        $fake->seedCompanies(3);
        $fake->companies['1003']['domain'] = 'shared.example';
        $this->connect($fake);
        $mine = $this->contact('Old', 'Name', 'P2001@people.example');
        $this->company('Shared A', ['website' => 'https://shared.example']);
        $this->company('Shared B', ['website' => 'https://www.shared.example/x']);
        $this->company('Single', ['website' => 'https://c1002.example']);
        $skip = $this->startAndConfirm([HubSpotObject::Contact]);
        self::assertTrue($this->imports()->run($skip));
        $view = $this->imports()->preview($skip);
        self::assertSame(['created' => 2, 'updated' => 0, 'duplicate' => 3, 'invalid' => 0, 'limit' => 0], $view->counts);
        self::assertSame('error.ambiguous_domain', $view->rows[2]->message);
        self::assertSame($mine, $view->rows[3]->recordId, 'attended: the id of a duplicate the person may see');
        self::assertSame(0, $this->int("SELECT COUNT(*) FROM nowo_crm_external_ref WHERE external_id = '2001'"), 'skip writes no reference');
        $update = $this->hubImportsDone();
        self::assertSame(['created' => 0, 'updated' => 4, 'duplicate' => 1, 'invalid' => 0, 'limit' => 0], $this->imports()->preview($update)->counts);
        self::assertSame('Person', $this->text('SELECT first_name FROM nowo_crm_contact WHERE id = ?', [$mine]));
        self::assertSame(1, $this->int("SELECT COUNT(*) FROM nowo_crm_external_ref WHERE external_id = '2001' AND target_id = ?", [$mine]));
        self::assertSame(ImportStatus::Done, $this->imports()->preview($update)->status);
    }

    public function testErasedPeopleStayOutAndMergesAndPurgesMoveTheReferences(): void
    {
        $fake = $this->hubspot();
        $fake->seedContacts(3);
        $this->connect($fake);
        self::assertTrue($this->imports()->run($this->startAndConfirm()));
        $ids = [];
        foreach (['2001', '2002', '2003'] as $hid) {
            $ids[$hid] = $this->text('SELECT target_id FROM nowo_crm_external_ref WHERE external_id = ?', [$hid]);
        }
        $this->service(CrmPersonalDataInterface::class)->erase($ids['2001']);
        self::assertSame(1, $this->int("SELECT COUNT(*) FROM nowo_crm_external_ref WHERE external_id = '2001' AND erased = ?", [true]));
        $survivor = $this->contact('Kept', 'One', 'kept@x.test');
        $this->service(MergeServiceInterface::class)->merge(SubjectType::Contact, $survivor, $ids['2002'], [], 1, 1);
        self::assertSame($survivor, $this->text("SELECT target_id FROM nowo_crm_external_ref WHERE external_id = '2002'"));
        $this->records()->trash(SubjectType::Contact, $ids['2003'], 1);
        $id = $this->startAndConfirm();
        self::assertTrue($this->imports()->run($id));
        $view = $this->imports()->preview($id);
        self::assertSame(['error.record_erased', null, 'error.record_trashed'], [$view->rows[0]->message, $view->rows[1]->message, $view->rows[2]->message]);
        self::assertSame([RowOutcome::Duplicate, RowOutcome::Updated, RowOutcome::Duplicate], [$view->rows[0]->outcome, $view->rows[1]->outcome, $view->rows[2]->outcome]);
        self::assertSame(1, $this->int("SELECT COUNT(*) FROM nowo_crm_contact WHERE status = 'active' AND id = ?", [$survivor]));
        // Purge: the trashed record's reference goes with it; the erased person's tombstone stays.
        $this->clock()->modify('+400 days');
        (new CommandTester((new Application($this->kernel))->find('nowo:crm-kit:purge')))->execute([]);
        self::assertSame(0, $this->int("SELECT COUNT(*) FROM nowo_crm_external_ref WHERE external_id = '2003'"));
        self::assertSame(1, $this->int("SELECT COUNT(*) FROM nowo_crm_external_ref WHERE external_id = '2001'"));
    }

    public function testAWebRequestNeverWaitsAndTheResumeCommandFinishes(): void
    {
        $fake = $this->hubspot(['import' => ['hubspot' => ['pages_per_request' => 5]]]);
        $fake->seedContacts(25);
        $this->connect($fake);
        $fake->failNext('/crm/v3/objects/contacts', 429, ['retry-after' => '3']);
        $id = $this->startAndConfirm();
        self::assertFalse($this->imports()->run($id, 5));
        $view = $this->imports()->preview($id);
        self::assertSame([ImportStatus::Running, 'hubspot_rate_limited', true], [$view->status, $view->errorCode, $view->awaitingPerson]);
        self::assertEquals($this->clock()->now()->modify('+3 seconds')->format('c'), $view->resumeAfter?->setTimezone($this->clock()->now()->getTimezone())->format('c'));
        $resume = new CommandTester((new Application($this->kernel))->find('nowo:crm-kit:import:resume'));
        $resume->execute([]);
        self::assertStringContainsString('imports finished: 0', $resume->getDisplay(), 'not due yet');
        $this->clock()->modify('+5 seconds');
        // Unattended: a burst limit is waited out (the mock clock moves) and retried.
        $fake->failNext('/crm/v3/objects/contacts', 429, ['retry-after' => '1']);
        $fake->failNext('/crm/v3/objects/contacts', 503);
        $resume->execute([]);
        self::assertStringContainsString('imports finished: 1', $resume->getDisplay());
        self::assertSame(25, $this->imports()->preview($id)->counts['created']);
        self::assertNull($this->imports()->preview($id)->errorCode);
    }

    public function testRefusalsPauseTheImportAndANewKeyResumesIt(): void
    {
        $fake = $this->hubspot(['import' => ['hubspot' => ['rate_limit' => ['max_attempts' => 2, 'max_retry_after' => 5]]]]);
        $fake->seedContacts(5);
        $version = $this->connect($fake);
        $fake->failNext('/crm/v3/owners', 401);
        $id = $this->startAndConfirm();
        self::assertFalse($this->imports()->run($id, 5));
        self::assertSame('hubspot_token_invalid', $this->imports()->preview($id)->errorCode);
        self::assertSame('invalid', $this->connections()->status()?->status->value);
        $this->expectRefused(fn () => $this->hubImports()->lists(), InvalidInputException::class);
        $resume = new CommandTester((new Application($this->kernel))->find('nowo:crm-kit:import:resume'));
        $resume->execute([]);
        self::assertSame(ImportStatus::Running, $this->imports()->preview($id)->status, 'no resume without a new key');
        $this->connections()->connect($fake->token, null, $this->connections()->status()?->version ?? $version);
        // Daily limit: waits until the next UTC day.
        $fake->failNext('/crm/v3/owners', 429, [], '{"policyName":"DAILY"}');
        $resume->execute([]);
        self::assertSame('hubspot_rate_limited', $this->imports()->preview($id)->errorCode);
        self::assertSame('00:01', $this->imports()->preview($id)->resumeAfter?->format('H:i'));
        // "Continue" takes a waiting import at once; a 403 pauses without a time; a bad answer too.
        $fake->failNext('/crm/v3/owners', 403);
        self::assertFalse($this->imports()->run($id, 1));
        self::assertSame(['hubspot_scope_missing', null], [$this->imports()->preview($id)->errorCode, $this->imports()->preview($id)->resumeAfter]);
        $fake->failNext('/crm/v3/owners', 302, ['location' => 'http://169.254.169.254/']);
        self::assertFalse($this->imports()->run($id, 1));
        self::assertSame('hubspot_bad_response', $this->imports()->preview($id)->errorCode);
        // An outage beyond max_attempts in an unattended run pauses for a minute.
        $this->clock()->modify('+2 days');
        $fake->failNext('/crm/v3/owners', 502);
        $fake->failNext('/crm/v3/owners', 504);
        $this->connection()->executeStatement('UPDATE nowo_crm_import SET resume_after = ? WHERE id = ?', [$this->clock()->now()->format('Y-m-d H:i:s'), $id]);
        $resume->execute([]);
        self::assertSame('hubspot_unavailable', $this->imports()->preview($id)->errorCode);
        self::assertTrue($this->imports()->run($id));
        self::assertSame(5, $this->imports()->preview($id)->counts['created']);
    }

    public function testDisconnectAccountChangeAndTooManyRecordsFailTheImport(): void
    {
        $fake = $this->hubspot(['import' => ['hubspot' => ['max_records' => 5]]]);
        $fake->seedContacts(4);
        $this->connect($fake);
        $id = $this->hubImports()->start(new HubSpotImportScope([HubSpotObject::Contact]));
        $this->expectRefused(fn () => $this->hubImports()->start(new HubSpotImportScope([HubSpotObject::Contact])), ConflictException::class);
        $this->imports()->cancel($id);
        self::assertTrue($this->imports()->run($this->startAndConfirm()));
        $fake->seedContacts(10, 3001);
        $id = $this->startAndConfirm();
        self::assertFalse($this->imports()->run($id));
        self::assertSame([ImportStatus::Failed, 'hubspot_too_many'], [$this->imports()->preview($id)->status, $this->imports()->preview($id)->errorCode]);
        $fake->contacts = [];
        $fake->seedContacts(2);
        // The key is replaced by one of another account while an import runs.
        $id            = $this->startAndConfirm();
        $fake->account = '9090';
        $view          = $this->connections()->connect($fake->token, null, $this->connections()->status()?->version, true);
        self::assertFalse($this->imports()->run($id));
        self::assertSame([ImportStatus::Failed, 'hubspot_account_changed'], [$this->imports()->preview($id)->status, $this->imports()->preview($id)->errorCode]);
        // Disconnected (and connected again: another connection) while an import runs.
        $id = $this->startAndConfirm();
        $this->connections()->disconnect($view->version);
        $this->connect($fake);
        self::assertFalse($this->imports()->run($id));
        self::assertSame('hubspot_disconnected', $this->imports()->preview($id)->errorCode);
    }

    public function testStartSchemaAndConfirmRefuseWhatTheyCannotUse(): void
    {
        $fake = $this->hubspot();
        $fake->seedContacts(1);
        $this->expectRefused(fn () => $this->hubImports()->start(new HubSpotImportScope([HubSpotObject::Contact])), InvalidInputException::class);
        $this->connect($fake);
        $fake->lists = ['77' => ['name' => str_repeat('N', 70), 'type' => '0-1', 'members' => []], '79' => ['name' => 'Deals list', 'type' => '0-3', 'members' => []]];
        self::assertCount(1, $this->hubImports()->lists());
        $this->expectRefused(fn () => $this->hubImports()->start(new HubSpotImportScope([HubSpotObject::Contact], [new HubSpotObjectId('79')])), InvalidInputException::class);
        $this->expectRefused(fn () => $this->hubImports()->start(new HubSpotImportScope([HubSpotObject::Contact], [new HubSpotObjectId('12345')])), InvalidInputException::class);
        $id     = $this->hubImports()->start(new HubSpotImportScope([HubSpotObject::Contact], [new HubSpotObjectId('77')]));
        $schema = $this->hubImports()->schema($id);
        self::assertSame(['companies', 'contacts'], array_keys($schema->properties));
        self::assertNotContains('secret_note', array_map(static fn ($p): string => $p->name, $schema->properties['companies']), 'sensitive properties are never offered');
        $this->expectRefused(fn () => $this->hubImports()->confirm($id, new HubSpotMapping(['contacts' => ['secret_note' => 'phone']]), DuplicatePolicy::Skip, $schema->version), InvalidInputException::class);
        $this->expectRefused(fn () => $this->hubImports()->confirm($id, new HubSpotMapping(['contacts' => ['email' => 'custom.nope']]), DuplicatePolicy::Skip, $schema->version), InvalidInputException::class);
        $this->expectRefused(fn () => $this->hubImports()->confirm($id, new HubSpotMapping(['contacts' => ['email' => 'email']], ['78' => HubSpotMapping::CREATE]), DuplicatePolicy::Skip, $schema->version), InvalidInputException::class);
        $this->expectRefused(fn () => $this->hubImports()->confirm($id, new HubSpotMapping(['contacts' => ['email' => 'email']], ['77' => HubSpotMapping::CREATE]), DuplicatePolicy::Skip, $schema->version), InvalidInputException::class);
        $this->expectRefused(fn () => $this->hubImports()->confirm($id, new HubSpotMapping(['contacts' => ['email' => 'email']], ['77' => '01890000-0000-7000-8000-000000000000']), DuplicatePolicy::Skip, $schema->version), InvalidInputException::class);
        $this->expectRefused(fn () => $this->hubImports()->schema('not-an-id'), NotAvailableException::class);
        $this->expectRefused(fn () => $this->imports()->previewMapping($id, ['contacts' => 'email']), InvalidInputException::class);
        $this->service(FieldServiceInterface::class)->define(SubjectType::Contact, 'vip_level', 'VIP', FieldType::Text, [], false, 1);
        $this->hubImports()->confirm($id, new HubSpotMapping(['contacts' => ['email' => 'email', 'lastname' => 'last_name', 'vip_level' => 'custom.vip_level']]), DuplicatePolicy::Skip, $schema->version);
        $this->expectRefused(fn () => $this->hubImports()->schema($id), ConflictException::class);
        $this->expectRefused(fn () => $this->hubImports()->confirm($id, new HubSpotMapping([]), DuplicatePolicy::Skip, $schema->version), ConflictException::class);
    }

    public function testTheBackgroundRunAndTheQueuedConfirm(): void
    {
        $fake = $this->hubspot();
        $fake->seedContacts(30);
        $this->connect($fake);
        /** @var ImportService $service */
        $service = $this->service(ImportService::class);
        $id      = $this->startAndConfirm();
        $tenant  = new TenantRef('acme');
        self::assertSame(['stop', null], $service->runQueued($tenant, $id, 1), 'claimed and fresh: another runner has it');
        $this->connection()->executeStatement('UPDATE nowo_crm_import SET claim_token = NULL, resume_after = ? WHERE id = ?', [$this->clock()->now()->format('Y-m-d H:i:s'), $id]);
        self::assertSame(['more', null], $service->runQueued($tenant, $id, 1));
        $fake->failNext('/crm/v3/objects/contacts', 429, ['retry-after' => '120']);
        [$state, $at] = $service->runQueued($tenant, $id, 5);
        self::assertSame('wait', $state);
        self::assertNotNull($at);
        self::assertEquals(['wait', $at], $service->runQueued($tenant, $id, 5));
        $this->clock()->modify('+3 minutes');
        self::assertSame(['done', null], $service->runQueued($tenant, $id, 50));
        self::assertSame(['stop', null], $service->runQueued($tenant, $id, 50));
        self::assertSame(['stop', null], $service->runQueued($tenant, '01890000-0000-7000-8000-000000000000', 50));
    }

    private function hubImportsDone(): string
    {
        $id = $this->startAndConfirm([HubSpotObject::Contact], policy: DuplicatePolicy::Update);
        self::assertTrue($this->imports()->run($id));

        return $id;
    }

    /**
     * @param class-string<Throwable> $class
     */
    private function expectRefused(Closure $call, string $class): void
    {
        try {
            $call();
            self::fail('Expected ' . $class);
        } catch (Throwable $e) {
            self::assertInstanceOf($class, $e, $e->getMessage());
        }
    }
}
