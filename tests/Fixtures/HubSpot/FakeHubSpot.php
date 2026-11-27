<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Fixtures\HubSpot;

use Closure;
use Symfony\Component\HttpClient\Response\MockResponse;

use function array_key_exists;
use function array_slice;
use function array_values;
use function bin2hex;
use function count;
use function explode;
use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function json_decode;
use function json_encode;
use function parse_str;
use function parse_url;
use function preg_match;
use function random_bytes;
use function str_starts_with;

use const JSON_THROW_ON_ERROR;
use const PHP_URL_PATH;
use const PHP_URL_QUERY;

/**
 * An in-memory HubSpot account behind a MockHttpClient callback (0.10.0 tests): companies, contacts, owners, lists,
 * associations, properties and scopes, with switches for failures. The credential is made at runtime (never a real
 * token, never shaped like HubSpot's `pat-(na|eu)N-`). Fake data only.
 */
final class FakeHubSpot
{
    public const REQUIRED = ['crm.objects.companies.read', 'crm.objects.contacts.read', 'crm.objects.owners.read', 'crm.schemas.companies.read', 'crm.schemas.contacts.read'];

    public string $token;

    public string $account = '4242';

    /** Whether the token-info endpoint knows the credential (a legacy private app token); false: a service key. */
    public bool $introspect = true;

    /** @var list<string> */
    public array $scopes = [...self::REQUIRED, 'crm.lists.read'];

    /** @var array<array-key, array<string, string|null>> */
    public array $companies = [];

    /** @var array<array-key, array<string, string|null>> */
    public array $contacts = [];

    /** @var array<array-key, string|null> owner id => e-mail */
    public array $owners = [];

    /** @var array<array-key, array{name: string, type: string, members: list<string>}> */
    public array $lists = [];

    /**
     * 0.12.0: engagements per kind (`notes`, `calls`, `meetings`, `tasks`): id => properties + associated ids.
     *
     * @var array<string, array<array-key, array{properties: array<string, string|null>, contacts?: list<string>, companies?: list<string>}>>
     */
    public array $engagements = ['notes' => [], 'calls' => [], 'meetings' => [], 'tasks' => []];

    /** @var array<array-key, list<array{string, bool}>> contact id => [company id, primary] */
    public array $associations = [];

    /** @var array<string, list<array{name: string, label: string, type: string, dataSensitivity?: string}>> */
    public array $properties = [
        'companies' => [
            ['name' => 'name', 'label' => 'Company name', 'type' => 'string'],
            ['name' => 'domain', 'label' => 'Domain', 'type' => 'string'],
            ['name' => 'website', 'label' => 'Website', 'type' => 'string'],
            ['name' => 'city', 'label' => 'City', 'type' => 'string'],
            ['name' => 'country', 'label' => 'Country', 'type' => 'string'],
            ['name' => 'hubspot_owner_id', 'label' => 'Owner', 'type' => 'enumeration'],
            ['name' => 'secret_note', 'label' => 'Secret', 'type' => 'string', 'dataSensitivity' => 'sensitive'],
        ],
        'contacts' => [
            ['name' => 'firstname', 'label' => 'First name', 'type' => 'string'],
            ['name' => 'lastname', 'label' => 'Last name', 'type' => 'string'],
            ['name' => 'email', 'label' => 'E-mail', 'type' => 'string'],
            ['name' => 'phone', 'label' => 'Phone <b>', 'type' => 'string'],
            ['name' => 'jobtitle', 'label' => 'Job title', 'type' => 'string'],
            ['name' => 'hubspot_owner_id', 'label' => 'Owner', 'type' => 'enumeration'],
            ['name' => 'vip_level', 'label' => 'VIP level', 'type' => 'string'],
        ],
    ];

    /**
     * Forced answers, first match wins and is consumed: [path prefix, status, headers, body].
     *
     * @var array<int, array{string, int, array<string, string>, string}>
     */
    public array $failures = [];

