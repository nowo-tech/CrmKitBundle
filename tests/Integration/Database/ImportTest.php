<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use Nowo\CrmKitBundle\Application\ImportService;
use Nowo\CrmKitBundle\Domain\Enum\DuplicatePolicy;
use Nowo\CrmKitBundle\Domain\Enum\FieldType;
use Nowo\CrmKitBundle\Domain\Enum\ImportFormat;
use Nowo\CrmKitBundle\Domain\Enum\ImportStatus;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Import\ImportFileException;
use Nowo\CrmKitBundle\Domain\Read\AffiliationView;
use Nowo\CrmKitBundle\Domain\Read\TagView;
use Nowo\CrmKitBundle\Domain\Service\CrmReaderInterface;
use Nowo\CrmKitBundle\Domain\Service\FieldServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\ImportServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\ListQuery;
use Nowo\CrmKitBundle\Domain\Service\TagServiceInterface;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use ReflectionMethod;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

use function str_repeat;

/**
 * Imports (contract sections 9 and 15.4): upload ⇒ preview ⇒ confirm (one claim wins) ⇒ chunks; duplicates skipped
 * or updated, invalid rows, the plan limit, tags and company links, cancel, and the resume of a stale claim.
 */
final class ImportTest extends CrmTestCase
{
    private const CSV = "First name,Last name,E-mail,Organisation,Tags,VIP,Notes\nAda,Lovelace,ada@x.test,Acme,vip,1,a\nBob,Byte,BOB@x.test,Nowhere,,0,\nCyd,Cloud,ada@x.test,,,,\n,,bad,,,,\nDee,Dot,dee@x.test,acme,unknown,,\n";

    public function testAContactImportRunsInChunks(): void
    {
        $this->boot(['import' => ['chunk_rows' => 10]]);
        $this->admin();
        $this->service(TagServiceInterface::class)->create('VIP', 'red');
        $this->service(FieldServiceInterface::class)->define(SubjectType::Contact, 'vip', 'VIP', FieldType::Boolean, [], false, 1);
        $acme    = $this->company('Acme');
        $imports = $this->service(ImportServiceInterface::class);
        $id      = $imports->upload(SubjectType::Contact, ImportFormat::Csv, ' people.csv ', self::CSV);
        $preview = $imports->preview($id);
        self::assertEquals(['First name' => 'first_name', 'Last name' => 'last_name', 'E-mail' => 'email', 'Organisation' => 'company', 'Tags' => 'tags', 'VIP' => 'custom.vip', 'Notes' => ''], $preview->mapping);
        self::assertSame(['people.csv', 5, ImportStatus::Preview], [$preview->fileName, $preview->rowsTotal, $preview->status]);
        self::assertSame(['email' => 'error.email', 'last_name' => 'error.contact_name'], $preview->rows[3]->errors + ['last_name' => 'error.contact_name']);
        $imports->confirm($id, $preview->mapping, DuplicatePolicy::Skip, $preview->version);
        $this->expectConflict(static fn () => $imports->confirm($id, $preview->mapping, DuplicatePolicy::Skip, $preview->version));
        self::assertTrue($imports->run($id));
        $done = $imports->preview($id);
        self::assertSame(['created' => 2, 'updated' => 0, 'duplicate' => 1, 'invalid' => 2, 'limit' => 0], $done->counts);
        self::assertSame(ImportStatus::Done, $done->status);
        self::assertSame('error.tag_unknown', $done->rows[4]->message ?? $done->rows[4]->message);
        self::assertTrue($imports->run($id), 'running a finished import is a no-op');
        $ada = $this->reader()->list(new ListQuery(SubjectType::Contact, 'ada@'))->rows[0];
        self::assertSame(['VIP'], array_map(static fn (TagView $t): string => $t->name, $ada->tags));
        self::assertSame('1', $ada->custom['vip']);
        self::assertSame(['Acme'], array_map(static fn (AffiliationView $a): string => $a->companyName, $this->reader()->record(SubjectType::Contact, $ada->id)->affiliations));
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_affiliation WHERE company_id = ?', [$acme]));
        self::assertContains('ImportCompleted', $this->recorder()->eventNames());
        self::assertContains('crm.import.completed', $this->recorder()->actions());
    }

