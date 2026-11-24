<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Unit\Infrastructure;

use Closure;
use DateTimeImmutable;
use Defuse\Crypto\Exception\BadFormatException;
use InvalidArgumentException;
use LogicException;
use Nowo\CrmKitBundle\Application\HubSpot\HubSpotException;
use Nowo\CrmKitBundle\Application\ImportService;
use Nowo\CrmKitBundle\Application\Port\ImportRunDispatcherInterface;
use Nowo\CrmKitBundle\Bridge\DoctrineEncrypt\DoctrineEncryptCrmSecretBox;
use Nowo\CrmKitBundle\Bridge\OutboundUrlGuard\GuardedHubSpotRequestOptions;
use Nowo\CrmKitBundle\Domain\Enum\HubSpotObject;
use Nowo\CrmKitBundle\Domain\Exception\SecretKeyUnavailableException;
use Nowo\CrmKitBundle\Domain\Exception\SecretUnsealException;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotAssociation;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotOwnerPage;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotProperty;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotRecord;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotTokenInfo;
use Nowo\CrmKitBundle\Domain\Value\HubSpotAccountId;
use Nowo\CrmKitBundle\Domain\Value\HubSpotCursor;
use Nowo\CrmKitBundle\Domain\Value\HubSpotKey;
use Nowo\CrmKitBundle\Domain\Value\HubSpotObjectId;
use Nowo\CrmKitBundle\Domain\Value\HubSpotToken;
use Nowo\CrmKitBundle\Domain\Value\SealedSecret;
use Nowo\CrmKitBundle\Domain\Value\SecretContext;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Nowo\CrmKitBundle\Infrastructure\Crypto\SodiumCrmSecretBox;
use Nowo\CrmKitBundle\Infrastructure\HubSpot\HttpHubSpotReader;
use Nowo\CrmKitBundle\Infrastructure\HubSpot\HubSpotHttpClientFactory;
use Nowo\CrmKitBundle\Infrastructure\HubSpot\HubSpotRequestOptionsInterface;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Messenger\MessengerImportRunDispatcher;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Messenger\RunCrmImport;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Messenger\RunCrmImportHandler;
use Nowo\CrmKitBundle\Tests\Fixtures\HubSpot\FakeHubSpot;
use Nowo\DoctrineEncryptBundle\Encryptors\DefuseEncryptor;
use Nowo\DoctrineEncryptBundle\Encryptors\EncryptorInterface;
use Nowo\DoctrineEncryptBundle\Encryptors\EncryptorRegistry;
use Nowo\DoctrineEncryptBundle\Encryptors\EncryptorRegistryInterface;
use Nowo\DoctrineEncryptBundle\Encryptors\HaliteEncryptor;
use Nowo\DoctrineEncryptBundle\Encryptors\MysqlAesEncryptor;
use Nowo\OutboundUrlGuardBundle\Exception\UnsafeOutboundUrlException;
use Nowo\OutboundUrlGuardBundle\Guard\OutboundUrlGuard;
use ParagonIE\Halite\Alerts\CannotPerformOperation;
use ParagonIE\Halite\Alerts\InvalidKey;
use ParagonIE\Halite\Alerts\InvalidMessage;
use ParagonIE\Halite\Alerts\InvalidSignature;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Throwable;

use function base64_encode;
use function str_repeat;

/**
 * The HubSpot infrastructure (0.10.0): the reader against the fake account and hostile answers, the secret boxes,
 * the OutboundUrlGuard pin, the HTTP client and the Messenger parts.
 */
