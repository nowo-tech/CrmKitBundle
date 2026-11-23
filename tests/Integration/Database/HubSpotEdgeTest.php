<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use Closure;
use DateTimeImmutable;
use Nowo\CrmKitBundle\Application\HubSpot\HubSpotRunner;
use Nowo\CrmKitBundle\Application\HubSpot\HubSpotRunState;
use Nowo\CrmKitBundle\Domain\Enum\DuplicatePolicy;
use Nowo\CrmKitBundle\Domain\Enum\HubSpotObject;
use Nowo\CrmKitBundle\Domain\Enum\ImportFormat;
use Nowo\CrmKitBundle\Domain\Enum\ImportStatus;
use Nowo\CrmKitBundle\Domain\Enum\RowOutcome;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotSchemaView;
use Nowo\CrmKitBundle\Domain\Read\ImportRowView;
use Nowo\CrmKitBundle\Domain\Service\TagServiceInterface;
use Nowo\CrmKitBundle\Domain\Value\HubSpotAccountId;
use Nowo\CrmKitBundle\Domain\Value\HubSpotImportScope;
use Nowo\CrmKitBundle\Domain\Value\HubSpotMapping;
use Nowo\CrmKitBundle\Domain\Value\HubSpotObjectId;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Messenger\RunCrmImport;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Messenger\RunCrmImportHandler;
use Nowo\CrmKitBundle\Tests\Fixtures\HubSpot\FakeHubSpot;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Throwable;

use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * HubSpot edge cases (0.10.0): credentials that no longer open, racing writers (references, tags, the first
 * connection), a cursor moved under the runner, references to merged or vanished records, per-record permissions,
 * values that are refused, the plan limit, repeated associations and lists, the host's e-mail lookup and the refusals
 * of start, schema and confirm.
 */
final class HubSpotEdgeTest extends HubSpotTestCase
{
    public function testACredentialThatNoLongerOpensInvalidatesTheConnection(): void
    {
        $fake = $this->hubspot();
        $fake->seedContacts(2);
        $this->connect($fake);
        $id = $this->startAndConfirm();
        $this->connection()->executeStatement("UPDATE nowo_crm_hubspot_connection SET token_sealed = 'AAAA'");
        self::assertFalse($this->imports()->run($id, 1));
        self::assertSame('hubspot_token_invalid', $this->imports()->preview($id)->errorCode);
        self::assertSame('invalid', $this->connections()->status()?->status->value);
        $this->expectRefusal(fn (): array => $this->hubImports()->lists(), InvalidInputException::class);
    }

    public function testRacingWritersAreReplayedOnceThenRefused(): void
    {
        $fake = $this->hubspot();
        $fake->seedContacts(2);
        $this->ctx()->races['HubSpotConnection'] = 1;
        $this->expectRefusal(fn (): int => $this->connect($fake), ConflictException::class, ConflictException::STALE);
        $this->connection()->executeStatement('DELETE FROM nowo_crm_hubspot_connection');
        $this->connect($fake);
        // Another runner stored the reference first (its record is gone): the page settles again and takes it over.
        $id                                = $this->startAndConfirm();
        $this->ctx()->races['ExternalRef'] = 1;
        self::assertTrue($this->imports()->run($id));
        self::assertSame(2, $this->int("SELECT COUNT(*) FROM nowo_crm_external_ref WHERE object_type = 'contact'"));
        self::assertSame(0, $this->int("SELECT COUNT(*) FROM nowo_crm_external_ref WHERE target_id = '01900000-0000-7000-8000-ffffffffffff'"));
        // Twice: a write conflict.
        $fake->seedContacts(1, 3001);
        $id                                = $this->startAndConfirm();
        $this->ctx()->races['ExternalRef'] = 2;
        $this->expectRefusal(fn (): bool => $this->imports()->run($id), ConflictException::class, ConflictException::WRITE_CONFLICT);
        // A racing tag of the same name at the confirm.
        $this->connection()->executeStatement('DELETE FROM nowo_crm_external_ref');
        $this->stop($id);
        $fake->lists               = ['77' => ['name' => 'Fans', 'type' => '0-1', 'members' => []]];
        $this->ctx()->races['Tag'] = 1;
        $this->expectRefusal(fn (): string => $this->startAndConfirm([HubSpotObject::Contact], ['77'], ['77' => HubSpotMapping::CREATE]), ConflictException::class, ConflictException::WRITE_CONFLICT);
    }

