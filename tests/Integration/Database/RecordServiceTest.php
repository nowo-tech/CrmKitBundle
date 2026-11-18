<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use Nowo\CrmKitBundle\Domain\Enum\FieldType;
use Nowo\CrmKitBundle\Domain\Enum\RecordStatus;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Read\ActivityView;
use Nowo\CrmKitBundle\Domain\Read\AffiliationView;
use Nowo\CrmKitBundle\Domain\Read\RecordView;
use Nowo\CrmKitBundle\Domain\Service\CrmReaderInterface;
use Nowo\CrmKitBundle\Domain\Service\FieldServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\NoteServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\RecordInput;
use Nowo\CrmKitBundle\Domain\Service\TagServiceInterface;
use Throwable;

/**
 * Companies and contacts through the service (contract sections 7, 9 and 15): create, edit with the version check,
 * owners, custom fields, tags, e-mail uniqueness, trash / restore / purge, affiliations, the gate (404 / 403) and the
 * plan counter.
 */
final class RecordServiceTest extends CrmTestCase
{
    private const OTHER = '01900000-0000-7000-8000-00000000abcd';

    public function testCreateEditWithFieldsOwnerAndTags(): void
    {
        $this->boot();
        $this->admin();
        $fields = $this->service(FieldServiceInterface::class);
        $fields->define(SubjectType::Company, 'tier', 'Tier', FieldType::Choice, ['Gold', 'Silver'], false, 1);
        $vip  = $this->service(TagServiceInterface::class)->create('VIP', 'red');
        $id   = $this->records()->create(new RecordInput(SubjectType::Company, ['name' => 'Acme', 'city' => 'Madrid'], 'leo', ['tier' => 'Gold'], [$vip]));
        $view = $this->reader()->record(SubjectType::Company, $id);
        self::assertSame(['Leo', 'Gold', 'VIP', 1], [$view->ownerName, $view->custom['tier'], $view->tags[0]->name, $view->version]);

        $version = $this->records()->update($id, new RecordInput(SubjectType::Company, ['city' => 'Bilbao'], 'ana', ['tier' => 'Silver'], []), 1);
        self::assertSame(2, $version);
        self::assertSame(2, $this->records()->update($id, new RecordInput(SubjectType::Company, ['city' => 'Bilbao'], setOwner: false), 2), 'nothing changed: same version');
        $view = $this->reader()->record(SubjectType::Company, $id);
        self::assertSame(['Bilbao', 'ana', 'Silver', []], [$view->values['city'], $view->ownerId, $view->custom['tier'], $view->tags]);
        $kinds = array_map(static fn (ActivityView $e): string => $e->kind->value, $this->reader()->timeline(SubjectType::Company, $id));
        self::assertSame(['owner_changed', 'updated', 'untagged', 'tagged', 'created'], $kinds);
        self::assertSame(['crm.company.created', 'crm.company.updated'], array_values(array_filter($this->recorder()->actions(), static fn (string $a): bool => str_contains($a, 'company'))));
        self::assertContains('RecordUpdated', $this->recorder()->eventNames());
        self::assertContains('TagsChanged', $this->recorder()->eventNames());

        $this->assertConflict(ConflictException::STALE, fn (): int => $this->records()->update($id, new RecordInput(SubjectType::Company, ['city' => 'Vigo']), 1));
        $this->assertInvalid(['owner' => 'error.owner_unknown'], fn (): int => $this->records()->update($id, new RecordInput(SubjectType::Company, [], 'zoe'), 2));
        $this->assertInvalid(['tags' => 'error.tag_unknown'], fn (): int => $this->records()->update($id, new RecordInput(SubjectType::Company, [], null, null, [self::OTHER]), 2));
        $this->assertInvalid(['custom.tier' => 'field.error.type'], fn (): int => $this->records()->update($id, new RecordInput(SubjectType::Company, [], null, ['tier' => 'Bronze']), 2));
        $this->assertInvalid(['name' => 'error.required'], fn (): string => $this->records()->create(new RecordInput(SubjectType::Company, ['city' => 'X'])));
    }

