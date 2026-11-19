<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use Nowo\CrmKitBundle\Domain\Enum\ConsentSource;
use Nowo\CrmKitBundle\Domain\Enum\FieldType;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Read\ConsentView;
use Nowo\CrmKitBundle\Domain\Read\NoteView;
use Nowo\CrmKitBundle\Domain\Read\TagView;
use Nowo\CrmKitBundle\Domain\Service\ConsentServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\CrmReaderInterface;
use Nowo\CrmKitBundle\Domain\Service\CrmSettingsInterface;
use Nowo\CrmKitBundle\Domain\Service\FieldServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\NoteServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\TagServiceInterface;
use Throwable;

use function array_map;
use function str_repeat;

/**
 * Tags, custom fields, notes, consent and settings (contract sections 6 and 15).
 */
final class PartsTest extends CrmTestCase
{
    public function testTags(): void
    {
        $this->boot();
        $this->admin();
        $tags = $this->service(TagServiceInterface::class);
        $vip  = $tags->create(' VIP ', 'red');
        $this->expectError(['name' => 'error.tag_taken'], static fn () => $tags->create('vip', 'blue'));
        $this->expectError(['name' => 'error.tag_name'], static fn () => $tags->create(str_repeat('x', 61), 'blue'));
        $this->expectError(['colour' => 'error.invalid'], static fn () => $tags->create('Gold', 'pink'));
        $gold = $tags->create('Gold', 'yellow');
        $this->expectError(['name' => 'error.tag_taken'], static fn () => $tags->change($gold, 'VIP', 'yellow', 1));
        $tags->change($gold, 'Golden', 'orange', 1);
        $this->expectConflict(ConflictException::STALE, static fn () => $tags->change($gold, 'Gold', 'orange', 1));
        $acme = $this->company('Acme');
        $tags->assign(SubjectType::Company, $acme, [$vip, $gold]);
        $tags->assign(SubjectType::Company, $acme, [$vip, $gold]);
        self::assertSame(2, $this->int('SELECT COUNT(*) FROM nowo_crm_tagging'));
        $this->expectError(['tags' => 'error.invalid'], static fn () => $tags->assign(SubjectType::Company, $acme, [$vip, $vip]));
        $tags->delete($gold);
        self::assertSame(['VIP'], array_map(static fn (TagView $t): string => $t->name, $this->service(CrmReaderInterface::class)->record(SubjectType::Company, $acme)->tags));
        $this->expectThrow(NotAvailableException::class, static fn () => $tags->delete($gold));
        $this->expectThrow(NotAvailableException::class, static fn () => $tags->change('bad', 'x', 'red', 1));
        $this->ctx()->grants['acme']['ana'] = ['view', 'edit'];
        $this->expectThrow(AccessDeniedException::class, static fn () => $tags->create('New', 'red'));
    }

    public function testFields(): void
    {
        $this->boot();
        $this->admin();
        $fields = $this->service(FieldServiceInterface::class);
        $id     = $fields->define(SubjectType::Contact, 'tier', ' Tier ', FieldType::Choice, ['A', ' B ', 'A', ''], true, 2);
        $field  = $this->service(CrmReaderInterface::class)->fields(SubjectType::Contact)[0];
        self::assertSame(['Tier', ['A', 'B'], true], [$field->label, $field->choices, $field->required]);
        $this->expectError(['key' => 'error.field_key_taken'], static fn () => $fields->define(SubjectType::Contact, 'tier', 'X', FieldType::Text, [], false, 1));
        $this->expectError(['key' => 'error.field_key'], static fn () => $fields->define(SubjectType::Contact, 'Tier', 'X', FieldType::Text, [], false, 1));
        $this->expectError(['key' => 'error.field_key_reserved'], static fn () => $fields->define(SubjectType::Contact, 'email', 'X', FieldType::Text, [], false, 1));
        $this->expectError(['label' => 'error.invalid'], static fn () => $fields->define(SubjectType::Contact, 'x', ' ', FieldType::Text, [], false, 1));
        $this->expectError(['choices' => 'error.field_choices'], static fn () => $fields->define(SubjectType::Contact, 'x', 'X', FieldType::Choice, [], false, 1));
        $this->expectError(['choices' => 'error.invalid'], static fn () => $fields->define(SubjectType::Contact, 'x', 'X', FieldType::Choice, ["a\x07"], false, 1));
        $fields->define(SubjectType::Contact, 'note', 'Note', FieldType::Text, ['ignored'], false, 1);
        self::assertSame([], $this->service(CrmReaderInterface::class)->fields(SubjectType::Contact)[0]->choices);
        $fields->change($id, 'Level', ['A', 'B', 'C'], false, 3, 1);
        $this->expectConflict(ConflictException::STALE, static fn () => $fields->change($id, 'Level', ['A'], false, 3, 1));
        $fields->archive($id, true, 2);
        self::assertCount(1, $this->service(CrmReaderInterface::class)->fields(SubjectType::Contact));
        self::assertCount(2, $this->service(CrmReaderInterface::class)->fields(SubjectType::Contact, true));
        $fields->archive($id, false, 3);
        $this->expectThrow(NotAvailableException::class, static fn () => $fields->archive('01900000-0000-7000-8000-00000000ffff', true, 1));
    }

