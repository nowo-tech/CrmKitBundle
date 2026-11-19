<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database\Api;

use Nowo\CrmKitBundle\Api\CrmApiCode;
use Nowo\CrmKitBundle\Api\CrmApiLevel;
use Nowo\CrmKitBundle\Application\Pipeline\FunnelReader;
use Nowo\CrmKitBundle\Application\Pipeline\FunnelService;
use Nowo\CrmKitBundle\Domain\Enum\FunnelKind;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Service\TagServiceInterface;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Api\CrmApiRouteLoader;
use Nowo\CrmKitBundle\Tests\Fixtures\TestApiState;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function count;
use function is_array;
use function is_string;

/**
 * The `/crm/v1` JSON API (0.3.0): routes, status codes, headers, error bodies.
 */
final class ApiHttpTest extends ApiTestCase
{
    public function testRoutesOffByDefault(): void
    {
        $this->boot();
        $router = $this->kernel->getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        self::assertSame([], array_filter(array_keys($router->getRouteCollection()->all()), static fn (string $n): bool => str_starts_with($n, CrmApiRouteLoader::NAME_PREFIX)));
        self::assertSame('/api/crm/v1', $this->kernel->getContainer()->getParameter('nowo_crm_kit.api_path'));
    }

    public function testTheJsonApi(): void
    {
        $this->bootApi();
        $this->as($this->principal());
        $me = $this->api('GET', '/me');
        self::assertSame([200, 'ana'], [$this->httpCode(), $me['actor_id'] ?? null]);

        // Writes need a key; creates answer 201 with an ETag.
        $this->api('POST', '/companies', ['name' => 'Acme']);
        self::assertSame([428, CrmApiCode::IDEMP_MISSING, CrmApiCode::IDEMP_MISSING], [$this->httpCode(), $this->header('X-Error-Code'), $this->api('POST', '/companies', ['name' => 'Acme'])['code'] ?? null]);
        $this->api('POST', '/companies', ['name' => 'Acme'], ['Idempotency-Key' => 'bad key']);
        self::assertSame(CrmApiCode::IDEMP_INVALID, $this->header('X-Error-Code'));
        $made = $this->api('POST', '/companies', ['name' => 'Acme', 'nope' => 1], ['Idempotency-Key' => 'k1']);
        self::assertSame([400, CrmApiCode::INPUT_MALFORMED, 'nope'], [$this->httpCode(), $made['code'] ?? null, $made['field'] ?? null]);
        $made = $this->api('POST', '/companies', ['name' => 'Acme'], ['Idempotency-Key' => 'k1']);
        self::assertSame([201, '"1"'], [$this->httpCode(), $this->header('ETag')]);
        $record = $made['record'] ?? null;
        self::assertIsArray($record);
        $id = $record['id'] ?? null;
        self::assertIsString($id);
        $this->api('POST', '/companies', ['name' => 'Acme'], ['Idempotency-Key' => 'k1']);
        self::assertSame([201, 'true'], [$this->httpCode(), $this->header('Idempotency-Replayed')]);
        $this->api('POST', '/companies', ['name' => 'Other'], ['Idempotency-Key' => 'k1']);
        self::assertSame([409, CrmApiCode::IDEMP_MISMATCH], [$this->httpCode(), $this->header('X-Error-Code')]);
        self::assertNull($this->header('X-Conflict-Code'), 'a domain conflict never carries X-Conflict-Code');

        // Reads.
        $this->api('GET', '/companies/' . $id);
        self::assertSame([200, '"1"'], [$this->httpCode(), $this->header('ETag')]);
        $this->api('GET', '/companies/not-a-uuid');
        self::assertSame([404, CrmApiCode::NOT_FOUND], [$this->httpCode(), $this->header('X-Error-Code')]);
        $list = $this->api('GET', '/companies?limit=1');
        self::assertSame(200, $this->httpCode());
        self::assertCount(1, is_array($list['items'] ?? null) ? $list['items'] : []);
        $this->api('GET', '/search?types=company,contact&q=ac&sort=updated');
        self::assertSame(200, $this->httpCode());
        $this->api('GET', '/search?limit=0');
        self::assertSame(400, $this->httpCode());
        $this->api('GET', '/search?cursor=abc');
        self::assertSame(CrmApiCode::INPUT_CURSOR, $this->header('X-Error-Code'));
        $this->api('GET', '/search?sort=nope');
        self::assertSame(400, $this->httpCode());
        $this->api('GET', '/search?funnel=00000000-0000-4000-8000-000000000000');
        self::assertSame(400, $this->httpCode());
        $this->api('GET', '/search?types=deal');
        self::assertSame(400, $this->httpCode());
        $this->api('GET', '/search?owner=' . rawurlencode(' x'));
        self::assertSame(400, $this->httpCode());
        $this->api('GET', '/search?role=Bad');
        self::assertSame(400, $this->httpCode());
        $this->api('GET', '/search?q[]=x');
        self::assertSame(400, $this->httpCode());
        foreach (['/companies/' . $id . '/timeline', '/companies/' . $id . '/notes', '/companies/' . $id . '/links', '/companies/' . $id . '/contacts', '/tags', '/funnels'] as $path) {
            $this->api('GET', $path);
            self::assertSame(200, $this->httpCode(), $path);
        }

        // Update needs If-Match (428), stale is 409 CRM_001 with the current version.
        $this->api('PATCH', '/companies/' . $id, ['city' => 'Madrid'], ['Idempotency-Key' => 'u1']);
        self::assertSame([428, CrmApiCode::VERSION_MISSING], [$this->httpCode(), $this->header('X-Error-Code')]);
        $this->api('PATCH', '/companies/' . $id, ['city' => 'Madrid'], ['Idempotency-Key' => 'u1', 'If-Match' => '*']);
        self::assertSame(400, $this->httpCode());
        $stale = $this->api('PATCH', '/companies/' . $id, ['city' => 'Madrid'], ['Idempotency-Key' => 'u1', 'If-Match' => '"5"']);
        self::assertSame([409, CrmApiCode::STALE, 1], [$this->httpCode(), $stale['code'] ?? null, $stale['current_version'] ?? null]);
        $this->api('PATCH', '/companies/' . $id, ['city' => 'Madrid'], ['Idempotency-Key' => 'u2', 'If-Match' => 'W/"1"']);
        self::assertSame([200, '"2"'], [$this->httpCode(), $this->header('ETag')]);
        $this->client->request('PATCH', '/api/crm/v1/companies/' . $id, [], [], ['HTTP_IDEMPOTENCY_KEY' => 'u3', 'HTTP_IF_MATCH' => '2'], '[1,2]');
        self::assertSame(400, $this->httpCode());
        $this->client->request('PATCH', '/api/crm/v1/companies/' . $id, [], [], ['HTTP_IDEMPOTENCY_KEY' => 'u4', 'HTTP_IF_MATCH' => '2'], '{bad');
        self::assertSame(400, $this->httpCode());

        // Relations, notes, tags, next actions, consents.
        $contact = $this->api('POST', '/contacts', ['last_name' => 'Lovelace', 'email' => 'ada@x.test'], ['Idempotency-Key' => 'c1']);
        $cid     = is_array($contact['record'] ?? null) && is_string($contact['record']['id'] ?? null) ? $contact['record']['id'] : '';
        $this->api('PUT', '/contacts/' . $cid . '/companies/' . $id, ['role' => 'CEO'], ['Idempotency-Key' => 'a1']);
        self::assertSame(200, $this->httpCode());
        $this->api('GET', '/contacts/' . $cid . '/companies');
        self::assertSame(200, $this->httpCode());
        $this->api('DELETE', '/contacts/' . $cid . '/companies/' . $id, null, ['Idempotency-Key' => 'a2']);
        self::assertSame(200, $this->httpCode());
        $this->api('POST', '/contacts/' . $cid . '/notes', ['body' => 'Hi'], ['Idempotency-Key' => 'n1']);
        self::assertSame(201, $this->httpCode());
        $tag = $this->service(TagServiceInterface::class)->create('VIP', 'red');
        $this->api('PUT', '/contacts/' . $cid . '/tags/' . $tag, null, ['Idempotency-Key' => 't1']);
        self::assertSame(200, $this->httpCode());
        $this->api('DELETE', '/contacts/' . $cid . '/tags/' . $tag, null, ['Idempotency-Key' => 't2']);
        self::assertSame(200, $this->httpCode());
        $this->api('GET', '/contacts/' . $cid . '/consents?history=true');
        self::assertSame(200, $this->httpCode());
        $this->api('GET', '/contacts/' . $cid . '/consents?history=maybe');
        self::assertSame(400, $this->httpCode());
        $this->api('POST', '/contacts/' . $cid . '/next-actions', ['title' => 'Call', 'due_at' => '2026-11-25T09:00:00+01:00'], ['Idempotency-Key' => 'na1']);
        self::assertSame(201, $this->httpCode());

        // Deals.
        $funnel = $this->service(FunnelReader::class)->funnel($this->service(FunnelService::class)->create(FunnelKind::Deal, null, 'Sales', ['open' => 'Lead']));
        $deal   = $this->api('POST', '/deals', ['title' => 'D', 'funnel_id' => $funnel->id, 'stage_id' => $funnel->stages[0]->id, 'company_id' => $id], ['Idempotency-Key' => 'd1']);
        $did    = is_string($deal['id'] ?? null) ? $deal['id'] : '';
        self::assertSame(201, $this->httpCode());
        $this->api('GET', '/deals/' . $did);
        self::assertSame([200, '"1"'], [$this->httpCode(), $this->header('ETag')]);
        $this->api('GET', '/deals?status=open&stage=' . $funnel->stages[0]->id . '&stale=false&funnel=' . $funnel->id);
        self::assertSame(200, $this->httpCode());
        $this->api('GET', '/deals?status=nope');
        self::assertSame(400, $this->httpCode());
        $this->api('GET', '/deals?stage=x');
        self::assertSame(400, $this->httpCode());
        $this->api('GET', '/funnels/' . $funnel->id . '/stats?at=2026-11-18T10:00:00Z');
        self::assertSame(200, $this->httpCode());
        $this->api('PATCH', '/deals/' . $did, ['title' => 'D2'], ['Idempotency-Key' => 'd2', 'If-Match' => '1']);
        self::assertSame(200, $this->httpCode());
        $this->api('POST', '/deals/' . $did . '/stage', ['stage_id' => $funnel->stages[1]->id], ['Idempotency-Key' => 'd3']);
        self::assertSame(200, $this->httpCode());
        $this->api('GET', '/deals/' . $did . '/timeline');
        self::assertSame(200, $this->httpCode());
        $this->api('POST', '/deals/' . $did . '/next-actions', ['title' => 'Call', 'due_at' => '2026-11-25T09:00:00.5Z'], ['Idempotency-Key' => 'd4']);
        self::assertSame(201, $this->httpCode());
        $this->api('DELETE', '/deals/' . $did, null, ['Idempotency-Key' => 'd5']);
        self::assertSame(200, $this->httpCode());

        // Grants and people; trash.
        $this->as($this->principal(CrmApiLevel::View));
        $this->api('DELETE', '/companies/' . $id, null, ['Idempotency-Key' => 'x1']);
        self::assertSame([403, CrmApiCode::TOKEN], [$this->httpCode(), $this->header('X-Error-Code')]);
        $this->as(null);
        $this->api('GET', '/me');
        self::assertSame(404, $this->httpCode());
        $this->as($this->principal());
        $this->api('DELETE', '/companies/' . $id, null, ['Idempotency-Key' => 'x2']);
        self::assertSame(200, $this->httpCode());
        $this->ctx()->tenant = null;
        $this->api('GET', '/me');
        self::assertSame(404, $this->httpCode());
    }

