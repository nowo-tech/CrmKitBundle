<?php

declare(strict_types=1);

namespace App\Crm;

use Symfony\Component\HttpClient\Response\MockResponse;

use function array_key_exists;
use function array_slice;
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
use function strtolower;
use function strtr;

use const JSON_THROW_ON_ERROR;
use const PHP_URL_PATH;
use const PHP_URL_QUERY;

/**
 * Demo only (0.10.3): an in-memory HubSpot account behind a MockHttpClient callback (config/packages/nowo_crm_kit.yaml
 * decorates the kit's HubSpot client), so the HubSpot steps of the import modal can be tried and screenshotted without
 * a HubSpot account. A copy of the kit's test fixture (tests/Fixtures/HubSpot/FakeHubSpot.php) with a fixed, obviously
 * fake demo credential (`demo-hubspot-key`, never shaped like HubSpot's `pat-(na|eu)N-`) and a few sample records. Fake
 * data only; nothing leaves the demo. Worker mode (a shared service kept alive between requests, audit ZBJ-L1): it
 * records nothing per request — no request log, no Authorization header kept, no forced failures (those test hooks stay
 * in the kit's test double); only the fixed sample account lives in its properties.
 */
final class DemoHubSpot
{
    public const REQUIRED = ['crm.objects.companies.read', 'crm.objects.contacts.read', 'crm.objects.owners.read', 'crm.schemas.companies.read', 'crm.schemas.contacts.read'];

    /** The demo credential: fake, documented in the demo README. */
    public const DEMO_KEY = 'demo-hubspot-key';

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

    public function __construct()
    {
        // The demo's fake credential (type it in "Connect HubSpot"): never a real one.
        $this->token = self::DEMO_KEY;
        foreach (['Globex Iberia' => 'Madrid', 'Initech Labs' => 'Barcelona', 'Umbrella Foods' => 'Sevilla', 'Hooli Retail' => 'Valencia'] as $name => $city) {
            $this->companies[(string) (1001 + count($this->companies))] = ['name' => $name, 'domain' => strtolower(strtr($name, [' ' => ''])) . '.example', 'city' => $city, 'country' => 'es'];
        }
        foreach ([['Lucia', 'Gomez'], ['Marco', 'Rossi'], ['Emma', 'Laurent'], ['Jonas', 'Weber'], ['Sofia', 'Silva']] as [$first, $last]) {
            $this->contacts[(string) (2001 + count($this->contacts))] = ['firstname' => $first, 'lastname' => $last, 'email' => strtolower($first[0] . $last) . '@people.example'];
        }
        $this->owners = ['7001' => 'ana@demo.test'];
        $this->lists  = ['501' => ['name' => 'Trade fair 2026', 'type' => '0-1', 'members' => ['2001', '2002']]];
    }

    /**
     * The MockHttpClient callback.
     *
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options): MockResponse
    {
        $auth             = '';
        $headers          = is_array($options['normalized_headers'] ?? null) ? $options['normalized_headers'] : [];
        $line             = is_array($headers['authorization'] ?? null) ? ($headers['authorization'][0] ?? '') : '';
        if (is_string($line)) {
            $auth = $line;
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($auth !== 'Authorization: Bearer ' . $this->token) {
            return $this->json(['status' => 'error', 'message' => 'Authentication credentials not found'], 401);
        }
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $body = is_string($options['body'] ?? null) && $options['body'] !== '' ? json_decode($options['body'], true, 32, JSON_THROW_ON_ERROR) : [];
        $body = is_array($body) ? $body : [];

        return match (true) {
            $path === '/account-info/v3/details'                                             => $this->json(['portalId' => (int) $this->account, 'timeZone' => 'UTC', 'companyCurrency' => 'EUR']),
            $path === '/oauth/v2/private-apps/get/access-token-info'                         => $this->introspect ? $this->json(['hubId' => (int) $this->account, 'scopes' => $this->scopes, 'userId' => 7]) : $this->json(['status' => 'error'], 400),
            preg_match('#\A/crm/v3/objects/(companies|contacts)/search\z#', $path, $m) === 1 => $this->scoped('crm.objects.' . $m[1] . '.read', fn (): MockResponse => $this->json(['total' => count($m[1] === 'companies' ? $this->companies : $this->contacts), 'results' => []])),
            preg_match('#\A/crm/v3/objects/(companies|contacts)\z#', $path, $m) === 1        => $this->scoped('crm.objects.' . $m[1] . '.read', fn (): MockResponse => $this->page($m[1] === 'companies' ? $this->companies : $this->contacts, $query)),
            preg_match('#\A/crm/v3/properties/(companies|contacts)\z#', $path, $m) === 1     => $this->scoped('crm.schemas.' . $m[1] . '.read', fn (): MockResponse => $this->json(['results' => $this->properties[$m[1]]])),
            $path === '/crm/v3/owners'                                                       => $this->scoped('crm.objects.owners.read', fn (): MockResponse => $this->owners($query)),
            $path === '/crm/v4/associations/contacts/companies/batch/read'                   => $this->scoped('crm.objects.contacts.read', fn (): MockResponse => $this->associationsOf($body)),
            $path === '/crm/v3/lists/search'                                                 => $this->scoped('crm.lists.read', fn (): MockResponse => $this->listsOf($body)),
            preg_match('#\A/crm/v3/lists/([0-9]+)/memberships\z#', $path, $m) === 1          => $this->scoped('crm.lists.read', fn (): MockResponse => $this->members($m[1], $query)),
            default                                                                          => $this->json(['status' => 'error'], 404),
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
