<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Unit\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Nowo\CrmKitBundle\Application\HubSpot\HubSpotException;
use Nowo\CrmKitBundle\Application\HubSpot\HubSpotMapper;
use Nowo\CrmKitBundle\Application\HubSpot\HubSpotRunState;
use Nowo\CrmKitBundle\Domain\Enum\HubSpotObject;
use Nowo\CrmKitBundle\Domain\Enum\ImportFormat;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Model\HubSpot\ExternalRef;
use Nowo\CrmKitBundle\Domain\Model\HubSpot\HubSpotConnection;
use Nowo\CrmKitBundle\Domain\Model\Import;
use Nowo\CrmKitBundle\Domain\Model\ImportRow;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotProperty;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotRecord;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotTokenInfo;
use Nowo\CrmKitBundle\Domain\Value\HubSpotAccountId;
use Nowo\CrmKitBundle\Domain\Value\HubSpotCursor;
use Nowo\CrmKitBundle\Domain\Value\HubSpotImportScope;
use Nowo\CrmKitBundle\Domain\Value\HubSpotKey;
use Nowo\CrmKitBundle\Domain\Value\HubSpotMapping;
use Nowo\CrmKitBundle\Domain\Value\HubSpotObjectId;
use Nowo\CrmKitBundle\Domain\Value\HubSpotToken;
use Nowo\CrmKitBundle\Domain\Value\SealedSecret;
use Nowo\CrmKitBundle\Domain\Value\SecretContext;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function print_r;
use function serialize;
use function str_contains;
use function strlen;
use function unserialize;

/**
 * The HubSpot values (0.10.0): anchored id rules (`/D`, no trailing newline, no zero-width characters), the
 * credential that never shows, the scope, the mapping, the run cursor and the field rules.
 */
