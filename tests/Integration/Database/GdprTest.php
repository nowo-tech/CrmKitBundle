<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use Nowo\CrmKitBundle\Application\CrmPersonalData;
use Nowo\CrmKitBundle\Domain\Enum\ConsentSource;
use Nowo\CrmKitBundle\Domain\Enum\DuplicatePolicy;
use Nowo\CrmKitBundle\Domain\Enum\FieldType;
use Nowo\CrmKitBundle\Domain\Enum\ImportFormat;
use Nowo\CrmKitBundle\Domain\Enum\RecordStatus;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Service\ConsentServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\CrmExporterInterface;
use Nowo\CrmKitBundle\Domain\Service\CrmPersonalDataInterface;
use Nowo\CrmKitBundle\Domain\Service\CrmReaderInterface;
use Nowo\CrmKitBundle\Domain\Service\CrmSettingsInterface;
use Nowo\CrmKitBundle\Domain\Service\FieldServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\HostLinkServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\ImportServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\ListQuery;
use Nowo\CrmKitBundle\Domain\Service\MergeServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\NoteServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\RecordInput;
use Nowo\CrmKitBundle\Domain\Service\TagServiceInterface;
use Nowo\CrmKitBundle\Domain\Value\HostObjectRef;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

use function implode;
use function is_string;
use function iterator_to_array;
use function json_decode;
use function json_encode;

/**
 * GDPR (contract section 16): export ⇔ erasure parity, consent kept without evidence, retention purge, counters
 * rebuild, CSV and vCard exports.
 */
