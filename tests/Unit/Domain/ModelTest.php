<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Unit\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Nowo\CrmKitBundle\Domain\Enum\ActivityKind;
use Nowo\CrmKitBundle\Domain\Enum\ConsentSource;
use Nowo\CrmKitBundle\Domain\Enum\DuplicatePolicy;
use Nowo\CrmKitBundle\Domain\Enum\FieldType;
use Nowo\CrmKitBundle\Domain\Enum\ImportFormat;
use Nowo\CrmKitBundle\Domain\Enum\ImportStatus;
use Nowo\CrmKitBundle\Domain\Enum\RecordStatus;
use Nowo\CrmKitBundle\Domain\Enum\RowOutcome;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Model\Activity;
use Nowo\CrmKitBundle\Domain\Model\Affiliation;
use Nowo\CrmKitBundle\Domain\Model\Company;
use Nowo\CrmKitBundle\Domain\Model\Consent;
use Nowo\CrmKitBundle\Domain\Model\Contact;
use Nowo\CrmKitBundle\Domain\Model\Counter;
use Nowo\CrmKitBundle\Domain\Model\CrmSettings;
use Nowo\CrmKitBundle\Domain\Model\FieldDefinition;
use Nowo\CrmKitBundle\Domain\Model\HostLink;
use Nowo\CrmKitBundle\Domain\Model\Import;
use Nowo\CrmKitBundle\Domain\Model\ImportRow;
use Nowo\CrmKitBundle\Domain\Model\MergeRecord;
use Nowo\CrmKitBundle\Domain\Model\Note;
use Nowo\CrmKitBundle\Domain\Model\RecordFields;
use Nowo\CrmKitBundle\Domain\Model\Tag;
use Nowo\CrmKitBundle\Domain\Model\Tagging;
use Nowo\CrmKitBundle\Domain\Value\FieldKey;
use Nowo\CrmKitBundle\Domain\Value\TagName;
use PHPUnit\Framework\TestCase;

/**
 * The entities: derived keys, status changes, custom-field normalisation, settings bounds and the plain getters.
 */