    public function testTheDefaultAuthenticatorUsesTheFirewallUser(): void
    {
        $this->bootApi(['api' => ['http' => ['authenticator' => null, 'default_grant' => ['level' => 'view']]]]);
        $this->ctx()->grant('acme', 'leo', 'view');
        $this->client->loginUser(new InMemoryUser('leo', 'x', ['ROLE_USER']), 'main');
        $this->client->request('GET', '/api/crm/v1/me');
        $me = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($me);
        $grant = $me['grant'] ?? null;
        $text  = $me['text'] ?? null;
        self::assertIsArray($grant);
        self::assertIsArray($text);
        self::assertSame(['leo', 'view', 'API'], [$me['actor_id'] ?? null, $grant['level'] ?? null, $text['token'] ?? null]);
        $this->client->request('POST', '/api/crm/v1/companies', [], [], ['HTTP_IDEMPOTENCY_KEY' => 'k'], '{"name":"X"}');
        self::assertSame(403, $this->httpCode());
        $this->client->restart();
        $this->client->request('GET', '/api/crm/v1/me');
        self::assertSame(404, $this->httpCode());
    }

    public function testAnUnexpectedFailureIsAServerErrorWithoutDetail(): void
    {
        $this->bootApi();
        $this->as($this->principal());
        $state = $this->kernel->getContainer()->get('test.api_state');
        self::assertInstanceOf(TestApiState::class, $state);
        $state->fail = true;
        $body        = $this->api('GET', '/me');
        self::assertSame(['error' => 'Internal error.', 'code' => CrmApiCode::SERVER], $body);
        self::assertSame([500, CrmApiCode::SERVER], [$this->httpCode(), $this->header('X-Error-Code')]);
    }

    public function testRecordTypesOfTheRoutes(): void
    {
        self::assertSame(SubjectType::Company, SubjectType::from('company'));
        self::assertTrue((new CrmApiRouteLoader(true, '/api/crm/v1'))->supports('.', CrmApiRouteLoader::TYPE));
        self::assertCount(0, (new CrmApiRouteLoader(false, '/x'))->load('.'));
        self::assertLessThan(count(CrmApiRouteLoader::ROUTES), (new CrmApiRouteLoader(true, '/x', false))->load('.')->count());
        self::assertCount(count(CrmApiRouteLoader::ROUTES), (new CrmApiRouteLoader(true, '/x', true))->load('.'));
    }
}
