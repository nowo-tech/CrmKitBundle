<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Unit\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\CrmKitBundle\Domain\Enum\CrmResource;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Port\CrmHostObjectResolverInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmPanelProviderInterface;
use Nowo\CrmKitBundle\Domain\Value\ActorProfile;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\AuditEntry;
use Nowo\CrmKitBundle\Domain\Value\ConsentPurpose;
use Nowo\CrmKitBundle\Domain\Value\EmailAddress;
use Nowo\CrmKitBundle\Domain\Value\ExactId;
use Nowo\CrmKitBundle\Domain\Value\FieldKey;
use Nowo\CrmKitBundle\Domain\Value\HostObjectRef;
use Nowo\CrmKitBundle\Domain\Value\HostObjectView;
use Nowo\CrmKitBundle\Domain\Value\Panel;
use Nowo\CrmKitBundle\Domain\Value\PanelContext;
use Nowo\CrmKitBundle\Domain\Value\PanelItem;
use Nowo\CrmKitBundle\Domain\Value\RecordId;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\Domain\Value\TagName;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * Value objects (contract section 5, REQ-SEC-006): look-alike ids are refused, never trimmed or folded.
 */
final class ValueTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function lookAlikes(): iterable
    {
        yield 'empty' => [''];
        yield 'trailing space' => ['bob '];
        yield 'leading space' => [' bob'];
        yield 'newline' => ["bob\n"];
        yield 'nbsp' => ["bob\u{00A0}"];
        yield 'zwsp' => ["bo\u{200B}b"];
        yield 'bom' => ["\u{FEFF}bob"];
        yield 'bidi' => ["bob\u{202E}"];
        yield 'line separator' => ["bob\u{2028}x"];
        yield 'invalid utf-8' => ["bo\xC3"];
        yield 'too long' => [str_repeat('a', 129)];
    }

    #[DataProvider('lookAlikes')]
    public function testOpaqueRefsRefuseLookAlikes(string $value): void
    {
        self::assertFalse(ExactId::isValid($value));
        $this->expectException(InvalidArgumentException::class);
        new TenantRef($value);
    }

    public function testRefsCompareByteForByte(): void
    {
        $bob = new ActorRef('bob');
        self::assertTrue($bob->equals(new ActorRef('bob')));
        self::assertFalse($bob->equals(new ActorRef('Bob')));
        self::assertSame('bob', (string) $bob);
        self::assertSame('default', TenantRef::default()->value);
        self::assertTrue(ExactId::isValid(str_repeat('é', 64)));
        self::assertFalse(ExactId::isValid(str_repeat('é', 65)));
    }

    public function testRecordIdsAreLowercaseUuids(): void
    {
        $id = new RecordId('01900000-0000-7000-8000-000000000001');
        self::assertSame('01900000-0000-7000-8000-000000000001', (string) $id);
        self::assertFalse(RecordId::isValid('01900000-0000-7000-8000-00000000000A'));
        self::assertFalse(RecordId::isValid("01900000-0000-7000-8000-000000000001\n"));
        $this->expectException(InvalidArgumentException::class);
        new RecordId('nope');
    }

    public function testSubjectRefs(): void
    {
        $ref = SubjectRef::of(SubjectType::Contact, '01900000-0000-7000-8000-000000000001', 'ana');
        self::assertSame('ana', $ref->owner?->value);
        self::assertNull(SubjectRef::of(SubjectType::Company, '01900000-0000-7000-8000-000000000001')->owner);
    }

    public function testHostObjectRefs(): void
    {
        $ref = new HostObjectRef('project', 'p1');
        self::assertSame('p1', $ref->id);
        foreach ([['Project', 'p1'], ['project', 'p1 ']] as [$type, $id]) {
            try {
                new HostObjectRef($type, $id);
                self::fail('accepted ' . $type . '/' . $id);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testEmailAddresses(): void
    {
        self::assertSame('ada@example.test', (new EmailAddress('Ada@Example.TEST'))->key());
        foreach (['ada', 'ada@x', 'a da@x.test', "ada@x.test\n", str_repeat('a', 250) . '@x.test', 'a@b@c.test'] as $bad) {
            try {
                new EmailAddress($bad);
                self::fail('accepted ' . $bad);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testKeysAndNames(): void
    {
        self::assertSame('newsletter', (new ConsentPurpose('newsletter'))->value);
        self::assertSame('vat_regime', (new FieldKey('vat_regime'))->value);
        self::assertSame('vip', (new TagName('VIP'))->key());
        foreach ([static fn (): ConsentPurpose => new ConsentPurpose('News'), static fn (): FieldKey => new FieldKey("vat\n"), static fn (): TagName => new TagName(' vip'), static fn (): TagName => new TagName(''), static fn (): TagName => new TagName(str_repeat('x', 61))] as $make) {
            try {
                $make();
                self::fail('accepted');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testReadOnlyCarriers(): void
    {
        $tenant = new TenantRef('acme');
        $actor  = new ActorRef('ana');
        $entry  = new AuditEntry($tenant, $actor, 'crm.x', 'company', 'id', ['n' => 1], new DateTimeImmutable('2026-11-18'));
        self::assertSame('crm.x', $entry->action);
        self::assertSame('Ana', (new ActorProfile($actor, 'Ana'))->name);
        self::assertSame('/p', (new HostObjectView('p', 'P', '/p'))->url);
        $context = new PanelContext($tenant, SubjectRef::of(SubjectType::Company, '01900000-0000-7000-8000-000000000001'), $actor, [new HostObjectRef('project', 'p1')]);
        self::assertCount(1, $context->links);
        $panel = new Panel('T', [new PanelItem('a')]);
        self::assertSame(Panel::MAIN, $panel->position);
        self::assertSame('neutral', $panel->items[0]->tone);
    }

    public function testOneHostClassMayImplementBothExtensionPoints(): void
    {
        $both = new class implements CrmPanelProviderInterface, CrmHostObjectResolverInterface {
            public function key(): string
            {
                return 'k';
            }

            public function supports(SubjectType $type): bool
            {
                return true;
            }

            public function panel(PanelContext $context): ?Panel
            {
                return null;
            }

            public function type(): string
            {
                return 'project';
            }

            public function resolve(TenantRef $tenant, array $ids): array
            {
                return [];
            }

            public function search(TenantRef $tenant, string $text, int $limit): array
            {
                return [];
            }
        };
        self::assertSame([null, 'project'], [$both->panel(new PanelContext(new TenantRef('acme'), SubjectRef::of(SubjectType::Company, '01900000-0000-7000-8000-000000000001'), null, [])), $both->type()]);
    }

    public function testEnumsAndExceptions(): void
    {
        self::assertSame('companies', SubjectType::Company->segment());
        self::assertSame(SubjectType::Contact, SubjectType::fromSegment('contacts'));
        self::assertNull(SubjectType::fromSegment('deals'));
        self::assertSame(CrmResource::Contacts, SubjectType::Contact->resource());
        self::assertSame(CrmResource::Companies, SubjectType::Company->resource());
        self::assertSame(['x' => 'k'], InvalidInputException::field('x', 'k')->errors);
        self::assertSame('Not available.', (new NotAvailableException())->getMessage());
        $conflict = new ConflictException(ConflictException::MERGED, 'id');
        self::assertSame('id', $conflict->recordId);
        self::assertSame(ConflictException::MERGED, $conflict->errorCode);
    }
}