final class HubSpotInfrastructureTest extends TestCase
{
    public function testTheReaderMapsEveryEndpoint(): void
    {
        $fake = new FakeHubSpot();
        $fake->seedCompanies(3);
        $fake->seedContacts(2);
        $fake->contacts[2001] = ['firstname' => 'Person', 'lastname' => 'No2001', 'email' => 'p2001@people.example', 'phone' => null];
        $fake->owners         = ['501' => 'a@b.example', '502' => 'bad'];
        $fake->lists          = ['7' => ['name' => "List\u{200B} one", 'type' => '0-1', 'members' => ['2001', '2002']], '8' => ['name' => 'Odd', 'type' => '0-9', 'members' => []]];
        $fake->associations   = ['2001' => [['1001', true]]];
        $reader               = $this->reader($fake);
        $token                = new HubSpotToken($fake->token);
        self::assertSame('4242', $reader->accountId($token)->value);
        self::assertSame($fake->scopes, $reader->tokenInfo($token)->scopes);
        self::assertSame(3, $reader->count($token, HubSpotObject::Company));
        $properties = $reader->properties($token, HubSpotObject::Company);
        self::assertNotContains('secret_note', array_map(static fn (HubSpotProperty $p): string => $p->name->value, $properties));
        $page = $reader->records($token, HubSpotObject::Company, [new HubSpotKey('name')], null, 2);
        self::assertSame(['1001', '1002'], array_map(static fn (HubSpotRecord $r): string => $r->id->value, $page->records));
        self::assertSame(['name' => 'Company 1001'], $page->records[0]->properties);
        self::assertSame('2', $page->next?->value, 'only paging.next.after, never the link');
        self::assertNull($reader->records($token, HubSpotObject::Company, [], new HubSpotCursor('2'), 2)->next);
        self::assertSame([null], array_values($reader->records($token, HubSpotObject::Contact, [new HubSpotKey('phone')], null, 1)->records[0]->properties));
        $associations = $reader->associations($token, [new HubSpotObjectId('2001'), new HubSpotObjectId('2002')]);
        self::assertSame([['2001', '1001', true]], array_map(static fn (HubSpotAssociation $a): array => [$a->contact->value, $a->company->value, $a->primary], $associations));
        self::assertSame([], $reader->associations($token, []));
        $owners = $reader->owners($token, null, 1);
        self::assertSame(['a@b.example', '1'], [$owners->owners[0]->email?->value, $owners->next?->value]);
        self::assertNull($reader->owners($token, new HubSpotCursor('1'), 1)->owners[0]->email);
        $lists = $reader->lists($token);
        self::assertSame(['List one', HubSpotObject::Contact, 2], [$lists[0]->name, $lists[0]->object, $lists[0]->size]);
        self::assertNull($lists[1]->object);
        self::assertCount(1, $reader->lists($token, [new HubSpotObjectId('8')]));
        $members = $reader->members($token, new HubSpotObjectId('7'), null, 1);
        self::assertSame(['2001', '1'], [$members->ids[0]->value, $members->next?->value]);
        self::assertSame(['2002', null], [$reader->members($token, new HubSpotObjectId('7'), new HubSpotCursor('1'), 1)->ids[0]->value, $reader->members($token, new HubSpotObjectId('7'), new HubSpotCursor('1'), 1)->next]);
        $fake->failNext('/crm/v3/lists/search', 503);
        $this->fails(static fn (): bool => $reader->probe($token, 'crm.lists.read'), HubSpotException::UNAVAILABLE);
        self::assertTrue($reader->probe($token, 'crm.lists.read'));
        self::assertTrue($reader->probe($token, 'crm.objects.owners.read'));
        $fake->scopes = ['crm.objects.companies.read'];
        self::assertFalse($reader->probe($token, 'crm.lists.read'));
        $this->fails(static fn (): bool => $reader->probe($token, 'crm.unknown.read'), HubSpotException::BAD_RESPONSE);
        $fake->introspect = false;
        $this->fails(static fn (): HubSpotTokenInfo => $reader->tokenInfo($token), HubSpotException::UNKNOWN_TOKEN);
        // Every request went to the constant host, with the bearer header only.
        foreach ($fake->requests as [, $url]) {
            self::assertStringStartsWith(HttpHubSpotReader::BASE_URL . '/', $url);
        }
    }

