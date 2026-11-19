<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Unit\Application;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Application\CrmExporter;
use Nowo\CrmKitBundle\Application\CrmReader;
use Nowo\CrmKitBundle\Application\ImportMapping;
use Nowo\CrmKitBundle\Application\RecordValidator;
use Nowo\CrmKitBundle\Application\Shared\Effects;
use Nowo\CrmKitBundle\Application\Taggings;
use Nowo\CrmKitBundle\Domain\Enum\FieldType;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Event\RecordCreated;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Model\FieldDefinition;
use Nowo\CrmKitBundle\Domain\Port\CrmAuditSinkInterface;
use Nowo\CrmKitBundle\Domain\Value\AuditEntry;
use Nowo\CrmKitBundle\Domain\Value\FieldKey;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Nowo\TransactionContract\TransientStorageFailure;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;

use function str_repeat;

/**
 * Validation, import mapping, the after-commit buffer, the CSV writer and the transient-failure detector.
 */
final class ApplicationHelpersTest extends TestCase
{
    public function testCoreValuesAreCleaned(): void
    {
        self::assertSame(['name' => 'Acme', 'country' => 'ES', 'email' => null], RecordValidator::values(SubjectType::Company, ['name' => ' Acme ', 'country' => 'es', 'email' => ''], true));
        try {
            RecordValidator::values(SubjectType::Company, ['name' => '', 'first_name' => 'x', 'email' => 'nope', 'website' => 'ftp://x', 'phone' => 'call me', 'country' => 'E1', 'industry' => str_repeat('x', 81), 'city' => "Ma\u{200B}drid", 'legal_name' => "\xC3"], true);
            self::fail('accepted');
        } catch (InvalidInputException $e) {
            self::assertSame(['name' => 'error.required', 'first_name' => 'error.unknown_field', 'email' => 'error.email', 'website' => 'error.url', 'phone' => 'error.phone', 'country' => 'error.country', 'industry' => 'error.too_long', 'city' => 'error.invalid', 'legal_name' => 'error.invalid'], $e->errors);
        }
        try {
            RecordValidator::values(SubjectType::Company, [], true);
            self::fail('accepted');
        } catch (InvalidInputException $e) {
            self::assertSame(['name' => 'error.required'], $e->errors);
        }
        self::assertSame(['description' => "Line 1\nLine 2"], RecordValidator::values(SubjectType::Company, ['description' => "Line 1\nLine 2"], false));
        RecordValidator::contactName(['first_name' => 'A']);
        $this->expectException(InvalidInputException::class);
        RecordValidator::contactName(['first_name' => null, 'last_name' => null]);
    }

    public function testCustomValues(): void
    {
        $now  = new DateTimeImmutable();
        $tier = new FieldDefinition('01900000-0000-7000-8000-000000000001', 'acme', SubjectType::Company, new FieldKey('tier'), 'Tier', FieldType::Choice, ['A'], false, 1, $now);
        $old  = new FieldDefinition('01900000-0000-7000-8000-000000000002', 'acme', SubjectType::Company, new FieldKey('old'), 'Old', FieldType::Text, [], true, 2, $now);
        $old->setArchived(true);
        $gone = new FieldDefinition('01900000-0000-7000-8000-000000000003', 'acme', SubjectType::Company, new FieldKey('gone'), 'Gone', FieldType::Text, [], false, 3, $now);
        $gone->setArchived(true);
        self::assertSame(['tier' => 'A', 'old' => 'kept'], RecordValidator::custom(['tier' => 'A'], [$tier, $old, $gone], ['old' => 'kept']));
        self::assertSame([], RecordValidator::custom([], [$tier], []));
        try {
            RecordValidator::custom(['tier' => 'B', 'nope' => 'x'], [$tier], []);
            self::fail('accepted');
        } catch (InvalidInputException $e) {
            self::assertSame(['custom.tier' => 'field.error.type', 'custom.nope' => 'error.unknown_field'], $e->errors);
        }
    }

