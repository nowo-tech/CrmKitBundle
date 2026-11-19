<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Unit\Application;

use Nowo\CrmKitBundle\Application\CrmBatchLookup;
use Nowo\CrmKitBundle\Application\PartyCatalogue;
use Nowo\CrmKitBundle\Application\Port\CrmBatchLookupStoreInterface;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Read\RolesAndTags;
use Nowo\CrmKitBundle\Domain\Value\CompanyDomain;
use Nowo\CrmKitBundle\Domain\Value\EmailAddress;
use Nowo\CrmKitBundle\Domain\Value\HostObjectRef;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use PHPUnit\Framework\TestCase;

use function array_map;
use function count;
use function range;
use function sprintf;
use function str_repeat;
use function strtoupper;

/**
 * {@see CrmBatchLookup} on a recording store: normalisation, de-duplication, chunks of 500, a result for every usable
 * input, configured roles in catalogue order, tags in byte order, foreign keys from the store ignored.
 */
final class CrmBatchLookupTest extends TestCase
{
    private const ID_A = '01900000-0000-7000-8000-000000000001';
    private const ID_B = '01900000-0000-7000-8000-000000000002';

    public function testInputsAreNormalisedDeduplicatedAndAlwaysAnswered(): void
    {
        $store  = new RecordingBatchStore();
        $lookup = new CrmBatchLookup($store, new PartyCatalogue(['customer', 'supplier']));
        $tenant = new TenantRef('acme');

        $store->ids = ['ada@acme.test' => [self::ID_A], 'stranger@x.test' => [self::ID_B]];
        self::assertSame(['ada@acme.test' => [self::ID_A], 'bob@x.test' => []], $lookup->contactsByEmails($tenant, [' Ada@ACME.test', 'ada@acme.test', 'bob@x.test', '', "\xff@x.test"]));
        self::assertSame([['ada@acme.test', 'bob@x.test']], $store->calls);

        $store->calls = [];
        $store->ids   = ['acme.test' => [self::ID_A, self::ID_B]];
        self::assertSame(['acme.test' => [self::ID_A, self::ID_B], 'beta.test' => []], $lookup->companiesByDomains($tenant, ['https://www.acme.test/x', 'ACME.test', 'beta.test', '123', 'a b']));
        self::assertSame([['acme.test', 'beta.test']], $store->calls);

        $store->calls = [];
        $store->ids   = [self::ID_A => [self::ID_B], self::ID_B => [self::ID_A]];
        self::assertSame([self::ID_A => [self::ID_B]], $lookup->affiliationsOf($tenant, [self::ID_A, 'nope', strtoupper(self::ID_A)]));
        self::assertSame([[self::ID_A]], $store->calls);
    }

    public function testLinkedSubjectsRolesAndTags(): void
    {
        $store  = new RecordingBatchStore();
        $lookup = new CrmBatchLookup($store, new PartyCatalogue(['customer', 'supplier', 'partner']));
        $tenant = new TenantRef('acme');
        $ada    = SubjectRef::of(SubjectType::Contact, self::ID_A, 'ana');
        $acme   = SubjectRef::of(SubjectType::Company, self::ID_B);

        $store->linked = ['issue:1' => [$ada, $acme], 'issue:9' => [$ada]];
        $result        = $lookup->subjectsLinkedToMany($tenant, [new HostObjectRef('issue', '1'), new HostObjectRef('issue', '2'), new HostObjectRef('issue', '1')]);
        self::assertSame(['issue:1' => [$ada, $acme], 'issue:2' => []], $result);
        self::assertSame(1, $store->linkCalls);

        $store->rolesAndTags = [
            'company:' . self::ID_B => ['roles' => ['supplier', 'legacy', 'customer'], 'tags' => ['vip', 'beta', 'Zed']],
            'contact:' . self::ID_B => ['roles' => ['partner'], 'tags' => []],
        ];
        self::assertEquals([
            'company:' . self::ID_B => new RolesAndTags(['customer', 'supplier'], ['Zed', 'beta', 'vip']),
            'contact:' . self::ID_A => new RolesAndTags(),
        ], $lookup->rolesAndTagsOf($tenant, [$acme, $ada, SubjectRef::of(SubjectType::Company, self::ID_B, 'leo')]));
        self::assertSame(['contact:' . self::ID_A, 'company:' . self::ID_B], [$ada->key(), $acme->key()]);
    }

    public function testLongListsRunInChunksOfFiveHundred(): void
    {
        $store  = new RecordingBatchStore();
        $lookup = new CrmBatchLookup($store, new PartyCatalogue());
        $tenant = new TenantRef('acme');
        $emails = array_map(static fn (int $i): string => sprintf('u%d@x.test', $i), range(1, 1001));

        self::assertCount(1001, $lookup->contactsByEmails($tenant, $emails));
        self::assertSame([500, 500, 1], array_map(count(...), $store->calls));
        $hosts = array_map(static fn (int $i): HostObjectRef => new HostObjectRef('issue', (string) $i), range(1, 501));
        self::assertCount(501, $lookup->subjectsLinkedToMany($tenant, $hosts));
        self::assertSame(2, $store->linkCalls);
        $subjects = array_map(static fn (int $i): SubjectRef => SubjectRef::of(SubjectType::Contact, sprintf('01900000-0000-7000-8000-%012d', $i)), range(1, 501));
        self::assertCount(501, $lookup->rolesAndTagsOf($tenant, $subjects));
        self::assertSame(2, $store->tagCalls);
    }