    /** @var (Closure(string): void)|null called with the path of every request (a test acting meanwhile) */
    public ?Closure $onRequest = null;

    /** @var list<array{string, string}> method, URL of every request */
    public array $requests = [];

    /** @var list<string> every Authorization header seen */
    public array $authorizations = [];

    public function __construct()
    {
        // A fake credential, made at runtime: never a real one.
        $this->token = 'fake-key-' . bin2hex(random_bytes(12));
    }

    /**
     * Adds companies `1..n` (ids from `$from`).
     */
    public function seedCompanies(int $count, int $from = 1001): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $this->companies[(string) ($from + $i)] = ['name' => 'Company ' . ($from + $i), 'domain' => 'c' . ($from + $i) . '.example', 'city' => 'City', 'country' => 'es'];
        }
    }

    /**
     * Adds contacts `1..n` (ids from `$from`).
     */
    public function seedContacts(int $count, int $from = 2001): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $this->contacts[(string) ($from + $i)] = ['firstname' => 'Person', 'lastname' => 'No' . ($from + $i), 'email' => 'p' . ($from + $i) . '@people.example'];
        }
    }

    /**
     * Fails the next request to the path.
     *
     * @param array<string, string> $headers
     */
    public function failNext(string $prefix, int $status, array $headers = [], string $body = '{}'): void
    {
        $this->failures[] = [$prefix, $status, $headers, $body];
    }

    /**
     * The MockHttpClient callback.
     *
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options): MockResponse
    {
        $this->requests[] = [$method, $url];
        $auth             = '';
        $headers          = is_array($options['normalized_headers'] ?? null) ? $options['normalized_headers'] : [];
        $line             = is_array($headers['authorization'] ?? null) ? ($headers['authorization'][0] ?? '') : '';
        if (is_string($line)) {
            $auth = $line;
        }
        $this->authorizations[] = $auth;
        $path                   = (string) parse_url($url, PHP_URL_PATH);
        if ($this->onRequest instanceof Closure) {
            ($this->onRequest)($path);
        }
        foreach ($this->failures as $i => [$prefix, $status, $extra, $body]) {
            if ($path === $prefix || str_starts_with($path, $prefix . '?')) {
                unset($this->failures[$i]);
                $this->failures = array_values($this->failures);

                return new MockResponse($body, ['http_code' => $status, 'response_headers' => $extra]);
            }
        }
        if ($auth !== 'Authorization: Bearer ' . $this->token) {
            return $this->json(['status' => 'error', 'message' => 'Authentication credentials not found'], 401);
        }
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $body = is_string($options['body'] ?? null) && $options['body'] !== '' ? json_decode($options['body'], true, 32, JSON_THROW_ON_ERROR) : [];
        $body = is_array($body) ? $body : [];

        return match (true) {
            $path === '/account-info/v3/details'                                                     => $this->json(['portalId' => (int) $this->account, 'timeZone' => 'UTC', 'companyCurrency' => 'EUR']),
            $path === '/oauth/v2/private-apps/get/access-token-info'                                 => $this->introspect ? $this->json(['hubId' => (int) $this->account, 'scopes' => $this->scopes, 'userId' => 7]) : $this->json(['status' => 'error'], 400),
            preg_match('#\A/crm/v3/objects/(companies|contacts)/search\z#', $path, $m) === 1         => $this->scoped('crm.objects.' . $m[1] . '.read', fn (): MockResponse => $this->json(['total' => count($m[1] === 'companies' ? $this->companies : $this->contacts), 'results' => []])),
            preg_match('#\A/crm/v3/objects/(notes|calls|meetings|tasks)/search\z#', $path, $m) === 1 => $this->scoped('crm.objects.contacts.read', fn (): MockResponse => $this->json(['total' => count($this->engagements[$m[1]]), 'results' => []])),
            preg_match('#\A/crm/v3/objects/(notes|calls|meetings|tasks)\z#', $path, $m) === 1        => $this->scoped('crm.objects.contacts.read', fn (): MockResponse => $this->engagementPage($this->engagements[$m[1]], $query)),
            preg_match('#\A/crm/v3/objects/(companies|contacts)\z#', $path, $m) === 1                => $this->scoped('crm.objects.' . $m[1] . '.read', fn (): MockResponse => $this->page($m[1] === 'companies' ? $this->companies : $this->contacts, $query)),
            preg_match('#\A/crm/v3/properties/(companies|contacts)\z#', $path, $m) === 1             => $this->scoped('crm.schemas.' . $m[1] . '.read', fn (): MockResponse => $this->json(['results' => $this->properties[$m[1]]])),
            $path === '/crm/v3/owners'                                                               => $this->scoped('crm.objects.owners.read', fn (): MockResponse => $this->owners($query)),
            $path === '/crm/v4/associations/contacts/companies/batch/read'                           => $this->scoped('crm.objects.contacts.read', fn (): MockResponse => $this->associationsOf($body)),
            $path === '/crm/v3/lists/search'                                                         => $this->scoped('crm.lists.read', fn (): MockResponse => $this->listsOf($body)),
            preg_match('#\A/crm/v3/lists/([0-9]+)/memberships\z#', $path, $m) === 1                  => $this->scoped('crm.lists.read', fn (): MockResponse => $this->members($m[1], $query)),
            default                                                                                  => $this->json(['status' => 'error'], 404),
        };
    }

    /**
     * @param callable(): MockResponse $answer
     */
    private function scoped(string $scope, callable $answer): MockResponse
    {
        return in_array($scope, $this->scopes, true) ? $answer() : $this->json(['status' => 'error', 'category' => 'MISSING_SCOPES'], 403);
    }

    /**
     * @param array<array-key, array<string, string|null>> $rows
     * @param array<array-key, mixed> $query
     */
    private function page(array $rows, array $query): MockResponse
    {
        $limit  = $this->int($query['limit'] ?? 100);
        $offset = $this->int($query['after'] ?? 0);
        $wanted = is_string($query['properties'] ?? null) ? explode(',', $query['properties']) : [];
        $slice  = array_slice($rows, $offset, $limit, true);
        $out    = [];
        foreach ($slice as $id => $properties) {
            $kept = [];
            foreach ($wanted as $name) {
                if (array_key_exists($name, $properties)) {
                    $kept[$name] = $properties[$name];
                }
            }
            $out[] = ['id' => (string) $id, 'properties' => $kept + ['hs_object_id' => (string) $id], 'archived' => false, 'unknownField' => ['x' => 1]];
        }
        $next = $offset + $limit < count($rows) ? ['next' => ['after' => (string) ($offset + $limit), 'link' => 'http://169.254.169.254/latest/meta-data']] : null;

        return $this->json(['results' => $out] + ($next === null ? [] : ['paging' => $next]));
    }

    /**
     * A page of engagements with their requested properties and `associations=contacts,companies` (v3 shape).
     *
     * @param array<array-key, array{properties: array<string, string|null>, contacts?: list<string>, companies?: list<string>}> $rows
     * @param array<array-key, mixed> $query
     */
    private function engagementPage(array $rows, array $query): MockResponse
    {
        $limit  = $this->int($query['limit'] ?? 100);
        $offset = $this->int($query['after'] ?? 0);
        $wanted = is_string($query['properties'] ?? null) ? explode(',', $query['properties']) : [];
        $out    = [];
        foreach (array_slice($rows, $offset, $limit, true) as $id => $row) {
            $kept = [];
            foreach ($wanted as $name) {
                if (array_key_exists($name, $row['properties'])) {
                    $kept[$name] = $row['properties'][$name];
                }
            }
            $associations = [];
            foreach (['contacts', 'companies'] as $kind) {
                $ids = $row[$kind] ?? [];
                if ($ids !== [] && is_string($query['associations'] ?? null) && in_array($kind, explode(',', $query['associations']), true)) {
                    $results = [];
                    foreach ($ids as $other) {
                        // HubSpot repeats an id per association type.
                        $results[] = ['id' => $other, 'type' => 'engagement_to_' . $kind];
                        $results[] = ['id' => $other, 'type' => 'engagement_to_' . $kind . '_unlabeled'];
                    }
                    $associations[$kind] = ['results' => $results];
                }
            }
            $out[] = ['id' => (string) $id, 'properties' => $kept + ['hs_object_id' => (string) $id], 'archived' => false] + ($associations === [] ? [] : ['associations' => $associations]);
        }
        $next = $offset + $limit < count($rows) ? ['paging' => ['next' => ['after' => (string) ($offset + $limit)]]] : [];

        return $this->json(['results' => $out] + $next);
    }

    /**
     * @param array<array-key, mixed> $query
     */
    private function owners(array $query): MockResponse
    {
        $limit  = $this->int($query['limit'] ?? 100);
        $offset = $this->int($query['after'] ?? 0);
        $out    = [];
        foreach (array_slice($this->owners, $offset, $limit, true) as $id => $email) {
            $out[] = ['id' => (string) $id, 'email' => $email, 'firstName' => 'Owner', 'archived' => false];
        }
        $next = $offset + $limit < count($this->owners) ? ['paging' => ['next' => ['after' => (string) ($offset + $limit)]]] : [];

        return $this->json(['results' => $out] + $next);
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private function associationsOf(array $body): MockResponse
    {
        $results = [];
        foreach (is_array($body['inputs'] ?? null) ? $body['inputs'] : [] as $input) {
            $id = is_array($input) && is_string($input['id'] ?? null) ? $input['id'] : '';
            $to = [];
            foreach ($this->associations[$id] ?? [] as [$company, $primary]) {
                $types = [['category' => 'HUBSPOT_DEFINED', 'typeId' => 279, 'label' => null]];
                if ($primary) {
                    $types[] = ['category' => 'HUBSPOT_DEFINED', 'typeId' => 1, 'label' => 'Primary'];
                }
                $to[] = ['toObjectId' => (int) $company, 'associationTypes' => $types];
            }
            if ($to !== []) {
                $results[] = ['from' => ['id' => $id], 'to' => $to];
            }
        }

        return $this->json(['status' => 'COMPLETE', 'results' => $results], 207);
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private function listsOf(array $body): MockResponse
    {
        $ids = is_array($body['listIds'] ?? null) ? $body['listIds'] : null;
        $out = [];
        foreach ($this->lists as $id => $list) {
            if ($ids === null || in_array((string) $id, $ids, true)) {
                $out[] = ['listId' => (string) $id, 'name' => $list['name'], 'objectTypeId' => $list['type'], 'additionalProperties' => ['hs_list_size' => (string) count($list['members'])]];
            }
        }

        return $this->json(['lists' => $out, 'hasMore' => false, 'offset' => 0]);
    }

    /**
     * @param array<array-key, mixed> $query
     */
    private function members(string $list, array $query): MockResponse
    {
        $limit   = $this->int($query['limit'] ?? 100);
        $offset  = $this->int($query['after'] ?? 0);
        $members = $this->lists[$list]['members'] ?? [];
        $out     = [];
        foreach (array_slice($members, $offset, $limit) as $id) {
            $out[] = ['recordId' => $id, 'membershipTimestamp' => '2026-01-01T00:00:00Z'];
        }
        $next = $offset + $limit < count($members) ? ['paging' => ['next' => ['after' => (string) ($offset + $limit)]]] : [];

        return $this->json(['results' => $out] + $next);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function json(array $data, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($data, JSON_THROW_ON_ERROR), ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']]);
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
