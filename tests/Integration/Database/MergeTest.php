<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use Nowo\CrmKitBundle\Domain\Enum\ConsentSource;
use Nowo\CrmKitBundle\Domain\Enum\FieldType;
use Nowo\CrmKitBundle\Domain\Enum\RecordStatus;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Read\AffiliationView;
use Nowo\CrmKitBundle\Domain\Read\LinkView;
use Nowo\CrmKitBundle\Domain\Read\TagView;
use Nowo\CrmKitBundle\Domain\Service\ConsentServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\CrmReaderInterface;
use Nowo\CrmKitBundle\Domain\Service\FieldServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\HostLinkServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\MergeServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\NoteServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\RecordInput;
use Nowo\CrmKitBundle\Domain\Service\TagServiceInterface;
use Nowo\CrmKitBundle\Domain\Value\HostObjectRef;

/**
 * Merging duplicates (contract section 15.3): fields taken from the other record, children moved once (unique ones
 * de-duplicated), the other record a tombstone, the counter released, a snapshot kept; stale versions refused.
 */
final class MergeTest extends CrmTestCase
{
    public function testTwoContactsMerge(): void
    {
        $this->boot();
        $this->admin();
        $this->service(FieldServiceInterface::class)->define(SubjectType::Contact, 'tier', 'Tier', FieldType::Text, [], false, 1);
        $this->service(FieldServiceInterface::class)->define(SubjectType::Contact, 'shoe', 'Shoe', FieldType::Text, [], false, 2);
        $tags  = $this->service(TagServiceInterface::class);
        $vip   = $tags->create('VIP', 'red');
        $gold  = $tags->create('Gold', 'yellow');
        $acme  = $this->company('Acme');
        $beta  = $this->company('Beta');
        $keep  = $this->records()->create(new RecordInput(SubjectType::Contact, ['first_name' => 'Ada', 'last_name' => 'Lovelace'], null, ['tier' => 'A'], [$vip]));
        $other = $this->records()->create(new RecordInput(SubjectType::Contact, ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@x.test', 'phone' => '+34 1'], 'leo', ['tier' => 'B', 'shoe' => '38'], [$vip, $gold]));
        $this->records()->affiliate($keep, $acme, null, true);
        $this->records()->affiliate($other, $acme, 'dup', false);
        $this->records()->affiliate($other, $beta, null, true);
        $this->service(NoteServiceInterface::class)->add(SubjectType::Contact, $other, 'Moves');
        $links = $this->service(HostLinkServiceInterface::class);
        $links->link(SubjectType::Contact, $keep, new HostObjectRef('project', 'p1'));
        $links->link(SubjectType::Contact, $other, new HostObjectRef('project', 'p1'));
        $links->link(SubjectType::Contact, $other, new HostObjectRef('project', 'p2'));
        $this->service(ConsentServiceInterface::class)->record($other, 'newsletter', true, ConsentSource::Form);
        $merges  = $this->service(MergeServiceInterface::class);
        $preview = $merges->preview(SubjectType::Contact, $keep, $other);
        $byField = [];
        foreach ($preview->fields as $field) {
            $byField[$field->field] = $field->takeOther;
        }
        self::assertTrue($byField['email']);
        self::assertFalse($byField['first_name']);
        self::assertTrue($byField['custom.shoe']);
        self::assertFalse($byField['custom.tier']);
        self::assertSame(['notes' => 1, 'tags' => 2, 'links' => 2, 'affiliations' => 2, 'roles' => 0, 'activities' => 8, 'consents' => 1], $preview->moves);

        $merges->merge(SubjectType::Contact, $keep, $other, ['email', 'phone', 'custom.tier', 'custom.nope'], $preview->survivor->version, $preview->other->version);
        $reader = $this->service(CrmReaderInterface::class);
        $view   = $reader->record(SubjectType::Contact, $keep);
        self::assertSame(['ada@x.test', '+34 1', 'B', '38', 'leo'], [$view->values['email'], $view->values['phone'], $view->custom['tier'], $view->custom['shoe'], $view->ownerId]);
        self::assertSame(['Gold', 'VIP'], array_map(static fn (TagView $t): string => $t->name, $view->tags));
        self::assertSame([['Acme', true], ['Beta', false]], array_map(static fn (AffiliationView $a): array => [$a->companyName, $a->primary], $view->affiliations));
        self::assertSame(['Website', 'Shop'], array_map(static fn (LinkView $l): string => $l->label, $reader->links(SubjectType::Contact, $keep)));
        self::assertCount(1, $reader->notes(SubjectType::Contact, $keep));
        self::assertCount(1, $reader->consents($keep));
        $tomb = $reader->record(SubjectType::Contact, $other);
        self::assertSame([RecordStatus::Merged, $keep], [$tomb->status, $tomb->mergedIntoId]);
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_tagging WHERE subject_id = ?', [$other]));
        self::assertSame(1, $this->int("SELECT value FROM nowo_crm_counter WHERE resource = 'contacts'"));
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_merge'));
        self::assertContains('RecordsMerged', $this->recorder()->eventNames());
        $this->expectConflictCode(ConflictException::MERGE_STALE, static fn () => $merges->merge(SubjectType::Contact, $other, $keep, [], 1, 1));
        $this->expectConflictCode(ConflictException::MERGED, fn (): int => $this->records()->update($other, new RecordInput(SubjectType::Contact, ['city' => 'x']), 2));
    }

    public function testCompaniesAndRefusals(): void
    {
        $this->boot();
        $this->admin();
        $a   = $this->company('Acme');
        $b   = $this->company('ACME');
        $ada = $this->contact('Ada', 'L');
        $this->records()->affiliate($ada, $b, null, true);
        $merges = $this->service(MergeServiceInterface::class);
        $this->expectConflictCode(ConflictException::MERGE_STALE, static fn () => $merges->merge(SubjectType::Company, $a, $b, [], 1, 9));
        try {
            $merges->merge(SubjectType::Company, $a, $b, ['first_name'], 1, 1);
            self::fail('accepted');
        } catch (InvalidInputException $e) {
            self::assertSame(['take' => 'error.invalid'], $e->errors);
        }
        try {
            $merges->preview(SubjectType::Company, $a, $a);
            self::fail('accepted');
        } catch (InvalidInputException $e) {
            self::assertSame(['other' => 'error.merge_same'], $e->errors);
        }
        foreach (['nope', '01900000-0000-7000-8000-00000000ffff'] as $missing) {
            try {
                $merges->preview(SubjectType::Company, $a, $missing);
                self::fail('found');
            } catch (NotAvailableException) {
                $this->addToAssertionCount(1);
            }
        }
        $merges->merge(SubjectType::Company, $a, $b, ['name'], 1, 1);
        $view = $this->service(CrmReaderInterface::class)->record(SubjectType::Company, $a);
        self::assertSame('ACME', $view->label);
        self::assertSame(['Ada L'], array_map(static fn (AffiliationView $x): string => $x->contactName, $view->affiliations));
        $c = $this->company('Gamma');
        $this->records()->trash(SubjectType::Company, $c, 1);
        $this->expectConflictCode(ConflictException::TRASHED, static fn () => $merges->preview(SubjectType::Company, $a, $c));
        $this->expectConflictCode(ConflictException::MERGE_STALE, static fn () => $merges->merge(SubjectType::Company, $a, $c, [], 2, 2));
    }

    /**
     * @param callable(): mixed $call
     */
    private function expectConflictCode(string $code, callable $call): void
    {
        try {
            $call();
            self::fail('no conflict');
        } catch (ConflictException $e) {
            self::assertSame($code, $e->errorCode);
        }
    }
}