final class HubSpotValuesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function ids(): iterable
    {
        yield 'digits' => ['12345', true];
        yield 'max' => ['12345678901234567890', true];
        yield 'too long' => ['123456789012345678901', false];
        yield 'leading zero' => ['0123', false];
        yield 'zero' => ['0', false];
        yield 'trailing newline' => ["123\n", false];
        yield 'zwsp' => ["12\u{200B}3", false];
        yield 'sign' => ['-12', false];
        yield 'empty' => ['', false];
    }

    #[DataProvider('ids')]
    public function testNumericIds(string $value, bool $valid): void
    {
        self::assertSame($valid, HubSpotObjectId::isValid($value));
        self::assertSame($valid, HubSpotAccountId::isValid($value));
        if (!$valid) {
            $this->expectException(InvalidArgumentException::class);
        }
        self::assertTrue((new HubSpotObjectId($value))->equals(new HubSpotObjectId($value)));
    }

    public function testKeysCursorsAndCredentials(): void
    {
        self::assertTrue(HubSpotKey::isValid('hubspot_owner_id'));
        self::assertFalse(HubSpotKey::isValid("name\n"));
        self::assertFalse(HubSpotKey::isValid('a|b'));
        self::assertTrue(HubSpotCursor::isValid('NTI1Cg=='));
        self::assertFalse(HubSpotCursor::isValid('https://evil.example/'));
        foreach ([static fn () => new HubSpotKey('a b'), static fn () => new HubSpotCursor("x\n")] as $bad) {
            try {
                $bad();
                self::fail('refused');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        // Any visible ASCII without spaces: service keys and private app tokens alike, no prefix rule.
        $key = new HubSpotToken('svc-key:abc/DEF+123=');
        self::assertSame('123=', $key->last4());
        self::assertSame('svc-key:abc/DEF+123=', $key->reveal());
        self::assertFalse(str_contains(print_r($key, true), 'abc/DEF'));
        self::assertFalse(HubSpotToken::isValid('abc defgh'));
        self::assertFalse(HubSpotToken::isValid("abcdefgh\r\nX-Evil: 1"));
        self::assertFalse(HubSpotToken::isValid('short'));
        try {
            serialize($key);
            self::fail('never serialised');
        } catch (LogicException) {
            self::addToAssertionCount(1);
        }
        try {
            unserialize('O:' . strlen(HubSpotToken::class) . ':"' . HubSpotToken::class . '":1:{s:5:"value";s:8:"abcdefgh";}');
            self::fail('never unserialised');
        } catch (LogicException) {
            self::addToAssertionCount(1);
        }
        $this->expectException(InvalidArgumentException::class);
        new HubSpotToken('has space here');
    }

    public function testSecretContextsAndSealedValues(): void
    {
        $context = new SecretContext(SecretContext::HUBSPOT_TOKEN, new TenantRef('acme'), '01890000-0000-7000-8000-000000000001');
        self::assertSame('nowo-crm-kit|v1|hubspot.token|acme|01890000-0000-7000-8000-000000000001', $context->aad());
        foreach ([static fn () => new SecretContext('other', new TenantRef('acme'), '01890000-0000-7000-8000-000000000001'), static fn () => new SecretContext(SecretContext::HUBSPOT_TOKEN, new TenantRef('acme'), 'x'), static fn () => new SealedSecret('BAD', 'AAAA'), static fn () => new SealedSecret('k1', 'not base64!'), static fn () => new SealedSecret('k1', '')] as $bad) {
            try {
                $bad();
                self::fail('refused');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame('k1', (new SealedSecret('k1', 'AAAA'))->keyId);
    }

    public function testScopeAndMapping(): void
    {
        $scope = new HubSpotImportScope([HubSpotObject::Contact], [new HubSpotObjectId('7')]);
        self::assertSame([HubSpotObject::Company, HubSpotObject::Contact], $scope->objects, 'contacts bring their companies, read first');
        self::assertSame(['companies', 'contacts', 'list:7'], $scope->columns());
        self::assertEquals($scope, HubSpotImportScope::fromColumns($scope->columns()));
        self::assertTrue($scope->reads(HubSpotObject::Company));
        self::assertSame([HubSpotObject::Company], (new HubSpotImportScope([HubSpotObject::Company]))->objects);
        foreach ([static fn () => new HubSpotImportScope([]), static fn () => new HubSpotImportScope([HubSpotObject::Company], [new HubSpotObjectId('1'), new HubSpotObjectId('1')]), static fn () => new HubSpotImportScope([HubSpotObject::Company], array_fill(0, 51, new HubSpotObjectId('1'))), static fn () => HubSpotImportScope::fromColumns(['deals'])] as $bad) {
            try {
                $bad();
                self::fail('refused');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $mapping = new HubSpotMapping(['companies' => ['name' => 'name', 'domain' => 'domain', 'x' => ''], 'contacts' => ['email' => 'email', 'hubspot_owner_id' => 'owner', 'vip' => 'custom.vip']], ['7' => HubSpotMapping::CREATE]);
        self::assertEquals($mapping, HubSpotMapping::fromFlat($mapping->flat()));
        self::assertSame(['companies|name' => 'name', 'companies|domain' => 'domain', 'companies|x' => '', 'contacts|email' => 'email', 'contacts|hubspot_owner_id' => 'owner', 'contacts|vip' => 'custom.vip', 'list|7' => 'create'], $mapping->flat());
        self::assertSame(['name', 'domain'], array_map(static fn (HubSpotKey $k): string => $k->value, $mapping->requested(HubSpotObject::Company)));
        self::assertTrue($mapping->customKnown(HubSpotObject::Contact, ['vip']));
        self::assertFalse($mapping->customKnown(HubSpotObject::Contact, []));
        self::assertFalse(HubSpotMapping::isTarget(HubSpotObject::Contact, 'domain'));
        self::assertFalse(HubSpotMapping::isTarget(HubSpotObject::Contact, 'custom.Bad'));
        self::assertFalse(HubSpotMapping::isTarget(HubSpotObject::Contact, 'name'));
        foreach ([static fn () => new HubSpotMapping(['deals' => []]), static fn () => new HubSpotMapping(['contacts' => ['a b' => 'email']]), static fn () => new HubSpotMapping(['contacts' => ['a' => 'email', 'b' => 'email']]), static fn () => new HubSpotMapping([], ['7' => 'nope']), static fn () => HubSpotMapping::fromFlat(['plain' => 'x'])] as $bad) {
            try {
                $bad();
                self::fail('refused');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testRunState(): void
    {
        $scope = new HubSpotImportScope([HubSpotObject::Contact], [new HubSpotObjectId('7'), new HubSpotObjectId('8'), new HubSpotObjectId('9')]);
        $state = HubSpotRunState::start(new HubSpotAccountId('42'), $scope, ['7' => HubSpotObject::Contact, '8' => HubSpotObject::Company, '9' => null]);
        self::assertSame(['owners', 'companies', 'contacts', 'lists'], $state->stages);
        self::assertNull($state->object());
        self::assertNull($state->cursor());
        $state = $state->advance(new HubSpotCursor('100'), owners: ['o1' => 'ana']);
        self::assertSame(['owners', '100', 1], [$state->stage, $state->after, $state->page]);
        $state = $state->advance(null);
        self::assertSame(HubSpotObject::Company, $state->object());
        $state = $state->advance(new HubSpotCursor('10'), 10, 0)->advance(null, 3)->advance(null, 2, 1);
        self::assertSame(['lists', ['companies' => 13, 'contacts' => 2], 1], [$state->stage, $state->seen, $state->skipped]);
        self::assertSame(['id' => '7', 'object' => 'contacts'], $state->currentList());
        $state = $state->advance(null, tagged: 2);
        self::assertSame(['id' => '8', 'object' => 'companies'], $state->currentList());
        $state = $state->advance(null);
        self::assertTrue($state->isDone());
        self::assertNull($state->currentList());
        self::assertSame(2, $state->tagged);
        self::assertSame($state->json(), HubSpotRunState::fromArray(json_decode($state->json(), true))->json());
        self::assertSame(['owners', 'companies'], HubSpotRunState::start(new HubSpotAccountId('42'), new HubSpotImportScope([HubSpotObject::Company]), [])->stages);
        $odd = HubSpotRunState::fromArray(['account' => '42', 'stages' => ['owners', 5], 'stage' => 'owners', 'owners' => ['o1' => 'a', 'o2' => 3], 'lists' => [['id' => 'x', 'object' => 'contacts'], 'no'], 'seen' => ['contacts' => 'x', 'companies' => 4]]);
        self::assertSame([['owners'], ['o1' => 'a'], [], ['companies' => 4]], [$odd->stages, $odd->owners, $odd->lists, $odd->seen]);
        foreach ([static fn () => HubSpotRunState::fromArray(null), static fn () => HubSpotRunState::fromArray(['account' => 'x']), static fn () => new HubSpotRunState('42', ['owners'], 'owners', 'bad cursor')] as $bad) {
            try {
                $bad();
                self::fail('refused');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testFieldRules(): void
    {
        $properties = [new HubSpotProperty(new HubSpotKey('name'), 'Name', 'string'), new HubSpotProperty(new HubSpotKey('domain'), 'Domain', 'string'), new HubSpotProperty(new HubSpotKey('custom_thing'), 'Custom', 'string')];
        self::assertSame(['name' => 'name', 'domain' => 'domain'], HubSpotMapper::defaults(HubSpotObject::Company, $properties));
        self::assertSame([], HubSpotMapper::defaults(HubSpotObject::Contact, $properties));
        self::assertContains('domain', HubSpotMapper::targets(HubSpotObject::Company, ['vip']));
        self::assertContains('custom.vip', HubSpotMapper::targets(HubSpotObject::Company, ['vip']));
        self::assertNotContains('domain', HubSpotMapper::targets(HubSpotObject::Contact, []));
        $record = new HubSpotRecord(new HubSpotObjectId('1'), ['name' => ' Acme ', 'domain' => 'Acme.example', 'city' => '', 'country' => 'Spain', 'hubspot_owner_id' => '9', 'vip' => 'gold', 'industry' => null]);
        $mapped = HubSpotMapper::map(HubSpotObject::Company, $record, ['name' => 'name', 'domain' => 'domain', 'city' => 'city', 'country' => 'country', 'hubspot_owner_id' => 'owner', 'vip' => 'custom.vip', 'industry' => 'industry', 'skip' => '']);
        self::assertSame(['values' => ['name' => 'Acme', 'website' => 'https://acme.example'], 'custom' => ['vip' => 'gold'], 'owner' => '9', 'note' => HubSpotMapper::COUNTRY_UNKNOWN], $mapped);
        self::assertSame(['website' => 'https://site.example'], HubSpotMapper::map(HubSpotObject::Company, new HubSpotRecord(new HubSpotObjectId('1'), ['website' => 'https://site.example', 'domain' => 'other.example']), ['website' => 'website', 'domain' => 'domain'])['values']);
        self::assertSame([], HubSpotMapper::map(HubSpotObject::Company, new HubSpotRecord(new HubSpotObjectId('1'), ['domain' => 'localhost']), ['domain' => 'domain'])['values']);
        self::assertSame(['country' => 'FR'], HubSpotMapper::map(HubSpotObject::Contact, new HubSpotRecord(new HubSpotObjectId('1'), ['country' => 'fr']), ['country' => 'country'])['values']);
    }

    public function testModelsAndReadModels(): void
    {
        $now        = new DateTimeImmutable('2026-11-23 10:00:00');
        $connection = new HubSpotConnection('01890000-0000-7000-8000-000000000001', 'acme', new HubSpotAccountId('42'), 'Main', 'ab12', new SealedSecret('k1', 'AAAA'), ['crm.objects.contacts.read'], $now, 'ana', $now);
        self::assertSame(['acme', '42', 'ana', 'ana', 1, true], [$connection->getTenantId(), $connection->getAccountId()->value, $connection->getCreatedById(), $connection->getUpdatedById(), $connection->getVersion(), $connection->isActive()]);
        self::assertSame($now, $connection->getCreatedAt());
        $connection->invalidate($now->modify('+1 minute'));
        self::assertFalse($connection->isActive());
        $connection->replace(new HubSpotAccountId('43'), 'New', 'cd34', new SealedSecret('k2', 'BBBB'), [], $now, 'leo');
        $connection->reseal(new SealedSecret('k3', 'CCCC'), $now);
        self::assertSame(['43', 'New', 'k3', 'leo', 'active'], [$connection->view()->accountId, $connection->getLabel(), $connection->getToken()->keyId, $connection->getUpdatedById(), $connection->getStatus()->value]);
        self::assertSame($now, $connection->getUpdatedAt());
        $ref = new ExternalRef('01890000-0000-7000-8000-000000000002', 'acme', '42', 'contact', '7', 'contact', '01890000-0000-7000-8000-000000000003', $now);
        $ref->retarget('01890000-0000-7000-8000-000000000004', $now);
        self::assertSame(['acme', 'hubspot', '42', 'contact', '7', 'contact', '01890000-0000-7000-8000-000000000004', false], [$ref->getTenantId(), $ref->getSource(), $ref->getAccountId(), $ref->getObjectType(), $ref->getExternalId(), $ref->getTargetType(), $ref->getTargetId(), $ref->isErased()]);
        self::assertSame([$now, $now, '01890000-0000-7000-8000-000000000002'], [$ref->getCreatedAt(), $ref->getUpdatedAt(), $ref->getId()]);
        $import = new Import('01890000-0000-7000-8000-000000000005', 'acme', SubjectType::Contact, ImportFormat::HubSpot, 'HubSpot', ['contacts'], [], 1, 'ana', $now, 'c1');
        $import->startCursor(['a' => 1]);
        $import->pause($now, 'hubspot_rate_limited');
        self::assertSame(['c1', ['a' => 1], $now, 'hubspot_rate_limited'], [$import->getConnectionId(), $import->getCursor(), $import->getResumeAfter(), $import->getErrorCode()]);
        self::assertFalse(ImportFormat::HubSpot->isFile());
        self::assertSame(SubjectType::Contact, (new ImportRow('r', 'i', 'acme', 1, [], SubjectType::Contact))->getSubjectType());
        self::assertSame(['crm.objects.contacts.write'], (new HubSpotTokenInfo(new HubSpotAccountId('1'), ['oauth', 'crm.objects.contacts.read', 'crm.objects.contacts.write']))->writeScopes());
        self::assertSame([HubSpotObject::Company, null], [HubSpotObject::ofSubject(SubjectType::Company), HubSpotObject::ofSubject(SubjectType::Deal)]);
        self::assertSame(HubSpotObject::Contact, HubSpotObject::ofSubject(SubjectType::Contact));
        self::assertSame(['crm.objects.contacts.read', 'crm.schemas.contacts.read'], HubSpotObject::Contact->scopes());
        self::assertTrue((new HubSpotException(HubSpotException::RATE_LIMITED, 429))->isTransient());
        self::assertFalse((new HubSpotException(HubSpotException::RATE_LIMITED, 429, null, true))->isTransient());
        self::assertSame('HubSpot: hubspot_bad_response (302).', (new HubSpotException(HubSpotException::BAD_RESPONSE, 302))->getMessage());
    }
}