    public function testImportMapping(): void
    {
        $now     = new DateTimeImmutable();
        $vip     = new FieldDefinition('01900000-0000-7000-8000-000000000001', 'acme', SubjectType::Contact, new FieldKey('vip'), 'VIP', FieldType::Boolean, [], false, 1, $now);
        $targets = ImportMapping::targets(SubjectType::Contact, [$vip]);
        self::assertContains('company', $targets);
        self::assertContains('custom.vip', $targets);
        self::assertNotContains('company', ImportMapping::targets(SubjectType::Company, []));
        $mapping = ImportMapping::detect(['E-Mail', 'Surname', 'Given name', 'Organisation', 'Tags', 'VIP', 'email', 'Notes'], $targets);
        self::assertSame(['E-Mail' => 'email', 'Surname' => 'last_name', 'Given name' => 'first_name', 'Organisation' => 'company', 'Tags' => 'tags', 'VIP' => 'custom.vip', 'email' => '', 'Notes' => ''], $mapping);
        self::assertTrue(ImportMapping::valid($mapping, ['E-Mail', 'Surname', 'Given name', 'Organisation', 'Tags', 'VIP', 'email', 'Notes'], $targets));
        self::assertFalse(ImportMapping::valid(['X' => 'email'], ['E-Mail'], $targets));
        self::assertFalse(ImportMapping::valid(['E-Mail' => 'shoe_size'], ['E-Mail'], $targets));
        self::assertFalse(ImportMapping::valid(['E-Mail' => 'email', 'Mail' => 'email'], ['E-Mail', 'Mail'], $targets));
        self::assertFalse(ImportMapping::valid(['E-Mail' => ''], ['E-Mail'], $targets));
        [$input, $tags, $company] = ImportMapping::input(SubjectType::Contact, ['E-Mail' => 'a@x.test', 'Tags' => 'vip, , gold', 'Organisation' => ' Acme ', 'VIP' => '1'], ['E-Mail' => 'email', 'Tags' => 'tags', 'Organisation' => 'company', 'VIP' => 'custom.vip', 'Notes' => '', 'Bad' => 'website']);
        self::assertSame(['email' => 'a@x.test'], $input->values);
        self::assertSame(['vip' => '1'], $input->custom);
        self::assertSame(['vip', 'gold'], $tags);
        self::assertSame('Acme', $company);
        self::assertNull(ImportMapping::input(SubjectType::Contact, ['Organisation' => ' '], ['Organisation' => 'company'])[2]);
    }

    public function testEffectsAreReleasedOnceAfterTheCommit(): void
    {
        $effects = new Effects();
        $tenant  = new TenantRef('acme');
        $effects->event(new RecordCreated($tenant, null, new DateTimeImmutable(), SubjectType::Company, 'x'));
        $effects->audit(new AuditEntry($tenant, null, 'crm.x', null, null, [], new DateTimeImmutable()));
        $sink = new class implements CrmAuditSinkInterface {
            /** @var list<AuditEntry> */
            public array $entries = [];

            public function record(AuditEntry $entry): void
            {
                $this->entries[] = $entry;
            }
        };
        $dispatcher = new class implements EventDispatcherInterface {
            /** @var list<object> */
            public array $events = [];

            public function dispatch(object $event): object
            {
                $this->events[] = $event;

                return $event;
            }
        };
        $effects->release($dispatcher, $sink);
        $effects->release(null, $sink);
        self::assertCount(1, $sink->entries);
        self::assertCount(1, $dispatcher->events);
        $effects->event(new RecordCreated($tenant, null, new DateTimeImmutable(), SubjectType::Company, 'y'));
        $effects->clear();
        $effects->release($dispatcher, $sink);
        self::assertCount(1, $dispatcher->events);
    }

    public function testCsvLinesNeutraliseFormulas(): void
    {
        self::assertSame("\"a\",\"'=1+2\",\"'+x\",\"'-1\",\"'@x\",\"'\tx\",\"say \"\"hi\"\"\",\"\"\r\n", CrmExporter::line(['a', '=1+2', '+x', '-1', '@x', "\tx", 'say "hi"', '']));
    }

    public function testTransientFailuresAreDetectedInTheChain(): void
    {
        self::assertTrue(CrmReader::transient(new RuntimeException('x', 0, new TransientStorageFailure(null, 'deadlock'))));
        self::assertFalse(CrmReader::transient(new RuntimeException('x')));
        self::assertTrue(Taggings::distinct(['a', 'b']));
        self::assertFalse(Taggings::distinct(['a', 'a']));
    }
}