    public function testContactsNeedANameAndAUniqueEmail(): void
    {
        $this->boot();
        $this->admin();
        $ada = $this->contact('Ada', 'Lovelace', 'ada@example.test');
        $this->assertInvalid(['email' => 'error.email_taken'], fn (): string => $this->contact('Other', 'Ada', 'ADA@example.test'));
        $this->assertInvalid(['last_name' => 'error.contact_name'], fn (): string => $this->records()->create(new RecordInput(SubjectType::Contact, ['email' => 'x@example.test'])));
        $bob = $this->contact('Bob', 'Byte', 'bob@example.test');
        $this->assertInvalid(['email' => 'error.email_taken'], fn (): int => $this->records()->update($bob, new RecordInput(SubjectType::Contact, ['email' => 'ada@example.test']), 1));
        $this->assertInvalid(['last_name' => 'error.contact_name'], fn (): int => $this->records()->update($bob, new RecordInput(SubjectType::Contact, ['first_name' => null, 'last_name' => null]), 1));
        self::assertSame(1, $this->int("SELECT COUNT(*) FROM nowo_crm_contact WHERE email_key = 'ada@example.test'"));
        self::assertNotSame($ada, $bob);
    }

    public function testTrashRestorePurgeAndCounters(): void
    {
        $this->boot();
        $this->admin();
        $this->ctx()->limits['companies'] = 2;
        $a                                = $this->company('A');
        $b                                = $this->company('B');
        $this->assertConflict(ConflictException::LIMIT, fn (): string => $this->company('C'));
        $this->records()->trash(SubjectType::Company, $a, 1);
        $c = $this->company('C');
        $this->assertConflict(ConflictException::LIMIT, fn () => $this->records()->restore(SubjectType::Company, $a));
        $this->assertConflict(ConflictException::TRASHED, fn (): int => $this->records()->update($a, new RecordInput(SubjectType::Company, ['city' => 'x']), 2));
        $this->assertConflict(ConflictException::STALE, fn () => $this->records()->restore(SubjectType::Company, $b));
        $this->assertConflict(ConflictException::STALE, fn () => $this->records()->trash(SubjectType::Company, $b, 7));
        $this->assertConflict(ConflictException::STALE, fn () => $this->records()->purge(SubjectType::Company, $b));
        $this->service(NoteServiceInterface::class)->add(SubjectType::Company, $c, 'gone soon');
        $this->records()->trash(SubjectType::Company, $c, 1);
        $this->records()->restore(SubjectType::Company, $a);
        self::assertSame(RecordStatus::Active, $this->reader()->record(SubjectType::Company, $a)->status);
        $this->assertConflict(ConflictException::TRASHED, fn () => $this->service(NoteServiceInterface::class)->add(SubjectType::Company, $c, 'x'));
        $this->actAs('ana', 'view', 'edit');
        $this->ctx()->grants['acme']['ana'] = ['view', 'edit'];
        $this->assertDenied(fn () => $this->records()->purge(SubjectType::Company, $c));
        $this->admin();
        $this->records()->purge(SubjectType::Company, $c);
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_company WHERE id = ?', [$c]));
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_note'));
        self::assertSame(2, $this->int("SELECT value FROM nowo_crm_counter WHERE resource = 'companies'"));
        self::assertContains('RecordPurged', $this->recorder()->eventNames());
    }