    public function testHostileAnswersBecomeCodesWithoutHubSpotText(): void
    {
        $fake   = new FakeHubSpot();
        $reader = $this->reader($fake, 1024);
        $token  = new HubSpotToken($fake->token);
        $cases  = [
            [401, [], '{"message":"secret detail"}', HubSpotException::TOKEN_INVALID],
            [403, [], '{}', HubSpotException::SCOPE_MISSING],
            [302, ['location' => 'http://169.254.169.254/'], '', HubSpotException::BAD_RESPONSE],
            [404, [], '{}', HubSpotException::BAD_RESPONSE],
            [500, [], 'oops', HubSpotException::UNAVAILABLE],
            [200, [], 'not json', HubSpotException::BAD_RESPONSE],
            [200, [], '"a string"', HubSpotException::BAD_RESPONSE],
            [200, [], str_repeat('x', 2048), HubSpotException::BAD_RESPONSE],
        ];
        foreach ($cases as [$status, $headers, $body, $code]) {
            $fake->failNext('/crm/v3/owners', $status, $headers, $body);
            $e = $this->fails(static fn (): HubSpotOwnerPage => $reader->owners($token, null, 10), $code);
            self::assertStringNotContainsString('secret detail', $e->getMessage());
            self::assertStringNotContainsString($fake->token, $e->getMessage());
        }
        $fake->failNext('/crm/v3/owners', 429, ['retry-after' => '7']);
        self::assertSame([7, false], [$this->fails(static fn (): HubSpotOwnerPage => $reader->owners($token, null, 10), HubSpotException::RATE_LIMITED)->retryAfter, false]);
        $fake->failNext('/crm/v3/owners', 429, ['retry-after' => 'Wed, 21 Oct 2026 07:28:00 GMT'], '{"policyName":"DAILY"}');
        $daily = $this->fails(static fn (): HubSpotOwnerPage => $reader->owners($token, null, 10), HubSpotException::RATE_LIMITED);
        self::assertSame([null, true], [$daily->retryAfter, $daily->daily]);
        foreach ([
            ['/crm/v3/owners', '{"results": "x"}', static fn (): HubSpotOwnerPage => $reader->owners($token, null, 10)],
            ['/crm/v3/owners', '{"results": [1]}', static fn (): HubSpotOwnerPage => $reader->owners($token, null, 10)],
            ['/crm/v3/owners', '{"results": [{"id": "0"}]}', static fn (): HubSpotOwnerPage => $reader->owners($token, null, 10)],
            ['/crm/v3/owners', '{"results": [], "paging": {"next": {"after": "https://evil.example/"}}}', static fn (): HubSpotOwnerPage => $reader->owners($token, null, 10)],
            ['/crm/v3/objects/companies/search', '{"total": -1}', static fn (): int => $reader->count($token, HubSpotObject::Company)],
            ['/crm/v3/lists/search', '{"lists": "x"}', static fn (): array => $reader->lists($token)],
            ['/crm/v3/lists/search', '{"lists": [1]}', static fn (): array => $reader->lists($token)],
            ['/crm/v4/associations/contacts/companies/batch/read', '{"results": [{"from": {"id": "1"}, "to": [1]}]}', static fn (): array => $reader->associations($token, [new HubSpotObjectId('1')])],
            ['/account-info/v3/details', '{"portalId": "x"}', static fn (): HubSpotAccountId => $reader->accountId($token)],
        ] as [$path, $body, $call]) {
            $fake->failNext($path, 200, [], $body);
            $this->fails($call, HubSpotException::BAD_RESPONSE);
        }
        $fake->failNext('/oauth/v2/private-apps/get/access-token-info', 500);
        $this->fails(static fn (): HubSpotTokenInfo => $reader->tokenInfo($token), HubSpotException::UNAVAILABLE);
        $fake->failNext('/oauth/v2/private-apps/get/access-token-info', 200, [], '{"hubId": 4242, "scopes": ["crm.objects.contacts.read", "BAD SCOPE", 5]}');
        $info = $reader->tokenInfo($token);
        self::assertSame(['crm.objects.contacts.read', '!unreadable', '!unreadable'], $info->scopes, 'unreadable entries fail closed');
        self::assertSame(['!unreadable', '!unreadable'], $info->writeScopes());
        $many = '{"hubId": 4242, "scopes": [' . implode(',', array_fill(0, 201, '"crm.objects.contacts.read"')) . ']}';
        $fake->failNext('/oauth/v2/private-apps/get/access-token-info', 200, [], $many);
        self::assertSame(['!unreadable'], $this->reader($fake)->tokenInfo($token)->writeScopes(), 'past the 200th entry');
        $fake->failNext('/crm/v3/properties/companies', 200, [], '{"results": [{"name": "ok", "label": "", "type": 5}, {"name": "bad name"}, {"name": "x", "label": "\u0007"}]}');
        self::assertSame([['ok', 'ok', 'string'], ['x', 'x', 'string']], array_map(static fn (HubSpotProperty $p): array => [$p->name->value, $p->label, $p->type], $reader->properties($token, HubSpotObject::Company)));
        $fake->failNext('/crm/v3/objects/companies', 200, [], '{"results": [{"id": 12, "properties": {"a": 3, "b": true, "c": false, "d": 1.5, "e": [1]}}]}');
        self::assertSame(['a' => '3', 'b' => 'true', 'c' => 'false', 'd' => '1.5', 'e' => null], $reader->records($token, HubSpotObject::Company, array_map(static fn (string $k): HubSpotKey => new HubSpotKey($k), ['a', 'b', 'c', 'd', 'e']), null, 10)->records[0]->properties);
        $fake->failNext('/crm/v3/lists/search', 200, [], '{"lists": [{"listId": 9, "name": 5, "objectTypeId": 3, "size": 4}]}');
        self::assertSame(['', null, 4], [$reader->lists($token)[0]->name, $reader->lists($token)[0]->object ?? null, 4]);
    }