final class ModelTest extends TestCase
{
    private const ID = '01900000-0000-7000-8000-000000000001';

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-11-18 10:00:00');
    }

    public function testCompaniesDeriveTheirKeys(): void
    {
        $company = new Company(self::ID, 'acme', $this->now, 'ana');
        self::assertSame(['name', 'website', 'tax_id'], $company->apply(['name' => 'ACME Tools', 'website' => 'https://WWW.Acme.test/x', 'tax_id' => 'b-12.34/5']));
        self::assertSame('acme tools', $company->getNameKey());
        self::assertSame('acme.test', $company->getDomainKey());
        self::assertSame('B12345', $company->getTaxIdKey());
        self::assertSame([], $company->apply(['name' => 'ACME Tools']));
        $company->apply(['website' => 'https://shop.acme.test', 'tax_id' => null, 'industry' => 'Tools', 'legal_name' => 'Acme SL', 'description' => 'D', 'email' => 'a@acme.test', 'phone' => '1', 'street' => 's', 'postal_code' => 'p', 'city' => 'c', 'region' => 'r', 'country' => 'ES']);
        self::assertSame('shop.acme.test', $company->getDomainKey());
        self::assertNull($company->getTaxIdKey());
        $company->apply(['website' => null]);
        self::assertNull($company->getDomainKey());
        self::assertSame('ACME Tools', $company->label());
        self::assertCount(13, $company->values());
        self::assertSame(SubjectType::Company, Company::type());
        self::assertSame('ana', $company->getCreatedById());
        self::assertSame($this->now, $company->getCreatedAt());
        self::assertSame(self::ID, $company->getId());
        self::assertSame('acme', $company->getTenantId());
    }

    public function testUnknownFieldsAreRefused(): void
    {
        $company = new Company(self::ID, 'acme', $this->now);
        try {
            $company->apply(['first_name' => 'x']);
            self::fail('accepted');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(LogicException::class);
        $company->value('first_name');
    }

    public function testContactsDeriveTheirKeysAndErase(): void
    {
        $contact = new Contact(self::ID, 'acme', $this->now);
        $contact->apply(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'Ada@Example.test', 'mobile' => '6', 'job_title' => 'CTO']);
        self::assertSame('Ada Lovelace', $contact->label());
        self::assertSame('lovelace ada', $contact->getSortKey());
        self::assertSame('ada@example.test', $contact->getEmailKey());
        self::assertSame(SubjectType::Contact, Contact::type());
        self::assertSame(['custom.vip'], $contact->changeCustom(['vip' => '1']));
        self::assertSame([], $contact->changeCustom(['vip' => '1']));
        self::assertSame(['custom.vip'], $contact->changeCustom([]));
        self::assertTrue($contact->changeOwner('ana'));
        self::assertFalse($contact->changeOwner('ana'));
        $contact->erase('Erased contact', $this->now, 'leo');
        self::assertSame(RecordStatus::Erased, $contact->getStatus());
        self::assertSame('Erased contact', $contact->label());
        self::assertNull($contact->getEmailKey());
        self::assertNull($contact->getOwnerId());
        self::assertSame($this->now, $contact->getErasedAt());
        self::assertSame('leo', $contact->getUpdatedById());
        $this->expectException(LogicException::class);
        $contact->value('website');
    }

    public function testStatusChanges(): void
    {
        $contact = new Contact(self::ID, 'acme', $this->now);
        $contact->apply(['last_name' => 'A', 'email' => 'a@x.test']);
        self::assertTrue($contact->isActive());
        $contact->trash($this->now, 'ana');
        self::assertSame($this->now, $contact->getTrashedAt());
        $contact->restore($this->now, 'ana');
        self::assertNull($contact->getTrashedAt());
        $contact->mergeInto('survivor', $this->now, 'ana');
        self::assertSame(RecordStatus::Merged, $contact->getStatus());
        self::assertSame('survivor', $contact->getMergedIntoId());
        self::assertNull($contact->getEmailKey());
        self::assertSame(1, $contact->getVersion());
        self::assertGreaterThan($this->now, $contact->getUpdatedAt(), 'a write in the same second moves the edit time on, so the version moves');
        $company = new Company(self::ID, 'acme', $this->now);
        $company->mergeInto('x', $this->now, null);
        self::assertSame('x', $company->getMergedIntoId());
        self::assertSame(array_keys(RecordFields::CONTACT), RecordFields::names(SubjectType::Contact));
    }

    public function testCustomFieldsNormalise(): void
    {
        $field = static fn (FieldType $type, bool $required = false): FieldDefinition => new FieldDefinition(self::ID, 'acme', SubjectType::Company, new FieldKey('k'), 'K', $type, ['Gold'], $required, 1, new DateTimeImmutable());
        self::assertSame('Hello', $field(FieldType::Text)->normalize(' Hello '));
        self::assertNull($field(FieldType::Text)->normalize(''));
        self::assertNull($field(FieldType::Text)->normalize(null));
        self::assertSame("a\nb", $field(FieldType::Textarea)->normalize("a\nb"));
        self::assertSame('12.50', $field(FieldType::Number)->normalize('12.50'));
        self::assertSame('7', $field(FieldType::Number)->normalize(7));
        self::assertSame('1.5', $field(FieldType::Number)->normalize(1.5));
        self::assertSame('2026-02-28', $field(FieldType::Date)->normalize('2026-02-28'));
        self::assertSame('2026-03-01', $field(FieldType::Date)->normalize(new DateTimeImmutable('2026-03-01')));
        self::assertSame('1', $field(FieldType::Boolean)->normalize(true));
        self::assertSame('0', $field(FieldType::Boolean)->normalize(false));
        self::assertSame('0', $field(FieldType::Boolean, true)->normalize(null));
        self::assertSame('1', $field(FieldType::Boolean)->normalize('1'));
        self::assertSame('Gold', $field(FieldType::Choice)->normalize('Gold'));
        self::assertSame('https://x.test', $field(FieldType::Url)->normalize('https://x.test'));
        self::assertSame('a@x.test', $field(FieldType::Email)->normalize('a@x.test'));
        foreach ([
            [FieldType::Text, "a\u{200B}b"], [FieldType::Textarea, "a\x07"], [FieldType::Number, '1,5'], [FieldType::Date, '2026-02-30'],
            [FieldType::Boolean, 'yes'], [FieldType::Choice, 'gold'], [FieldType::Url, 'ftp://x'], [FieldType::Email, 'x'], [FieldType::Text, ['a']], [FieldType::Text, "\xC3"], [FieldType::Number, true],
        ] as [$type, $value]) {
            try {
                $field($type)->normalize($value);
                self::fail('accepted ' . $type->value);
            } catch (InvalidArgumentException $e) {
                self::assertSame('field.error.type', $e->getMessage());
            }
        }
        $this->expectExceptionMessage('field.error.required');
        $field(FieldType::Text, true)->normalize(' ');
    }

    public function testFieldDefinitionGetters(): void
    {
        $field = new FieldDefinition(self::ID, 'acme', SubjectType::Contact, new FieldKey('tier'), 'Tier', FieldType::Choice, ['A', 'B'], true, 3, $this->now);
        $field->change('Level', ['A'], false, 4);
        $field->setArchived(true);
        self::assertSame([self::ID, 'acme', SubjectType::Contact, 'tier', 'Level', FieldType::Choice, ['A'], false, 4, true, 1, $this->now], [$field->getId(), $field->getTenantId(), $field->getSubjectType(), $field->getFieldKey(), $field->getLabel(), $field->getType(), $field->getChoices(), $field->isRequired(), $field->getPosition(), $field->isArchived(), $field->getVersion(), $field->getCreatedAt()]);
    }

    public function testSettingsBounds(): void
    {
        $settings = new CrmSettings('acme', $this->now);
        self::assertSame([30, 5, 30, 90, 1, null, 'acme', $this->now], [$settings->getTrashDays(), $settings->getTimelineYears(), $settings->getImportKeepDays(), $settings->getMergeKeepDays(), $settings->getVersion(), $settings->getUpdatedById(), $settings->getTenantId(), $settings->getUpdatedAt()]);
        $settings->change(7, 10, 1, 365, 'ana', $this->now);
        self::assertSame([7, 10, 1, 365, 'ana'], [$settings->getTrashDays(), $settings->getTimelineYears(), $settings->getImportKeepDays(), $settings->getMergeKeepDays(), $settings->getUpdatedById()]);
        $this->expectExceptionMessage('timelineYears');
        $settings->change(7, 11, 1, 1, null, $this->now);
    }

    public function testChildRows(): void
    {
        $now = $this->now;
        $a   = new Affiliation(self::ID, 'acme', 'c', 'k', 'CTO', false, $now);
        $a->setPrimary(true);
        $a->moveTo('c2', null);
        $a->moveTo(null, 'k2');
        self::assertSame([self::ID, 'acme', 'c2', 'k2', 'CTO', true, $now], [$a->getId(), $a->getTenantId(), $a->getContactId(), $a->getCompanyId(), $a->getRole(), $a->isPrimary(), $a->getCreatedAt()]);
        $tag = new Tag(self::ID, 'acme', new TagName('VIP'), 'red', $now);
        $tag->change(new TagName('Gold'), 'yellow');
        self::assertSame([self::ID, 'acme', 'Gold', 'gold', 'yellow', 1, $now], [$tag->getId(), $tag->getTenantId(), $tag->getName(), $tag->getNameKey(), $tag->getColour(), $tag->getVersion(), $tag->getCreatedAt()]);
        $tagging = new Tagging(self::ID, 'acme', 't', SubjectType::Company, 's', $now);
        $tagging->moveTo('s2');
        self::assertSame([self::ID, 'acme', 't', SubjectType::Company, 's2', $now], [$tagging->getId(), $tagging->getTenantId(), $tagging->getTagId(), $tagging->getSubjectType(), $tagging->getSubjectId(), $tagging->getCreatedAt()]);
        $note  = new Note(self::ID, 'acme', SubjectType::Contact, 's', 'Hi', false, 'ana', $now);
        $later = $now->modify('+1 hour');
        $note->edit('Bye', true, $later);
        $note->moveTo('s2');
        self::assertSame([self::ID, 'acme', SubjectType::Contact, 's2', 'Bye', true, 'ana', 1, $now, $later], [$note->getId(), $note->getTenantId(), $note->getSubjectType(), $note->getSubjectId(), $note->getBody(), $note->isPinned(), $note->getAuthorId(), $note->getVersion(), $note->getCreatedAt(), $note->getUpdatedAt()]);
        $activity = new Activity(self::ID, 'acme', SubjectType::Company, 's', ActivityKind::Created, 'ana', ['x' => 1], $now);
        $activity->moveTo('s2');
        self::assertSame([self::ID, 'acme', SubjectType::Company, 's2', ActivityKind::Created, 'ana', ['x' => 1], $now], [$activity->getId(), $activity->getTenantId(), $activity->getSubjectType(), $activity->getSubjectId(), $activity->getKind(), $activity->getActorId(), $activity->getParams(), $activity->getOccurredAt()]);
        $consent = new Consent(self::ID, 'acme', 'c', 'newsletter', true, ConsentSource::Form, 'web form', 'ana', $now);
        $consent->moveTo('c2');
        self::assertSame([self::ID, 'acme', 'c2', 'newsletter', true, ConsentSource::Form, 'web form', 'ana', $now], [$consent->getId(), $consent->getTenantId(), $consent->getContactId(), $consent->getPurpose(), $consent->isGranted(), $consent->getSource(), $consent->getEvidence(), $consent->getRecordedById(), $consent->getRecordedAt()]);
        $consent->anonymise();
        self::assertSame([null, null], [$consent->getEvidence(), $consent->getRecordedById()]);
        $link = new HostLink(self::ID, 'acme', SubjectType::Company, 's', 'project', 'p1', 'Web', 'ana', $now);
        $link->moveTo('s2');
        self::assertSame([self::ID, 'acme', SubjectType::Company, 's2', 'project', 'p1', 'Web', 'ana', $now], [$link->getId(), $link->getTenantId(), $link->getSubjectType(), $link->getSubjectId(), $link->getHostType(), $link->getHostId(), $link->getLabel(), $link->getCreatedById(), $link->getCreatedAt()]);
        $merge = new MergeRecord(self::ID, 'acme', SubjectType::Company, 's', 'm', ['a' => 1], ['name'], 'ana', $now);
        self::assertSame([self::ID, 'acme', SubjectType::Company, 's', 'm', ['a' => 1], ['name'], 'ana', $now], [$merge->getId(), $merge->getTenantId(), $merge->getSubjectType(), $merge->getSurvivorId(), $merge->getMergedId(), $merge->getSnapshot(), $merge->getTaken(), $merge->getActorId(), $merge->getMergedAt()]);
        $counter = new Counter('acme', 'contacts', 3);
        self::assertSame(['acme', 'contacts', 3], [$counter->getTenantId(), $counter->getResource(), $counter->getValue()]);
        $import = new Import(self::ID, 'acme', SubjectType::Contact, ImportFormat::Csv, 'f.csv', ['a'], ['a' => 'email'], 2, 'ana', $now);
        self::assertSame([self::ID, 'acme', SubjectType::Contact, ImportFormat::Csv, 'f.csv', ['a'], ['a' => 'email'], ImportStatus::Preview, DuplicatePolicy::Skip, 2, 0, null, null, null, 'ana', $now, 1], [$import->getId(), $import->getTenantId(), $import->getSubjectType(), $import->getFormat(), $import->getFileName(), $import->getColumns(), $import->getMapping(), $import->getStatus(), $import->getDuplicatePolicy(), $import->getRowsTotal(), $import->getRowsDone(), $import->getClaimToken(), $import->getClaimedAt(), $import->getFinishedAt(), $import->getCreatedById(), $import->getCreatedAt(), $import->getVersion()]);
        self::assertSame(['created' => 0, 'updated' => 0, 'duplicate' => 0, 'invalid' => 0, 'limit' => 0], $import->counts());
        $import->claim('t', ['a' => 'last_name'], DuplicatePolicy::Update, $now);
        self::assertSame([ImportStatus::Running, 't', $now, ['a' => 'last_name'], DuplicatePolicy::Update], [$import->getStatus(), $import->getClaimToken(), $import->getClaimedAt(), $import->getMapping(), $import->getDuplicatePolicy()]);
        $import->finish(ImportStatus::Done, $now);
        self::assertSame([ImportStatus::Done, null, $now], [$import->getStatus(), $import->getClaimToken(), $import->getFinishedAt()]);
        $row = new ImportRow(self::ID, 'i', 'acme', 1, ['a' => 'b']);
        self::assertSame([self::ID, 'i', 'acme', 1, ['a' => 'b'], RowOutcome::Pending, null, null], [$row->getId(), $row->getImportId(), $row->getTenantId(), $row->getRowNo(), $row->getData(), $row->getOutcome(), $row->getRecordId(), $row->getMessage()]);
        $row->settle(RowOutcome::Invalid, 'r', 'error.invalid');
        self::assertSame([RowOutcome::Invalid, 'r', 'error.invalid'], [$row->getOutcome(), $row->getRecordId(), $row->getMessage()]);
    }
}