    public function testAffiliations(): void
    {
        $this->boot();
        $this->admin();
        $acme  = $this->company('Acme');
        $beta  = $this->company('Beta');
        $ada   = $this->contact('Ada', 'L');
        $first = $this->records()->affiliate($ada, $acme, ' CTO ', true);
        self::assertSame($first, $this->records()->affiliate($ada, $acme, 'CEO', true), 'the pair is updated, not doubled');
        $this->records()->affiliate($ada, $beta, null, true);
        $view = $this->reader()->record(SubjectType::Contact, $ada);
        self::assertSame([['Beta', true, null], ['Acme', false, 'CEO']], array_map(static fn (AffiliationView $a): array => [$a->companyName, $a->primary, $a->role], $view->affiliations));
        self::assertSame(['Ada L'], array_map(static fn (AffiliationView $a): string => $a->contactName, $this->reader()->record(SubjectType::Company, $acme)->affiliations));
        $this->assertInvalid(['role' => 'error.invalid'], fn (): string => $this->records()->affiliate($ada, $acme, "bad\x07", false));
        $this->records()->unaffiliate($first);
        $this->assertNotAvailable(fn () => $this->records()->unaffiliate($first));
        $this->assertNotAvailable(fn () => $this->records()->unaffiliate('nope'));
        $this->records()->trash(SubjectType::Company, $beta, 1);
        self::assertSame([], $this->reader()->record(SubjectType::Contact, $ada)->affiliations, 'a trashed company is not shown');
        $this->assertConflict(ConflictException::TRASHED, fn (): string => $this->records()->affiliate($ada, $beta, null, false));
    }

    public function testTheGate(): void
    {
        $this->boot();
        $this->admin();
        $id                  = $this->company('Acme');
        $this->ctx()->tenant = 'beta';
        $this->actAs('ana', 'view', 'edit', 'manage');
        $this->assertNotAvailable(fn (): RecordView => $this->reader()->record(SubjectType::Company, $id));
        $this->assertNotAvailable(fn (): int => $this->records()->update($id, new RecordInput(SubjectType::Company, ['city' => 'x']), 1));
        $this->assertNotAvailable(fn (): RecordView => $this->reader()->record(SubjectType::Company, 'not-an-id'));
        $this->ctx()->tenant = null;
        $this->assertNotAvailable(fn (): string => $this->company('x'));
        $this->ctx()->tenant  = 'acme';
        $this->ctx()->enabled = ['acme' => false];
        $this->assertNotAvailable(fn (): string => $this->company('x'));
        $this->ctx()->enabled = [];
        $this->ctx()->actor   = null;
        $this->assertNotAvailable(fn (): string => $this->company('x'));
        $this->actAs('zoe', 'view');
        $this->assertDenied(fn (): string => $this->company('x'));
        $this->assertDenied(fn (): int => $this->records()->update($id, new RecordInput(SubjectType::Company, ['city' => 'x']), 1));
        $this->actAs('pia', 'edit:' . $id);
        $this->records()->update($id, new RecordInput(SubjectType::Company, ['city' => 'Own']), 1);
        self::assertSame(1, $this->int("SELECT COUNT(*) FROM nowo_crm_company WHERE city = 'Own'"));
    }

    private function reader(): CrmReaderInterface
    {
        return $this->service(CrmReaderInterface::class);
    }

    /**
     * @param callable(): mixed $call
     */
    private function assertConflict(string $code, callable $call): void
    {
        try {
            $call();
            self::fail('no conflict ' . $code);
        } catch (ConflictException $e) {
            self::assertSame($code, $e->errorCode);
        }
    }

    /**
     * @param array<string, string> $errors
     * @param callable(): mixed $call
     */
    private function assertInvalid(array $errors, callable $call): void
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
    private function assertNotAvailable(callable $call): void
    {
        $this->assertThrows(NotAvailableException::class, $call);
    }

    /**
     * @param callable(): mixed $call
     */
    private function assertDenied(callable $call): void
    {
        $this->assertThrows(AccessDeniedException::class, $call);
    }

    /**
     * @param class-string<Throwable> $class
     * @param callable(): mixed $call
     */
    private function assertThrows(string $class, callable $call): void
    {
        try {
            $call();
            self::fail('no ' . $class);
        } catch (Throwable $e) {
            self::assertInstanceOf($class, $e);
        }
    }
}
