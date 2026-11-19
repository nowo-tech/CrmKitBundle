<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Unit\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\CrmKitBundle\Application\ImportMapping;
use Nowo\CrmKitBundle\Application\PartyCatalogue;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Event\RolesChanged;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Model\Affiliation;
use Nowo\CrmKitBundle\Domain\Model\RoleAssignment;
use Nowo\CrmKitBundle\Domain\Value\AffiliationPurpose;
use Nowo\CrmKitBundle\Domain\Value\RoleKey;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * Party roles and affiliation purposes (contract section 6.8): keys matched exactly, catalogues checked, purposes
 * stored sorted and distinct, import cells parsed.
 */
final class RolesAndPurposesTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function badKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'uppercase' => ['Customer'];
        yield 'trailing newline' => ["customer\n"];
        yield 'leading digit' => ['1st'];
        yield 'dash' => ['key-account'];
        yield 'dot' => ['a.b'];
        yield 'space' => ['customer '];
        yield 'too long' => [str_repeat('a', 33)];
    }

    #[DataProvider('badKeys')]
    public function testKeysAreExact(string $key): void
    {
        self::assertFalse(RoleKey::isValid($key));
        self::assertFalse(AffiliationPurpose::isValid($key));
        try {
            new RoleKey($key);
            self::fail('role key accepted');
        } catch (InvalidArgumentException) {
        }
        $this->expectException(InvalidArgumentException::class);
        new AffiliationPurpose($key);
    }

    public function testValidKeys(): void
    {
        self::assertSame('key_account2', (new RoleKey('key_account2'))->value);
        self::assertSame(str_repeat('a', 32), (new AffiliationPurpose(str_repeat('a', 32)))->value);
    }

    public function testCatalogue(): void
    {
        $catalogue = new PartyCatalogue();
        self::assertSame(['customer', 'supplier', 'partner'], $catalogue->roles());
        self::assertSame(['billing', 'orders', 'technical', 'general'], $catalogue->purposes());
        self::assertTrue($catalogue->hasRole('customer'));
        self::assertFalse($catalogue->hasRole('distributor'));
        self::assertTrue($catalogue->hasPurpose('billing'));
        self::assertFalse($catalogue->hasPurpose('Billing'));
        self::assertSame(['supplier', 'customer'], $catalogue->checkRoles(['supplier', 'customer', 'supplier']));
        self::assertSame(['orders'], $catalogue->checkPurposes(['orders']));
        self::assertSame(['customer', 'partner'], $catalogue->knownRoles(['partner', 'gone', 'customer']));
        self::assertSame(['billing', 'general'], $catalogue->knownPurposes(['general', 'old', 'billing']));
        foreach ([[static fn (): array => $catalogue->checkRoles(['customer ']), ['roles' => 'error.role_unknown']], [static fn (): array => $catalogue->checkPurposes(['BILLING']), ['purposes' => 'error.purpose_unknown']]] as [$call, $errors]) {
            try {
                $call();
                self::fail('unknown key accepted');
            } catch (InvalidInputException $e) {
                self::assertSame($errors, $e->errors);
            }
        }
    }

    public function testAffiliationPurposesAndPrimaryContact(): void
    {
        $at          = new DateTimeImmutable('2026-11-19 10:00:00');
        $affiliation = new Affiliation('01900000-0000-7000-8000-000000000001', 'acme', 'c', 'k', 'CFO', false, $at);
        self::assertSame([], $affiliation->getPurposes());
        self::assertFalse($affiliation->isPrimaryContact());
        $affiliation->setPurposes(['orders', 'billing', 'orders']);
        self::assertSame(['billing', 'orders'], $affiliation->getPurposes());
        self::assertSame(',billing,orders,', Affiliation::encode(['orders', 'billing']));
        self::assertSame('', Affiliation::encode([]));
        $affiliation->setPrimaryContact(true);
        $affiliation->change('CEO', true);
        self::assertTrue($affiliation->isPrimaryContact());
        self::assertSame(['CEO', true], [$affiliation->getRole(), $affiliation->isPrimary()]);
    }

    public function testRoleAssignmentAndEvent(): void
    {
        $at  = new DateTimeImmutable('2026-11-19 10:00:00');
        $row = new RoleAssignment('01900000-0000-7000-8000-000000000002', 'acme', SubjectType::Company, 'a', 'customer', $at, 'ana');
        $row->moveTo('b');
        self::assertSame(['01900000-0000-7000-8000-000000000002', 'acme', SubjectType::Company, 'b', 'customer', $at, 'ana'], [$row->getId(), $row->getTenantId(), $row->getSubjectType(), $row->getSubjectId(), $row->getRoleKey(), $row->getCreatedAt(), $row->getCreatedById()]);
        $event = new RolesChanged(new TenantRef('acme'), null, $at, SubjectType::Contact, 'c', ['customer'], ['supplier']);
        self::assertSame([['customer'], ['supplier']], [$event->added, $event->removed]);
    }

    public function testImportCells(): void
    {
        self::assertTrue(ImportMapping::yes(' YES '));
        self::assertTrue(ImportMapping::yes('x'));
        self::assertFalse(ImportMapping::yes(''));
        self::assertFalse(ImportMapping::yes(null));
        self::assertFalse(ImportMapping::yes('No'));
        self::assertNull(ImportMapping::yes('maybe'));
        $mapping                                   = ['E' => 'email', 'L' => 'last_name', 'R' => 'roles', 'P' => 'purposes', 'K' => 'primary_contact', 'C' => 'company'];
        [$input, $tags, $company, $purposes, $key] = ImportMapping::input(SubjectType::Contact, ['E' => 'a@x.test', 'L' => 'A', 'R' => 'customer; supplier,,', 'P' => 'billing', 'K' => ' yes ', 'C' => 'Acme'], $mapping);
        self::assertSame([['customer', 'supplier'], [], 'Acme', ['billing'], 'yes'], [$input->roles, $tags, $company, $purposes, $key]);
        [$input, , , $purposes, $key] = ImportMapping::input(SubjectType::Contact, ['E' => '', 'L' => 'A', 'R' => ' ', 'P' => '', 'K' => '', 'C' => ''], $mapping);
        self::assertSame([null, [], null], [$input->roles, $purposes, $key]);
        self::assertContains('roles', ImportMapping::targets(SubjectType::Company, []));
        self::assertNotContains('purposes', ImportMapping::targets(SubjectType::Company, []));
        self::assertSame(['Role' => 'roles', 'Purpose' => 'purposes', 'Primary' => '', 'Primary contact' => 'primary_contact'], ImportMapping::detect(['Role', 'Purpose', 'Primary', 'Primary contact'], ImportMapping::targets(SubjectType::Contact, [])));
    }
}