    public function testACursorMovedUnderTheRunnerStopsIt(): void
    {
        $fake = $this->hubspot();
        $fake->seedContacts(2);
        $this->connect($fake);
        $id              = $this->startAndConfirm();
        $token           = $this->text('SELECT claim_token FROM nowo_crm_import WHERE id = ?', [$id]);
        $fake->onRequest = function (string $path) use ($id): void {
            if ($path === '/crm/v3/owners') {
                $this->connection()->executeStatement('UPDATE nowo_crm_import SET run_cursor = ? WHERE id = ?', ['{"account": "4242", "stages": ["owners"], "stage": "done"}', $id]);
            }
        };
        self::assertSame(HubSpotRunner::LOST, $this->service(HubSpotRunner::class)->step(new TenantRef('acme'), $id, $token, 'ana', true));
        self::assertSame(0, $this->int('SELECT rows_done FROM nowo_crm_import WHERE id = ?', [$id]));
        // The claim taken over while HubSpot answered: the unit settles nothing.
        $this->connection()->executeStatement('UPDATE nowo_crm_import SET run_cursor = ? WHERE id = ?', [json_encode(HubSpotRunState::start(new HubSpotAccountId('4242'), new HubSpotImportScope([HubSpotObject::Contact]), [])->toArray(), JSON_THROW_ON_ERROR), $id]);
        $fake->onRequest = function (string $path) use ($id): void {
            if ($path === '/crm/v3/owners') {
                $this->connection()->executeStatement("UPDATE nowo_crm_import SET claim_token = 'other' WHERE id = ?", [$id]);
            }
        };
        self::assertSame(HubSpotRunner::LOST, $this->service(HubSpotRunner::class)->step(new TenantRef('acme'), $id, $token, 'ana', true));
        // A connection replaced (another id) while start() asked HubSpot.
        $fake->onRequest = function (string $path): void {
            if ($path === '/crm/v3/properties/companies') {
                $this->connection()->executeStatement("UPDATE nowo_crm_hubspot_connection SET id = '01900000-0000-7000-8000-00000000abcd'");
            }
        };
        $this->stop($id);
        $this->expectRefusal(fn (): string => $this->hubImports()->start(new HubSpotImportScope([HubSpotObject::Company])), InvalidInputException::class);
    }

    public function testReferencesToMergedOrVanishedRecordsAndPerRecordPermissions(): void
    {
        $fake = $this->hubspot();
        $fake->seedContacts(4);
        $fake->contacts[2001] = ['hubspot_owner_id' => '501'] + $fake->contacts[2001];
        $fake->owners         = ['501' => 'leo@owners.example'];
        $this->connect($fake);
        self::assertTrue($this->imports()->run($this->startAndConfirm()));
        $ids = [];
        foreach (['2001', '2002', '2003', '2004'] as $hid) {
            $ids[$hid] = $this->text('SELECT target_id FROM nowo_crm_external_ref WHERE external_id = ?', [$hid]);
        }
        self::assertSame('', $this->text('SELECT COALESCE(owner_id, \'\') FROM nowo_crm_contact WHERE id = ?', [$ids['2001']]));
        // 2001: merged meanwhile without the reference moving (a merge of 0.9) ⇒ the survivor is updated.
        $survivor = $this->contact('Sur', 'Vivor', 'survivor@x.test');
        $this->connection()->executeStatement("UPDATE nowo_crm_contact SET status = 'merged', merged_into_id = ?, email = NULL, email_key = NULL WHERE id = ?", [$survivor, $ids['2001']]);
        // 2002: its record vanished ⇒ created again, the reference follows. 2003: no edit right. 2004: its new e-mail
        // belongs to another contact.
        $this->connection()->executeStatement('DELETE FROM nowo_crm_contact WHERE id = ?', [$ids['2002']]);
        $this->ctx()->denied[] = 'edit:' . $ids['2003'];
        $this->contact('Other', 'Person', 'taken@x.test');
        $fake->contacts[2004]        = ['email' => 'taken@x.test'] + $fake->contacts[2004];
        $this->ctx()->emails['acme'] = ['leo' => 'leo@owners.example'];
        $id                          = $this->startAndConfirm();
        self::assertTrue($this->imports()->run($id));
        $view = $this->imports()->preview($id);
        self::assertSame([RowOutcome::Updated, RowOutcome::Created, RowOutcome::Invalid, RowOutcome::Invalid], array_map(static fn (ImportRowView $r): RowOutcome => $r->outcome, $view->rows));
        self::assertSame(['error.forbidden', 'error.email_taken'], [$view->rows[2]->message, $view->rows[3]->message]);
        self::assertSame($survivor, $view->rows[0]->recordId);
        self::assertSame($survivor, $this->text("SELECT target_id FROM nowo_crm_external_ref WHERE external_id = '2001'"));
        self::assertSame('leo', $this->text('SELECT owner_id FROM nowo_crm_contact WHERE id = ?', [$survivor]), 'the owner is set on update');
        self::assertNotSame($ids['2002'], $this->text("SELECT target_id FROM nowo_crm_external_ref WHERE external_id = '2002'"));
    }