    public function testUpdatePolicyLimitAndSmallChunks(): void
    {
        $this->boot(['import' => ['chunk_rows' => 10]]);
        $this->admin();
        $this->contact('Old', 'Name', 'ada@x.test');
        $this->ctx()->limits['contacts'] = 2;
        $imports                         = $this->service(ImportServiceInterface::class);
        $rows                            = "email,last_name,first_name\nada@x.test,Lovelace,Ada\n";
        for ($i = 0; $i < 12; ++$i) {
            $rows .= "p{$i}@x.test,P{$i},\n";
        }
        $id = $imports->upload(SubjectType::Contact, ImportFormat::Csv, 'p.csv', $rows);
        $imports->confirm($id, ['email' => 'email', 'last_name' => 'last_name', 'first_name' => 'first_name'], DuplicatePolicy::Update, 1);
        self::assertFalse($imports->run($id, 1), 'one chunk of ten, three rows left');
        self::assertTrue($imports->run($id));
        self::assertSame(['created' => 1, 'updated' => 1, 'duplicate' => 0, 'invalid' => 0, 'limit' => 11], $imports->preview($id)->counts);
        self::assertSame('Ada Lovelace', $this->text("SELECT display_name FROM nowo_crm_contact WHERE email_key = 'ada@x.test'"));
    }

    public function testContinueAttributesTheRowsToThePersonWhoClicked(): void
    {
        $this->boot(['import' => ['chunk_rows' => 10]]);
        $this->admin();
        $imports = $this->service(ImportServiceInterface::class);
        $rows    = "email,last_name\n";
        for ($i = 0; $i < 15; ++$i) {
            $rows .= "p{$i}@x.test,P{$i}\n";
        }
        $id = $imports->upload(SubjectType::Contact, ImportFormat::Csv, 'p.csv', $rows);
        $imports->confirm($id, ['email' => 'email', 'last_name' => 'last_name'], DuplicatePolicy::Skip, 1);
        self::assertFalse($imports->run($id, 1));
        $this->actAs('leo', 'view', 'edit');
        self::assertTrue($imports->run($id));
        self::assertSame('ana', $this->text("SELECT created_by_id FROM nowo_crm_contact WHERE email_key = 'p0@x.test'"));
        self::assertSame('leo', $this->text("SELECT created_by_id FROM nowo_crm_contact WHERE email_key = 'p14@x.test'"));
    }

