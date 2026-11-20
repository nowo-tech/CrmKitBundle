<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database\Api;

use Nowo\CrmKitBundle\Api\CrmApiGrant;
use Nowo\CrmKitBundle\Api\CrmApiLevel;
use Nowo\CrmKitBundle\Api\CrmApiPrincipal;
use Nowo\CrmKitBundle\Api\CrmApiReach;
use Nowo\CrmKitBundle\Api\CrmApiRefusal;
use Nowo\CrmKitBundle\Api\Input\IdempotencyKey;
use Nowo\CrmKitBundle\Api\Operation\CrmApiDealOperations;
use Nowo\CrmKitBundle\Api\Operation\CrmApiNextActionOperations;
use Nowo\CrmKitBundle\Api\Operation\CrmApiReadOperations;
use Nowo\CrmKitBundle\Api\Operation\CrmApiRecordOperations;
use Nowo\CrmKitBundle\Api\Operation\CrmApiRelationOperations;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Nowo\CrmKitBundle\Tests\Fixtures\TestApiState;
use Nowo\CrmKitBundle\Tests\Integration\Database\CrmTestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function array_replace_recursive;
use function bin2hex;
use function is_array;
use function json_decode;
use function random_bytes;

/**
 * Base of the API tests: the bundle with `api.enabled`, `api.http.enabled` and `pipeline.enabled`, the test area
 * checker (`security.access_checker`) and principals built per test.
 */
abstract class ApiTestCase extends CrmTestCase
{
    /**
     * @param array<string, mixed> $config
     */
    protected function bootApi(array $config = []): void
    {
        /** @var array<string, mixed> $merged */
        $merged = array_replace_recursive([
            'security' => ['access_checker' => 'test.areas'],
            'api'      => ['enabled' => true, 'http' => ['enabled' => true, 'authenticator' => 'test.api_authenticator']],
            'pipeline' => ['enabled' => true],
        ], $config);
        $this->boot($merged);
        $this->admin();
        $this->actAs('ana', 'view', 'edit', 'manage', 'amounts_view');
    }

    /**
     * A principal of `ana` (or another actor) with a level and ticks.
     *
     * @param list<string>|null $tenants null: every tenant
     */
    protected function principal(CrmApiLevel $level = CrmApiLevel::Edit, bool $amounts = true, bool $consents = true, bool $destructive = true, string $actor = 'ana', ?array $tenants = null, string $channel = CrmApiPrincipal::CHANNEL_API, string $token = 'tok-1'): CrmApiPrincipal
    {
        return new CrmApiPrincipal(new InMemoryUser($actor, null), new ActorRef($actor), new CrmApiGrant($level, $amounts, $consents, $destructive), $tenants === null ? CrmApiReach::everything() : CrmApiReach::limitedTo($tenants), $token, 'Agent ' . $token, $channel);
    }

    protected function acme(): TenantRef
    {
        return new TenantRef('acme');
    }

    protected function key(?string $value = null): IdempotencyKey
    {
        return new IdempotencyKey($value ?? 'k-' . bin2hex(random_bytes(6)));
    }

    protected function reads(): CrmApiReadOperations
    {
        return $this->service(CrmApiReadOperations::class);
    }

    protected function writes(): CrmApiRecordOperations
    {
        return $this->service(CrmApiRecordOperations::class);
    }

    protected function relations(): CrmApiRelationOperations
    {
        return $this->service(CrmApiRelationOperations::class);
    }

    protected function deals(): CrmApiDealOperations
    {
        return $this->service(CrmApiDealOperations::class);
    }

    protected function nextActions(): CrmApiNextActionOperations
    {
        return $this->service(CrmApiNextActionOperations::class);
    }

    /**
     * Asserts that the call is refused with the code.
     */
    protected function refused(string $code, callable $call): CrmApiRefusal
    {
        try {
            $call();
        } catch (CrmApiRefusal $refusal) {
            self::assertSame($code, $refusal->errorCode, $refusal->getMessage());

            return $refusal;
        }
        self::fail('not refused: ' . $code);
    }

    /**
     * Acts as this principal on the JSON API.
     */
    protected function as(?CrmApiPrincipal $principal): void
    {
        $state = $this->kernel->getContainer()->get('test.api_state');
        self::assertInstanceOf(TestApiState::class, $state);
        $state->principal = $principal;
    }

    /**
     * A JSON request; the decoded answer.
     *
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    protected function api(string $method, string $path, ?array $body = null, array $headers = []): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $this->client->request($method, '/api/crm/v1' . $path, [], [], $server, $body === null ? '' : (string) json_encode($body));
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $out  = [];
        foreach (is_array($data) ? $data : [] as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * @phpstan-impure
     */
    protected function httpCode(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    /**
     * @phpstan-impure
     */
    protected function header(string $name): ?string
    {
        return $this->client->getResponse()->headers->get($name);
    }
}