    public function testExistingKeepsInputOrderAndObjects(): void
    {
        $store  = new RecordingBatchStore();
        $lookup = new CrmBatchLookup($store, new PartyCatalogue());
        $tenant = new TenantRef('acme');
        $ada    = SubjectRef::of(SubjectType::Contact, self::ID_A, 'ana');
        $acme   = SubjectRef::of(SubjectType::Company, self::ID_B);
        $gone   = SubjectRef::of(SubjectType::Contact, self::ID_B);

        $store->existing = ['company:' . self::ID_B, 'contact:' . self::ID_A, 'company:' . self::ID_A];
        self::assertSame([$acme, $ada], $lookup->existing($tenant, [$acme, $gone, $ada, SubjectRef::of(SubjectType::Contact, self::ID_A)]));
        self::assertSame([3], $store->existingCalls);
        self::assertSame([], $lookup->existing($tenant, []));
        self::assertSame([3], $store->existingCalls, 'nothing asked: no query');

        $store->existing = [];
        $many            = array_map(static fn (int $i): SubjectRef => SubjectRef::of(SubjectType::Contact, sprintf('01900000-0000-7000-8000-%012d', $i)), range(1, 1001));
        self::assertSame([], $lookup->existing($tenant, $many));
        self::assertSame([3, 500, 500, 1], $store->existingCalls);
    }

    public function testKeysOfTheLookups(): void
    {
        self::assertSame('ada@acme.test', EmailAddress::lookupKey("\tAda@Acme.TEST "));
        self::assertNull(EmailAddress::lookupKey(' '));
        self::assertNull(EmailAddress::lookupKey("\xff"));
        self::assertNull(EmailAddress::lookupKey(str_repeat('a', 250) . '@x.test'));
        self::assertSame((new EmailAddress('Ada@Acme.TEST'))->key(), EmailAddress::lookupKey('Ada@Acme.TEST'));

        self::assertSame('acme.test', CompanyDomain::fromWebsite('https://WWW.Acme.Test/about'));
        self::assertSame('shop.acme.test', CompanyDomain::fromWebsite('http://shop.acme.test'));
        self::assertNull(CompanyDomain::fromWebsite(null));
        self::assertNull(CompanyDomain::fromWebsite('not a url'));
        foreach (['acme.test', 'www.acme.test', 'ACME.test', ' https://www.acme.test/x?y=1 ', 'acme.test/path'] as $input) {
            self::assertSame('acme.test', CompanyDomain::key($input), $input);
        }
        foreach (['', '  ', "\xff", 'a b', "acme\u{200B}.test", '123', str_repeat('a', 191)] as $input) {
            self::assertNull(CompanyDomain::key($input), $input);
        }
        self::assertSame(str_repeat('a', 190), CompanyDomain::key(str_repeat('a', 190)));
        self::assertSame('issue:a:b', (new HostObjectRef('issue', 'a:b'))->key());
    }
}

/**
 * Records the calls and answers from fixed maps (keys outside the asked ones included, to prove they are dropped).
 */
final class RecordingBatchStore implements CrmBatchLookupStoreInterface
{
    /** @var list<list<string>> */
    public array $calls = [];

    /** @var array<string, list<string>> */
    public array $ids = [];

    /** @var array<string, list<SubjectRef>> */
    public array $linked = [];

    /** @var array<string, array{roles: list<string>, tags: list<string>}> */
    public array $rolesAndTags = [];

    public int $linkCalls = 0;

    public int $tagCalls = 0;

    /** @var list<string> */
    public array $existing = [];

    /** @var list<int> */
    public array $existingCalls = [];

    public function contactIdsByEmailKeys(TenantRef $tenant, array $emailKeys): array
    {
        $this->calls[] = $emailKeys;

        return $this->ids;
    }

    public function companyIdsByDomainKeys(TenantRef $tenant, array $domainKeys): array
    {
        $this->calls[] = $domainKeys;

        return $this->ids;
    }

    public function activeSubjectsLinkedTo(TenantRef $tenant, array $hosts): array
    {
        ++$this->linkCalls;

        return $this->linked;
    }

    public function affiliatedCompanies(TenantRef $tenant, array $contactIds): array
    {
        $this->calls[] = $contactIds;

        return $this->ids;
    }

    public function rolesAndTags(TenantRef $tenant, array $subjects): array
    {
        ++$this->tagCalls;

        return $this->rolesAndTags;
    }

    public function existingKeys(TenantRef $tenant, array $subjects): array
    {
        $this->existingCalls[] = count($subjects);

        return $this->existing;
    }
}