    public function testRefusedValuesThePlanLimitAndRepeatedAssociationsAndLists(): void
    {
        $fake = $this->hubspot(['actor_email_lookup' => 'test.lookup']);
        $fake->seedCompanies(3);
        $fake->companies[1003] = ['name' => ''] + $fake->companies[1003];
        $fake->seedContacts(3);
        $fake->contacts[2001]            = ['hubspot_owner_id' => '601'] + $fake->contacts[2001];
        $fake->owners                    = ['601' => 'zoe@owners.example', '602' => 'ghost@owners.example'];
        $fake->associations              = ['2001' => [['1001', true], ['1002', true]], '2002' => [['1003', false]]];
        $fake->lists                     = ['77' => ['name' => 'Fans', 'type' => '0-1', 'members' => ['2001', '2002']], '78' => ['name' => 'Skipped', 'type' => '0-1', 'members' => ['2001']]];
        $this->ctx()->limits['contacts'] = 2;
        $this->connect($fake);
        $id = $this->startAndConfirm([HubSpotObject::Contact], ['77', '78'], ['77' => HubSpotMapping::CREATE]);
        self::assertTrue($this->imports()->run($id));
        $view = $this->imports()->preview($id);
        self::assertSame([RowOutcome::Created, RowOutcome::Created, RowOutcome::Invalid, RowOutcome::Created, RowOutcome::Created, RowOutcome::Limit], array_map(static fn (ImportRowView $r): RowOutcome => $r->outcome, $view->rows));
        self::assertSame('error.required', $view->rows[2]->message);
        self::assertSame('zoe', $this->text("SELECT c.owner_id FROM nowo_crm_contact c JOIN nowo_crm_external_ref r ON r.target_id = c.id WHERE r.external_id = '2001'"), 'the host lookup, its other keys dropped');
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_affiliation WHERE is_primary = ?', [true]), 'one primary company per contact');
        // Again: the affiliations, tags and the list's tag exist; a trashed company gets no affiliation; the list's
        // tag was deleted ⇒ a new one takes its reference.
        $this->ctx()->limits = [];
        $company             = $this->text("SELECT target_id FROM nowo_crm_external_ref WHERE external_id = '1002'");
        $this->records()->trash(SubjectType::Company, $company, 1);
        $fake->associations['2002'] = [['1002', false]];
        $tag                        = $this->text("SELECT target_id FROM nowo_crm_external_ref WHERE object_type = 'list'");
        $id                         = $this->startAndConfirm([HubSpotObject::Contact], ['77'], ['77' => HubSpotMapping::CREATE]);
        self::assertSame($tag, $this->text("SELECT target_id FROM nowo_crm_external_ref WHERE object_type = 'list'"), 'the list keeps its tag');
        self::assertTrue($this->imports()->run($id));
        self::assertSame(2, $this->int('SELECT COUNT(*) FROM nowo_crm_affiliation'));
        self::assertSame(2, $this->int('SELECT COUNT(*) FROM nowo_crm_tagging WHERE tag_id = ?', [$tag]));
        $this->service(TagServiceInterface::class)->delete($tag);
        $id = $this->startAndConfirm([HubSpotObject::Contact], ['77'], ['77' => HubSpotMapping::CREATE]);
        self::assertNotSame($tag, $this->text("SELECT target_id FROM nowo_crm_external_ref WHERE object_type = 'list'"));
        self::assertTrue($this->imports()->run($id));
    }

    public function testTheRefusalsOfStartSchemaAndConfirm(): void
    {
        $fake         = $this->hubspot();
        $fake->scopes = FakeHubSpot::REQUIRED;
        $fake->lists  = ['77' => ['name' => 'Fans', 'type' => '0-1', 'members' => []]];
        $this->connect($fake);
        self::assertSame([], $this->hubImports()->lists(), 'no lists scope, no lists');
        $this->expectRefusal(fn (): string => $this->hubImports()->start(new HubSpotImportScope([HubSpotObject::Contact], [new HubSpotObjectId('77')])), InvalidInputException::class);
        $fake->failNext('/crm/v3/objects/companies/search', 502);
        $this->expectRefusal(fn (): string => $this->hubImports()->start(new HubSpotImportScope([HubSpotObject::Company])), InvalidInputException::class);
        $fake->scopes = [...FakeHubSpot::REQUIRED, 'crm.lists.read'];
        $this->connections()->connect($fake->token, null, $this->connections()->status()?->version);
        $id = $this->hubImports()->start(new HubSpotImportScope([HubSpotObject::Contact], [new HubSpotObjectId('77')]));
        $fake->failNext('/crm/v3/lists/search', 503);
        $this->expectRefusal(fn (): HubSpotSchemaView => $this->hubImports()->schema($id), InvalidInputException::class);
        $schema = $this->hubImports()->schema($id);
        $fake->failNext('/crm/v3/lists/search', 503);
        $this->expectRefusal(fn () => $this->hubImports()->confirm($id, new HubSpotMapping(['contacts' => ['email' => 'email']], ['77' => HubSpotMapping::CREATE]), DuplicatePolicy::Skip, $schema->version), InvalidInputException::class);
        $fake->failNext('/crm/v3/properties/contacts', 503);
        $this->expectRefusal(fn () => $this->hubImports()->confirm($id, new HubSpotMapping(['contacts' => ['email' => 'email']]), DuplicatePolicy::Skip, $schema->version), InvalidInputException::class);
        $this->expectRefusal(fn () => $this->hubImports()->confirm($id, new HubSpotMapping(['contacts' => ['email' => 'email']]), DuplicatePolicy::Skip, $schema->version + 3), ConflictException::class, ConflictException::IMPORT_RUNNING);
        $this->ctx()->grants = [];
        $this->actAs('ana', 'view', 'edit');
        $this->ctx()->denied[] = 'edit';
        self::assertSame(ImportStatus::Preview, $this->imports()->preview($id)->status);
    }