    public function testTheFieldLimit(): void
    {
        $this->boot(['fields' => ['max_per_subject' => 1]]);
        $this->admin();
        $fields = $this->service(FieldServiceInterface::class);
        $fields->define(SubjectType::Company, 'a', 'A', FieldType::Text, [], false, 1);
        $this->expectError(['key' => 'error.field_limit'], static fn () => $fields->define(SubjectType::Company, 'b', 'B', FieldType::Text, [], false, 1));
    }

    public function testNotes(): void
    {
        $this->boot();
        $this->admin();
        $notes = $this->service(NoteServiceInterface::class);
        $acme  = $this->company('Acme');
        $first = $notes->add(SubjectType::Company, $acme, "Called them\nSecond line");
        $notes->add(SubjectType::Company, $acme, 'Pinned', true);
        $view = $this->service(CrmReaderInterface::class)->notes(SubjectType::Company, $acme);
        self::assertSame(['Pinned', "Called them\nSecond line"], array_map(static fn (NoteView $n): string => $n->body, $view));
        self::assertSame('Ana', $view[0]->authorName);
        $notes->edit($first, 'Edited', false, 1);
        $this->expectConflict(ConflictException::STALE, static fn () => $notes->edit($first, 'Again', false, 1));
        $this->expectError(['body' => 'error.required'], static fn () => $notes->add(SubjectType::Company, $acme, ' '));
        $this->expectError(['body' => 'error.note_invalid'], static fn () => $notes->add(SubjectType::Company, $acme, str_repeat('x', 10001)));
        $this->actAs('leo', 'view', 'edit');
        $this->expectThrow(AccessDeniedException::class, static fn () => $notes->delete($first));
        $own = $notes->add(SubjectType::Company, $acme, 'Leo writes');
        $notes->delete($own);
        $this->admin();
        $notes->delete($first);
        $this->expectThrow(NotAvailableException::class, static fn () => $notes->edit($first, 'x', false, 2));
        self::assertContains('NoteAdded', $this->recorder()->eventNames());
    }

    public function testConsent(): void
    {
        $this->boot();
        $this->admin();
        $consent = $this->service(ConsentServiceInterface::class);
        $ada     = $this->contact('Ada', 'L');
        $consent->record($ada, 'newsletter', true, ConsentSource::Form, ' web form ');
        $this->clock()->modify('+1 minute');
        $consent->record($ada, 'newsletter', false, ConsentSource::Manual);
        $consent->record($ada, 'tracking', true, ConsentSource::Import);
        self::assertSame(['tracking' => true, 'newsletter' => false], $consent->current($ada));
        $history = $this->service(CrmReaderInterface::class)->consents($ada);
        self::assertSame([['tracking', true], ['newsletter', false], ['newsletter', true]], array_map(static fn (ConsentView $c): array => [$c->purpose, $c->granted], $history));
        self::assertSame('web form', $history[2]->evidence);
        $this->expectError(['purpose' => 'error.consent_purpose'], static fn () => $consent->record($ada, 'sms', true, ConsentSource::Manual));
        $this->expectError(['purpose' => 'error.invalid'], static fn () => $consent->record($ada, 'SMS', true, ConsentSource::Manual));
        $this->expectError(['evidence' => 'error.invalid'], static fn () => $consent->record($ada, 'newsletter', true, ConsentSource::Manual, "x\x07"));
        self::assertContains('crm.consent.recorded', $this->recorder()->actions());
    }

    public function testSettings(): void
    {
        $this->boot();
        $this->admin();
        $settings = $this->service(CrmSettingsInterface::class);
        self::assertSame(['trash_days' => 30, 'timeline_years' => 5, 'import_keep_days' => 30, 'merge_keep_days' => 90, 'version' => 0], $settings->get());
        $settings->update(14, 3, 7, 30, 0);
        self::assertSame(1, $settings->get()['version']);
        $this->expectConflict(ConflictException::SETTINGS_STALE, static fn () => $settings->update(14, 3, 7, 30, 0));
        $this->expectError(['trashDays' => 'error.out_of_range'], static fn () => $settings->update(1, 3, 7, 30, 1));
        $settings->update(15, 3, 7, 30, 1);
        self::assertSame(15, $settings->get()['trash_days']);
    }

    /**
     * @param array<string, string> $errors
     * @param callable(): mixed $call
     */
    private function expectError(array $errors, callable $call): void
    {
        try {
            $call();
            self::fail('accepted');
        } catch (InvalidInputException $e) {
            self::assertSame($errors, $e->errors);
        }
    }

    /**
     * @param callable(): mixed $call
     */
    private function expectConflict(string $code, callable $call): void
    {
        try {
            $call();
            self::fail('no conflict');
        } catch (ConflictException $e) {
            self::assertSame($code, $e->errorCode);
        }
    }

    /**
     * @param class-string<Throwable> $class
     * @param callable(): mixed $call
     */
    private function expectThrow(string $class, callable $call): void
    {
        try {
            $call();
            self::fail('no ' . $class);
        } catch (Throwable $e) {
            self::assertInstanceOf($class, $e);
        }
    }
}