final class GdprTest extends CrmTestCase
{
    public function testExportThenEraseLeavesNothingPersonal(): void
    {
        $this->boot();
        $this->admin();
        $vip  = $this->service(TagServiceInterface::class)->create('VIP', 'red');
        $acme = $this->company('Acme');
        $ada  = $this->records()->create(new RecordInput(SubjectType::Contact, ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@x.test', 'phone' => '+34 600', 'city' => 'London'], 'leo', null, [$vip]));
        $dup  = $this->contact('Ada', 'Lovelace', 'ada2@x.test');
        $this->records()->affiliate($ada, $acme, 'CTO', true);
        $this->service(NoteServiceInterface::class)->add(SubjectType::Contact, $ada, 'Likes engines');
        $this->service(HostLinkServiceInterface::class)->link(SubjectType::Contact, $ada, new HostObjectRef('project', 'p1'));
        $this->service(ConsentServiceInterface::class)->record($ada, 'newsletter', true, ConsentSource::Form, 'web form');
        $reader = $this->service(CrmReaderInterface::class);
        $this->service(MergeServiceInterface::class)->merge(SubjectType::Contact, $ada, $dup, [], $reader->record(SubjectType::Contact, $ada)->version, 1);
        $data = $this->service(CrmPersonalDataInterface::class)->export($ada);
        $json = (string) json_encode($data);
        foreach (['ada@x.test', 'Likes engines', 'web form', 'VIP', 'Acme', 'CTO', 'p1', 'ada2@x.test'] as $expected) {
            self::assertStringContainsString($expected, $json);
        }
        self::assertContains('crm.contact.exported', $this->recorder()->actions());

        $this->service(CrmPersonalDataInterface::class)->erase($ada);
        $this->service(CrmPersonalDataInterface::class)->erase($ada);
        $after = (string) json_encode($this->service(CrmPersonalDataInterface::class)->export($ada));
        foreach (['ada@x.test', 'Likes engines', 'web form', 'VIP', 'CTO', 'p1', 'London', '+34 600', 'ada2@x.test', 'Lovelace'] as $gone) {
            self::assertStringNotContainsString($gone, $after);
        }
        $view = $this->service(CrmReaderInterface::class)->record(SubjectType::Contact, $ada);
        self::assertSame([RecordStatus::Erased, CrmPersonalData::PLACEHOLDER], [$view->status, $view->label]);
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_consent WHERE contact_id = ? AND evidence IS NULL', [$ada]));
        self::assertSame(0, $this->int("SELECT value FROM nowo_crm_counter WHERE resource = 'contacts'"));
        self::assertContains('ContactErased', $this->recorder()->eventNames());
    }

    public function testMergeAndErasureLeaveNoPersonalDataInTheRows(): void
    {
        $this->boot();
        $this->admin();
        $this->service(FieldServiceInterface::class)->define(SubjectType::Contact, 'tier', 'Tier', FieldType::Text, [], false, 1);
        $merges = $this->service(MergeServiceInterface::class);
        $reader = $this->service(CrmReaderInterface::class);
        $c      = $this->records()->create(new RecordInput(SubjectType::Contact, ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@x.test'], 'leo', ['tier' => 'A']));
        $a      = $this->records()->create(new RecordInput(SubjectType::Contact, ['first_name' => 'Ada', 'last_name' => 'King', 'email' => 'ada2@x.test', 'phone' => '+34 600', 'city' => 'London'], 'leo', ['tier' => 'B']));
        $b      = $this->contact('Augusta', 'Byron', 'ada3@x.test', ['mobile' => '+44 1']);
        // b → a, then a → c: a chain of two tombstones behind c.
        $merges->merge(SubjectType::Contact, $a, $b, [], $reader->record(SubjectType::Contact, $a)->version, 1);
        $merges->merge(SubjectType::Contact, $c, $a, ['phone'], $reader->record(SubjectType::Contact, $c)->version, $reader->record(SubjectType::Contact, $a)->version);
        $row = $this->connection()->fetchAssociative('SELECT first_name, last_name, display_name, email, email_key, phone, mobile, city, owner_id, custom, status, merged_into_id FROM nowo_crm_contact WHERE id = ?', [$a]);
        self::assertIsArray($row);
        self::assertSame(['merged', $c], [$row['status'], $row['merged_into_id']]);
        foreach (['first_name', 'last_name', 'email', 'email_key', 'phone', 'mobile', 'city', 'owner_id'] as $column) {
            self::assertNull($row[$column], 'the tombstone keeps no ' . $column);
        }
        self::assertSame('', $row['display_name']);
        self::assertSame([], json_decode(is_string($row['custom']) ? $row['custom'] : '', true));
        self::assertSame('+34 600', $reader->record(SubjectType::Contact, $c)->values['phone'], 'the survivor took it');
        self::assertSame(2, $this->int('SELECT COUNT(*) FROM nowo_crm_merge'), 'the snapshots are the audit copy');

        // A tombstone written before this rule (personal values left): the erasure of its survivor blanks it.
        $this->connection()->executeStatement("UPDATE nowo_crm_contact SET last_name = 'Byron', email = 'ada3@x.test', mobile = '+44 1' WHERE id = ?", [$b]);
        $this->service(CrmPersonalDataInterface::class)->erase($c);
        foreach ([$a, $b] as $tombstone) {
            self::assertSame(0, $this->int("SELECT COUNT(*) FROM nowo_crm_contact WHERE id = ? AND (last_name IS NOT NULL OR email IS NOT NULL OR mobile IS NOT NULL OR phone IS NOT NULL OR owner_id IS NOT NULL OR display_name <> '')", [$tombstone]));
            self::assertSame(1, $this->int("SELECT COUNT(*) FROM nowo_crm_contact WHERE id = ? AND status = 'merged'", [$tombstone]));
        }
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_merge'), 'merge traces of the erased person are gone');
        foreach (['ada@x.test', 'ada2@x.test', 'ada3@x.test', 'Lovelace', 'King', 'Byron', '+34 600', '+44 1', 'London'] as $gone) {
            self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_contact WHERE email = ? OR last_name = ? OR phone = ? OR mobile = ? OR city = ?', [$gone, $gone, $gone, $gone, $gone]), $gone);
        }
    }

    public function testRetentionPurgeAndCounterRebuild(): void
    {
        $this->boot();
        $this->admin();
        $this->service(CrmSettingsInterface::class)->update(7, 1, 1, 1, 0);
        $old  = $this->company('Old');
        $keep = $this->company('Keep');
        $this->service(NoteServiceInterface::class)->add(SubjectType::Company, $old, 'note');
        $this->records()->trash(SubjectType::Company, $old, 1);
        $imports = $this->service(ImportServiceInterface::class);
        $import  = $imports->upload(SubjectType::Company, ImportFormat::Csv, 'c.csv', "name\nNew\n");
        $imports->confirm($import, ['name' => 'name'], DuplicatePolicy::Skip, 1);
        $imports->run($import);
        $this->service(MergeServiceInterface::class)->merge(SubjectType::Company, $keep, $this->company('Dup'), [], 1, 1);
        $console = new Application($this->kernel);
        $dry     = new CommandTester($console->find('nowo:crm-kit:purge'));
        $dry->execute(['--dry-run' => true]);
        self::assertStringContainsString('records: 0', $dry->getDisplay());
        $this->clock()->modify('+2 years');
        $dry->execute(['--dry-run' => true, '--tenant' => 'acme']);
        self::assertStringContainsString('records: 1', $dry->getDisplay());
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_company WHERE id = ?', [$old]));
        $run = new CommandTester($console->find('nowo:crm-kit:purge'));
        self::assertSame(0, $run->execute([]));
        self::assertStringContainsString('records: 1', $run->getDisplay());
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_company WHERE id = ?', [$old]));
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_import_row'));
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_merge'));
        self::assertSame(0, $this->int("SELECT COUNT(*) FROM nowo_crm_company WHERE status = 'merged'"), 'tombstones past merge_keep_days');
        self::assertStringContainsString('merge tombstones: 1', $run->getDisplay());
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_activity'));
        self::assertContains('crm.retention.purged', $this->recorder()->actions());
        self::assertSame(2, $run->execute(['--tenant' => 'bad ']));

        $this->connection()->executeStatement("UPDATE nowo_crm_counter SET value = 99 WHERE resource = 'companies'");
        $rebuild = new CommandTester($console->find('nowo:crm-kit:counters:rebuild'));
        self::assertSame(0, $rebuild->execute([]));
        self::assertStringContainsString('acme companies: 2', $rebuild->getDisplay());
        self::assertSame(2, $this->int("SELECT value FROM nowo_crm_counter WHERE resource = 'companies'"));
    }

    public function testCsvAndVcardExports(): void
    {
        $this->boot();
        $this->admin();
        $acme = $this->company('=Acme', ['city' => 'Madrid']);
        for ($i = 0; $i < 3; ++$i) {
            $this->company('C' . $i);
        }
        $ada = $this->contact('Ada', 'Love;lace', 'ada@x.test', ['job_title' => 'Count, Engineer', 'street' => 'Main 1', 'city' => 'London']);
        $this->records()->affiliate($ada, $acme, null, true);
        $csv = implode('', iterator_to_array($this->service(CrmExporterInterface::class)->csv(new ListQuery(SubjectType::Company)), false));
        self::assertStringStartsWith('"id","name","legal_name"', $csv);
        self::assertStringContainsString("\"'=Acme\"", $csv);
        $vcard = $this->service(CrmExporterInterface::class)->vcard($ada);
        self::assertStringContainsString("N:Love\;lace;Ada;;;\r\n", $vcard);
        self::assertStringContainsString("TITLE:Count\\, Engineer\r\n", $vcard);
        self::assertStringContainsString("ORG:=Acme\r\n", $vcard);
        self::assertStringContainsString("ADR:;;Main 1;London;;;\r\n", $vcard);
        self::assertStringContainsString('UID:urn:uuid:' . $ada, $vcard);
    }
}