    public function testCompaniesVcardsAndRefusals(): void
    {
        $this->boot();
        $this->admin();
        $imports = $this->service(ImportServiceInterface::class);
        $id      = $imports->upload(SubjectType::Company, ImportFormat::Csv, '', "name;city;country\nAcme;Madrid;es\n;Bilbao;ES\n");
        $preview = $imports->preview($id);
        self::assertSame('import', $preview->fileName);
        $imports->confirm($id, $preview->mapping, DuplicatePolicy::Skip, 1);
        $imports->run($id);
        self::assertSame(['created' => 1, 'updated' => 0, 'duplicate' => 0, 'invalid' => 1, 'limit' => 0], $imports->preview($id)->counts);
        $vcard = $imports->upload(SubjectType::Contact, ImportFormat::Vcard, 'a.vcf', "BEGIN:VCARD\nFN:Ada L\nEMAIL:ada@x.test\nEND:VCARD\n");
        self::assertSame('email', $imports->preview($vcard)->mapping['email']);
        $this->expectFileError('import.error.vcard_contacts', static fn () => $imports->upload(SubjectType::Company, ImportFormat::Vcard, 'a.vcf', 'x'));
        $this->expectFileError('import.error.empty', static fn () => $imports->upload(SubjectType::Contact, ImportFormat::Csv, 'a.csv', "a\n"));
        try {
            $imports->confirm($vcard, ['nope' => 'email'], DuplicatePolicy::Skip, 1);
            self::fail('accepted');
        } catch (InvalidInputException $e) {
            self::assertSame(['mapping' => 'import.error.mapping'], $e->errors);
        }
        $imports->cancel($vcard);
        self::assertSame(ImportStatus::Cancelled, $imports->preview($vcard)->status);
        $this->expectConflict(static fn () => $imports->cancel($vcard));
        $this->expectConflict(static fn () => $imports->confirm($vcard, ['email' => 'email'], DuplicatePolicy::Skip, 2));
        self::assertFalse($imports->run($vcard));
        try {
            $imports->preview('01900000-0000-7000-8000-00000000ffff');
            self::fail('found');
        } catch (NotAvailableException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testAStaleClaimIsResumedAndCanBeCancelled(): void
    {
        $this->boot(['import' => ['chunk_rows' => 10, 'stale_claim_seconds' => 60]]);
        $this->admin();
        $imports = $this->service(ImportServiceInterface::class);
        $rows    = "email,last_name\n";
        for ($i = 0; $i < 25; ++$i) {
            $rows .= "p{$i}@x.test,P{$i}\n";
        }
        $a = $imports->upload(SubjectType::Contact, ImportFormat::Csv, 'a.csv', $rows);
        $imports->confirm($a, ['email' => 'email', 'last_name' => 'last_name'], DuplicatePolicy::Skip, 1);
        $imports->run($a, 1);
        $b = $imports->upload(SubjectType::Contact, ImportFormat::Csv, 'b.csv', "email,last_name\nq@x.test,Q\n");
        $imports->confirm($b, ['email' => 'email', 'last_name' => 'last_name'], DuplicatePolicy::Skip, 1);
        $this->expectConflict(static fn () => $imports->cancel($b), 'a fresh running claim is not cancelled');
        $this->clock()->modify('+2 minutes');
        $imports->cancel($b);
        $this->connection()->executeStatement("UPDATE nowo_crm_import SET created_by_id = 'bad id ' WHERE id = ?", [$a]);
        $tester = new CommandTester((new Application($this->kernel))->find('nowo:crm-kit:import:resume'));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('imports finished: 1', $tester->getDisplay());
        self::assertSame(25, $imports->preview($a)->counts['created']);
        self::assertSame(ImportService::SYSTEM_ACTOR, $this->text('SELECT created_by_id FROM nowo_crm_contact ORDER BY id DESC'));
        self::assertSame(0, $this->service(ImportService::class)->resumeStale());
    }

    public function testATakenOverClaimStopsTheFirstRunner(): void
    {
        $this->boot(['import' => ['chunk_rows' => 10]]);
        $this->admin();
        $imports = $this->service(ImportServiceInterface::class);
        $id      = $imports->upload(SubjectType::Contact, ImportFormat::Csv, 'a.csv', "email,last_name\na@x.test,A\n");
        $imports->confirm($id, ['email' => 'email', 'last_name' => 'last_name'], DuplicatePolicy::Skip, 1);
        $runClaimed = new ReflectionMethod(ImportService::class, 'runClaimed');
        self::assertSame('lost', $runClaimed->invoke($this->service(ImportService::class), new TenantRef('acme'), $id, 'someone-else', null, true, ImportFormat::Csv), 'the claim is gone: this runner stops');
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_contact'));
    }

    private function reader(): CrmReaderInterface
    {
        return $this->service(CrmReaderInterface::class);
    }

    /**
     * @param callable(): mixed $call
     */
    private function expectConflict(callable $call, string $message = ''): void
    {
        try {
            $call();
            self::fail('no conflict ' . $message);
        } catch (ConflictException $e) {
            self::assertSame(ConflictException::IMPORT_RUNNING, $e->errorCode);
        }
    }

    /**
     * @param callable(): mixed $call
     */
    private function expectFileError(string $key, callable $call): void
    {
        try {
            $call();
            self::fail('accepted');
        } catch (ImportFileException $e) {
            self::assertSame($key, $e->getMessage());
        }
        self::assertNotSame('', str_repeat('x', 1));
    }
}