    public function testTheBackgroundRunThroughMessenger(): void
    {
        $fake = $this->hubspot(['import' => ['messenger' => ['enabled' => true, 'units' => 2]]], static function (ContainerConfigurator $container): void {
            $container->extension('framework', ['messenger' => ['transports' => ['memory' => 'in-memory://'], 'routing' => [RunCrmImport::class => 'memory']]]);
        });
        $fake->seedContacts(25);
        $this->connect($fake);
        $id = $this->startAndConfirm();
        self::assertSame(['', true], [$this->text('SELECT COALESCE(claim_token, \'\') FROM nowo_crm_import WHERE id = ?', [$id]), $this->imports()->preview($id)->resumeAfter instanceof DateTimeImmutable], 'queued, unclaimed');
        self::assertFalse($this->imports()->run($id, 5), 'Continue dispatches');
        $services = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $services);
        $transport = $services->get('messenger.transport.memory');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $handler = $this->service(RunCrmImportHandler::class);
        for ($i = 0; $i < 20 && $this->imports()->preview($id)->status === ImportStatus::Running; ++$i) {
            $sent = $transport->getSent();
            $transport->reset();
            foreach ($sent as $envelope) {
                $message = $envelope->getMessage();
                self::assertInstanceOf(RunCrmImport::class, $message);
                $handler($message);
            }
        }
        self::assertSame(ImportStatus::Done, $this->imports()->preview($id)->status);
        self::assertSame(25, $this->imports()->preview($id)->counts['created']);
        // A paused import is dispatched with its wait; a wrong tenant id is dropped.
        $id = $this->startAndConfirm();
        $this->connection()->executeStatement('UPDATE nowo_crm_import SET resume_after = ? WHERE id = ?', ['2030-01-01 00:00:00', $id]);
        self::assertFalse($this->imports()->run($id, 5));
        $delayed = $transport->getSent()[0] ?? null;
        self::assertNotNull($delayed);
        self::assertGreaterThan(0, $delayed->last(DelayStamp::class)?->getDelay());
        $handler(new RunCrmImport($id, ' bad tenant '));
        $fake->failNext('/crm/v3/owners', 429, ['retry-after' => '600']);
        $this->connection()->executeStatement('UPDATE nowo_crm_import SET resume_after = ? WHERE id = ?', [$this->clock()->now()->format('Y-m-d H:i:s'), $id]);
        $transport->reset();
        $handler(new RunCrmImport($id, 'acme'));
        self::assertCount(1, $transport->getSent(), 'paused by HubSpot: dispatched again with the wait');
        // A CSV import is queued too.
        $csv = $this->imports()->upload(SubjectType::Contact, ImportFormat::Csv, 'a.csv', "email,last_name\na@x.test,A\n");
        $this->imports()->confirm($csv, ['email' => 'email', 'last_name' => 'last_name'], DuplicatePolicy::Skip, 1);
        self::assertSame('', $this->text('SELECT COALESCE(claim_token, \'\') FROM nowo_crm_import WHERE id = ?', [$csv]));
    }

    /**
     * Ends an import whose claim is fresh (a person would wait for it to go stale).
     */
    private function stop(string $id): void
    {
        $this->connection()->executeStatement("UPDATE nowo_crm_import SET status = 'cancelled', claim_token = NULL WHERE id = ?", [$id]);
    }

    /**
     * @param class-string<Throwable> $class
     */
    private function expectRefusal(Closure $call, string $class, ?string $code = null): void
    {
        try {
            $call();
            self::fail('Expected ' . $class);
        } catch (Throwable $e) {
            self::assertInstanceOf($class, $e, $e::class . ': ' . $e->getMessage());
            if ($code !== null && $e instanceof ConflictException) {
                self::assertSame($code, $e->errorCode);
            }
        }
    }
}