    public function testTransportFailuresAndTheGuardPin(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => throw new TransportException('down: secret'));
        $reader = new HttpHubSpotReader($client);
        $this->fails(static fn (): HubSpotOwnerPage => $reader->owners(new HubSpotToken('fake-key-12345678'), null, 10), HubSpotException::UNAVAILABLE);
        $seen   = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen[] = $options['resolve'] ?? null;

            return new MockResponse('{"portalId": 1}');
        });
        $guard  = new OutboundUrlGuard(static fn (string $url): array => ['resolve' => ['api.hubapi.com' => '203.0.113.9']]);
        $reader = new HttpHubSpotReader($client, 8388608, new GuardedHubSpotRequestOptions($guard));
        self::assertSame('1', $reader->accountId(new HubSpotToken('fake-key-12345678'))->value);
        self::assertSame([['api.hubapi.com' => '203.0.113.9']], $seen);
        self::assertSame([[HttpHubSpotReader::BASE_URL . '/account-info/v3/details', false, true]], $guard->calls, 'private addresses never allowed, DNS always resolved');
        $unsafe = new GuardedHubSpotRequestOptions(new OutboundUrlGuard(static fn (): array => throw new UnsafeOutboundUrlException('private')));
        $this->fails(static fn (): HubSpotAccountId => (new HttpHubSpotReader($client, 8388608, $unsafe))->accountId(new HubSpotToken('fake-key-12345678')), HubSpotException::BAD_RESPONSE);
        $plain = new HttpHubSpotReader($client, 8388608, new class implements HubSpotRequestOptionsInterface {
            public function options(string $url): array
            {
                return [];
            }
        });
        self::assertSame('1', $plain->accountId(new HubSpotToken('fake-key-12345678'))->value);
        self::assertNotSame(HubSpotHttpClientFactory::create(5, 10), HubSpotHttpClientFactory::create(5, 10));
    }

    public function testTheSodiumBox(): void
    {
        $box     = new SodiumCrmSecretBox(['k1' => str_repeat('a', 32), 'k2' => str_repeat('b', 32)], 'k2');
        $context = new SecretContext(SecretContext::HUBSPOT_TOKEN, new TenantRef('acme'), '01890000-0000-7000-8000-000000000001');
        $sealed  = $box->seal('fake-credential', $context);
        self::assertSame(['k2', 'k2'], [$sealed->keyId, $box->currentKeyId()]);
        self::assertNotSame($sealed->ciphertext, $box->seal('fake-credential', $context)->ciphertext, 'a random nonce per seal');
        self::assertSame('fake-credential', $box->unseal($sealed, $context));
        self::assertStringNotContainsString(str_repeat('a', 32), print_r($box, true));
        $other = new SecretContext(SecretContext::HUBSPOT_TOKEN, new TenantRef('acme'), '01890000-0000-7000-8000-000000000002');
        foreach ([[$sealed, $other], [new SealedSecret('k1', $sealed->ciphertext), $context], [new SealedSecret('k2', 'AAAA'), $context]] as [$value, $ctx]) {
            try {
                $box->unseal($value, $ctx);
                self::fail('does not open');
            } catch (SecretUnsealException $e) {
                self::assertSame('The secret does not open.', $e->getMessage());
            }
        }
        // 0.10.1 (ZAM-D2): a key id that is not configured says nothing about the value (it is kept).
        try {
            $box->unseal(new SealedSecret('k9', $sealed->ciphertext), $context);
            self::fail('the key is not available');
        } catch (SecretKeyUnavailableException $e) {
            self::assertSame(['k9', 'The key "k9" of a sealed secret is not available.'], [$e->keyId, $e->getMessage()]);
        }
        foreach ([static fn (): SodiumCrmSecretBox => new SodiumCrmSecretBox(['k1' => 'short'], 'k1'), static fn (): SodiumCrmSecretBox => new SodiumCrmSecretBox(['BAD' => str_repeat('a', 32)], 'BAD'), static fn (): SodiumCrmSecretBox => new SodiumCrmSecretBox(['k1' => str_repeat('a', 32)], 'k2')] as $bad) {
            try {
                $bad();
                self::fail('refused');
            } catch (LogicException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testTheDoctrineEncryptBridge(): void
    {
        $custom = new class implements EncryptorInterface {
            public function encrypt(string $data): string
            {
                return 'C:' . $data;
            }

            public function decrypt(string $data): string
            {
                return substr($data, 2);
            }
        };
        $registry = new class($custom) implements EncryptorRegistryInterface {
            public function __construct(private EncryptorInterface $custom)
            {
            }

            public function get(string $name): EncryptorInterface
            {
                return match ($name) {
                    'aes'     => new MysqlAesEncryptor(),
                    'defuse'  => new DefuseEncryptor(),
                    'custom'  => $this->custom,
                    'missing' => throw new InvalidArgumentException('Unknown encryptor config "missing".'),
                    'broken'  => new class implements EncryptorInterface {
                        public function encrypt(string $data): string
                        {
                            throw new RuntimeException('The key file is missing.');
                        }

                        public function decrypt(string $data): string
                        {
                            return $data;
                        }
                    },
                    default => new HaliteEncryptor(),
                };
            }

            public function getDefault(): EncryptorInterface
            {
                return new HaliteEncryptor();
            }
        };
        $box     = new DoctrineEncryptCrmSecretBox($registry);
        $context = new SecretContext(SecretContext::HUBSPOT_TOKEN, new TenantRef('acme'), '01890000-0000-7000-8000-000000000001');
        $sealed  = $box->seal('fake-credential', $context);
        self::assertSame([DoctrineEncryptCrmSecretBox::KEY_ID, DoctrineEncryptCrmSecretBox::KEY_ID], [$sealed->keyId, $box->currentKeyId()]);
        self::assertSame('fake-credential', $box->unseal($sealed, $context));
        self::assertSame('fake-credential', (new DoctrineEncryptCrmSecretBox($registry, 'personal'))->unseal($sealed, $context));
        $other = new SecretContext(SecretContext::HUBSPOT_TOKEN, new TenantRef('globex'), '01890000-0000-7000-8000-000000000001');
        foreach ([[$sealed, $other], [new SealedSecret(DoctrineEncryptCrmSecretBox::KEY_ID, 'A'), $context], [new SealedSecret(DoctrineEncryptCrmSecretBox::KEY_ID, base64_encode('nope')), $context], [new SealedSecret(DoctrineEncryptCrmSecretBox::KEY_ID, base64_encode('E:' . $context->aad() . "\n")), $context]] as [$value, $ctx]) {
            try {
                $box->unseal($value, $ctx);
                self::fail('does not open');
            } catch (SecretUnsealException) {
                self::addToAssertionCount(1);
            }
        }
        // 0.10.1 (ZAM-D2): a key id that is not configured, an unknown profile, or an encryptor that fails for another
        // reason than the value (a missing key) is "key not available": never a bad value, the token is kept.
        foreach ([
            static fn (): string => $box->unseal(new SealedSecret('k1', $sealed->ciphertext), $context),
            static fn (): string => (new DoctrineEncryptCrmSecretBox($registry, 'defuse'))->unseal($sealed, $context),
            static fn (): string => (new DoctrineEncryptCrmSecretBox($registry, 'missing'))->unseal($sealed, $context),
            static fn (): SealedSecret => (new DoctrineEncryptCrmSecretBox($registry, 'missing'))->seal('x', $context),
            static fn (): SealedSecret => (new DoctrineEncryptCrmSecretBox($registry, 'broken', true))->seal('x', $context),
        ] as $unavailable) {
            try {
                $unavailable();
                self::fail('the key is not available');
            } catch (SecretKeyUnavailableException) {
                self::addToAssertionCount(1);
            }
        }
        // A custom encryptor only with the explicit opt-in; MysqlAes never.
        self::assertSame('fake-credential', (new DoctrineEncryptCrmSecretBox($registry, 'custom', true))->unseal((new DoctrineEncryptCrmSecretBox($registry, 'custom', true))->seal('fake-credential', $context), $context));
        foreach ([new DoctrineEncryptCrmSecretBox($registry, 'custom'), new DoctrineEncryptCrmSecretBox($registry, 'aes', true)] as $refused) {
            try {
                $refused->seal('x', $context);
                self::fail('refused');
            } catch (LogicException) {
                self::addToAssertionCount(1);
            }
        }
        // Audit ZAW-L1: the key loads in its own step, so a key error is "not available" and only a value error does
        // not open. A wrong-length Halite key (InvalidKey), an unreadable one (CannotPerformOperation) and a bad Defuse
        // key string (BadFormatException while the key loads) keep the token; a tampered value or a malformed
        // ciphertext (BadFormatException once the key loaded) does not open.
        $failing = static fn (?Throwable $load, ?Throwable $value): EncryptorInterface => new class($load, $value) implements EncryptorInterface {
            public function __construct(private ?Throwable $load, private ?Throwable $value)
            {
            }

            public function encrypt(string $data): string
            {
                return $this->load instanceof Throwable ? throw $this->load : 'E:' . $data;
            }

            public function decrypt(string $data): string
            {
                if ($this->load instanceof Throwable) {
                    throw $this->load;
                }

                return $this->value instanceof Throwable ? throw $this->value : substr($data, 2);
            }
        };
        $cases = [[new InvalidKey('wrong length'), null], [new CannotPerformOperation('unreadable'), null], [new BadFormatException('bad key string'), null], [null, new InvalidMessage('tampered')], [null, new BadFormatException('bad ciphertext')], [null, new InvalidSignature('tampered')]];
        foreach ($cases as [$load, $value]) {
            $faulty = new DoctrineEncryptCrmSecretBox(new EncryptorRegistry(['default' => $failing($load, $value)]), null, true);

            try {
                $faulty->unseal($sealed, $context);
                self::fail('refused');
            } catch (SecretKeyUnavailableException $e) {
                self::assertInstanceOf(Throwable::class, $load, 'a key error');
                self::assertSame(['key_id' => DoctrineEncryptCrmSecretBox::KEY_ID, 'cause' => $load::class], $e->logContext());
            } catch (SecretUnsealException) {
                self::assertNull($load, 'a value error');
            }
        }
        self::assertSame(['key_id' => 'k9', 'cause' => null], (new SecretKeyUnavailableException('k9'))->logContext());
        // 0.10.1 (ZAM-D2): rotation through a second profile; values sealed before it keep opening.
        $rotated = new DoctrineEncryptCrmSecretBox($registry, 'crm_2026', false, 'de2026', [DoctrineEncryptCrmSecretBox::KEY_ID => null]);
        self::assertSame('fake-credential', $rotated->unseal($sealed, $context), 'sealed before the rotation');
        $resealed = $rotated->seal('fake-credential', $context);
        self::assertSame(['de2026', 'de2026'], [$resealed->keyId, $rotated->currentKeyId()]);
        self::assertSame('fake-credential', $rotated->unseal($resealed, $context));
        // The old key id mapped to a profile that cannot read it: never taken for a bad value.
        $this->expectUnavailable(static fn (): string => (new DoctrineEncryptCrmSecretBox($registry, 'crm_2026', false, 'de2026', [DoctrineEncryptCrmSecretBox::KEY_ID => 'defuse']))->unseal($sealed, $context));
        foreach ([static fn (): DoctrineEncryptCrmSecretBox => new DoctrineEncryptCrmSecretBox($registry, null, false, 'BAD'), static fn (): DoctrineEncryptCrmSecretBox => new DoctrineEncryptCrmSecretBox($registry, null, false, 'de2026', ['Bad Id' => null]), static fn (): DoctrineEncryptCrmSecretBox => new DoctrineEncryptCrmSecretBox($registry, null, false, 'de2026', ['de2026' => 'old'])] as $bad) {
            try {
                $bad();
                self::fail('refused');
            } catch (LogicException) {
                self::addToAssertionCount(1);
            }
        }
    }

    private function expectUnavailable(Closure $call): void
    {
        try {
            $call();
            self::fail('the key is not available');
        } catch (SecretKeyUnavailableException) {
            self::addToAssertionCount(1);
        }
    }

    public function testTheMessengerParts(): void
    {
        $clock = new MockClock('2026-11-23 10:00:00');
        $bus   = new class implements MessageBusInterface {
            /** @var list<Envelope> */
            public array $sent = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                return $this->sent[] = new Envelope($message, $stamps);
            }
        };
        $dispatcher = new MessengerImportRunDispatcher($bus, $clock);
        $dispatcher->dispatch(new TenantRef('acme'), 'i1');
        $dispatcher->dispatch(new TenantRef('acme'), 'i2', new DateTimeImmutable('2026-11-23 10:00:30'));
        $dispatcher->dispatch(new TenantRef('acme'), 'i3', new DateTimeImmutable('2026-11-23 09:00:00'));
        self::assertNull($bus->sent[0]->last(DelayStamp::class));
        self::assertSame(30000, $bus->sent[1]->last(DelayStamp::class)?->getDelay());
        self::assertSame(0, $bus->sent[2]->last(DelayStamp::class)?->getDelay());
        $message = $bus->sent[0]->getMessage();
        self::assertInstanceOf(RunCrmImport::class, $message);
        self::assertSame(['i1', 'acme'], [$message->importId, $message->tenantId]);
        self::assertTrue((new ReflectionClass(RunCrmImportHandler::class))->isFinal());
        self::assertTrue((new ReflectionClass(ImportService::class))->hasMethod('runQueued'));
        self::assertTrue((new ReflectionClass(ImportRunDispatcherInterface::class))->isInterface());
    }

    private function reader(FakeHubSpot $fake, int $max = 8388608): HttpHubSpotReader
    {
        return new HttpHubSpotReader(new MockHttpClient($fake, HttpHubSpotReader::BASE_URL), $max);
    }

    private function fails(callable $call, string $code): HubSpotException
    {
        try {
            $call();
        } catch (HubSpotException $e) {
            self::assertSame($code, $e->errorCode);

            return $e;
        }
        self::fail('Expected ' . $code);
    }
}
