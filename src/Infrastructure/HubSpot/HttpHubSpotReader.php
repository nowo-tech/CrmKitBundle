<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\HubSpot;

use InvalidArgumentException;
use JsonException;
use Nowo\CrmKitBundle\Application\HubSpot\HubSpotException;
use Nowo\CrmKitBundle\Application\HubSpot\Port\HubSpotReaderInterface;
use Nowo\CrmKitBundle\Domain\Enum\HubSpotObject;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotAssociation;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotIdPage;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotList;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotOwner;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotOwnerPage;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotPage;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotProperty;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotRecord;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotTokenInfo;
use Nowo\CrmKitBundle\Domain\Value\EmailAddress;
use Nowo\CrmKitBundle\Domain\Value\HubSpotAccountId;
use Nowo\CrmKitBundle\Domain\Value\HubSpotCursor;
use Nowo\CrmKitBundle\Domain\Value\HubSpotKey;
use Nowo\CrmKitBundle\Domain\Value\HubSpotObjectId;
use Nowo\CrmKitBundle\Domain\Value\HubSpotToken;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function array_map;
use function array_slice;
use function count;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function json_decode;
use function mb_substr;
use function preg_match;
use function preg_replace;
use function rawurlencode;
use function strlen;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * {@see HubSpotReaderInterface} over HubSpot's CRM API (0.10.0, design section 9). Only the constant base URL
 * {@see self::BASE_URL} is called, with paths built from enums and validated ids (URL-encoded); `paging.next.link` is
 * ignored and only `paging.next.after` is kept, as a {@see HubSpotCursor}; redirects are errors. Bodies are read up to
 * `max_response_bytes` while streaming, decoded with a depth of 32 and mapped field by field into read models;
 * unknown fields are dropped and an unexpected shape is `hubspot_bad_response`. The token goes into the
 * `Authorization` header of one request only; failures carry a code and a status, never HubSpot's text. Stateless.
 *
 * @internal
 */
final readonly class HttpHubSpotReader implements HubSpotReaderInterface
{
    /** The only host the kit calls. */
    public const BASE_URL = 'https://api.hubapi.com';

    /** HubSpot's member kinds of lists (`objectTypeId`). */
    private const LIST_OBJECTS = ['0-1' => HubSpotObject::Contact, '0-2' => HubSpotObject::Company];

    /**
     * The smallest request of an endpoint that needs each read scope (design addendum 2026-11-23: service keys have no
     * scope-listing endpoint, so a 403 here means the scope is missing). Associations need no scope of their own (the
     * two objects' read scopes cover them).
     */
    public const PROBES = [
        'crm.objects.companies.read' => ['GET', '/crm/v3/objects/companies', ['limit' => '1', 'archived' => 'false']],
        'crm.objects.contacts.read'  => ['GET', '/crm/v3/objects/contacts', ['limit' => '1', 'archived' => 'false']],
        'crm.objects.owners.read'    => ['GET', '/crm/v3/owners', ['limit' => '1']],
        'crm.schemas.companies.read' => ['GET', '/crm/v3/properties/companies', []],
        'crm.schemas.contacts.read'  => ['GET', '/crm/v3/properties/contacts', []],
        'crm.lists.read'             => ['POST', '/crm/v3/lists/search', []],
    ];

    /** HubSpot's primary company association (contact ⇒ company, HUBSPOT_DEFINED). */
    private const PRIMARY_COMPANY = 1;

    /**
     * Wires the client.
     */
    public function __construct(
        private HttpClientInterface $client,
        private int $maxResponseBytes = 8388608,
        private ?HubSpotRequestOptionsInterface $options = null,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function accountId(HubSpotToken $token): HubSpotAccountId
    {
        $data = $this->call($token, 'GET', '/account-info/v3/details');

        return $this->parseAccount($data['portalId'] ?? null);
    }

    /**
     * {@inheritDoc}
     */
    public function probe(HubSpotToken $token, string $scope): bool
    {
        [$method, $path, $query] = self::PROBES[$scope] ?? throw new HubSpotException(HubSpotException::BAD_RESPONSE);

        try {
            $this->call($token, $method, $path, $query, $method === 'POST' ? ['count' => 1, 'offset' => 0] : null);
        } catch (HubSpotException $e) {
            if ($e->errorCode === HubSpotException::SCOPE_MISSING) {
                return false;
            }

            throw $e;
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function tokenInfo(HubSpotToken $token): HubSpotTokenInfo
    {
        try {
            $data = $this->call($token, 'POST', '/oauth/v2/private-apps/get/access-token-info', [], ['tokenKey' => $token->reveal()]);
        } catch (HubSpotException $e) {
            // HubSpot answers a credential it does not know here (a service key) with 400 / 404; nothing else means that.
            throw in_array($e->status, [400, 404], true) ? new HubSpotException(HubSpotException::UNKNOWN_TOKEN, $e->status) : $e;
        }
        $hub    = $data['hubId'] ?? null;
        $scopes = [];
        foreach (is_array($data['scopes'] ?? null) ? $data['scopes'] : [] as $scope) {
            // Fails closed (audit ZAM-3): an entry the kit cannot read, or one past the 200th, counts as a write scope.
            $scopes[] = is_string($scope) && preg_match('/\A[a-z0-9._-]{1,100}\z/D', $scope) === 1 && count($scopes) < 200 ? $scope : HubSpotTokenInfo::UNREADABLE;
        }

        return new HubSpotTokenInfo($this->parseAccount($hub), $scopes);
    }

    /**
     * {@inheritDoc}
     */
    public function count(HubSpotToken $token, HubSpotObject $object): int
    {
        $data  = $this->call($token, 'POST', '/crm/v3/objects/' . rawurlencode($object->value) . '/search', [], ['limit' => 1, 'properties' => ['hs_object_id']]);
        $total = $data['total'] ?? null;

        return is_int($total) && $total >= 0 ? $total : throw new HubSpotException(HubSpotException::BAD_RESPONSE, 200);
    }

    /**
     * {@inheritDoc}
     */
    public function properties(HubSpotToken $token, HubSpotObject $object): array
    {
        $data       = $this->call($token, 'GET', '/crm/v3/properties/' . rawurlencode($object->value));
        $properties = [];
        foreach ($this->results($data) as $row) {
            $name = $row['name'] ?? null;
            // Sensitive and highly sensitive properties are never offered: reading one needs a
            // `.sensitive.read` / `.highly_sensitive.read` scope (HubSpot answers 403 without it), which H1 never asks for.
            $sensitivity = $row['dataSensitivity'] ?? 'non_sensitive';
            if (is_string($name) && HubSpotKey::isValid($name) && $sensitivity === 'non_sensitive' && count($properties) < 1000) {
                $properties[] = new HubSpotProperty(new HubSpotKey($name), $this->text($row['label'] ?? null, 255) ?? $name, $this->text($row['type'] ?? null, 40) ?? 'string');
            }
        }

        return $properties;
    }

    /**
     * {@inheritDoc}
     */
    public function records(HubSpotToken $token, HubSpotObject $object, array $properties, ?HubSpotCursor $after, int $limit): HubSpotPage
    {
        $names = array_map(static fn (HubSpotKey $k): string => $k->value, $properties);
        $query = ['limit' => (string) $limit, 'archived' => 'false'];
        if ($names !== []) {
            $query['properties'] = implode(',', $names);
        }
        if ($after instanceof HubSpotCursor) {
            $query['after'] = $after->value;
        }
        $data    = $this->call($token, 'GET', '/crm/v3/objects/' . rawurlencode($object->value), $query);
        $records = [];
        foreach ($this->results($data) as $row) {
            $values = [];
            $raw    = is_array($row['properties'] ?? null) ? $row['properties'] : [];
            foreach ($names as $name) {
                $value         = $raw[$name] ?? null;
                $values[$name] = is_string($value) ? $value : (is_int($value) || is_float($value) ? (string) $value : (is_bool($value) ? ($value ? 'true' : 'false') : null));
            }
            $records[] = new HubSpotRecord($this->objectId($row['id'] ?? null), $values);
        }

        return new HubSpotPage($records, $this->next($data));
    }

    /**
     * {@inheritDoc}
     */
    public function associations(HubSpotToken $token, array $contacts): array
    {
        if ($contacts === []) {
            return [];
        }
        $data         = $this->call($token, 'POST', '/crm/v4/associations/contacts/companies/batch/read', [], ['inputs' => array_map(static fn (HubSpotObjectId $id): array => ['id' => $id->value], array_slice($contacts, 0, 100))]);
        $associations = [];
        foreach ($this->results($data) as $row) {
            $from    = is_array($row['from'] ?? null) ? $row['from'] : [];
            $contact = $this->objectId($from['id'] ?? null);
            foreach (is_array($row['to'] ?? null) ? $row['to'] : [] as $to) {
                if (!is_array($to)) {
                    throw new HubSpotException(HubSpotException::BAD_RESPONSE, 200);
                }
                $primary = false;
                foreach (is_array($to['associationTypes'] ?? null) ? $to['associationTypes'] : [] as $type) {
                    $primary = $primary || (is_array($type) && ($type['typeId'] ?? null) === self::PRIMARY_COMPANY && ($type['category'] ?? null) === 'HUBSPOT_DEFINED');
                }
                $associations[] = new HubSpotAssociation($contact, $this->objectId($to['toObjectId'] ?? null), $primary);
            }
        }

        return $associations;
    }

    /**
     * {@inheritDoc}
     */
    public function owners(HubSpotToken $token, ?HubSpotCursor $after, int $limit): HubSpotOwnerPage
    {
        $query = ['limit' => (string) $limit];
        if ($after instanceof HubSpotCursor) {
            $query['after'] = $after->value;
        }
        $data   = $this->call($token, 'GET', '/crm/v3/owners', $query);
        $owners = [];
        foreach ($this->results($data) as $row) {
            $email = $row['email'] ?? null;
            try {
                $address = is_string($email) ? new EmailAddress($email) : null;
            } catch (InvalidArgumentException) {
                $address = null;
            }
            $owners[] = new HubSpotOwner($this->objectId($row['id'] ?? null), $address);
        }

        return new HubSpotOwnerPage($owners, $this->next($data));
    }

    /**
     * {@inheritDoc}
     */
    public function lists(HubSpotToken $token, array $ids = []): array
    {
        $body = ['count' => 250, 'offset' => 0];
        if ($ids !== []) {
            $body['listIds'] = array_map(static fn (HubSpotObjectId $id): string => $id->value, $ids);
        }
        $data  = $this->call($token, 'POST', '/crm/v3/lists/search', [], $body);
        $lists = [];
        foreach (is_array($data['lists'] ?? null) ? $data['lists'] : throw new HubSpotException(HubSpotException::BAD_RESPONSE, 200) as $row) {
            if (!is_array($row)) {
                throw new HubSpotException(HubSpotException::BAD_RESPONSE, 200);
            }
            $type       = $row['objectTypeId'] ?? null;
            $additional = is_array($row['additionalProperties'] ?? null) ? $row['additionalProperties'] : [];
            $size       = $row['size'] ?? $additional['hs_list_size'] ?? 0;
            $lists[]    = new HubSpotList($this->objectId($row['listId'] ?? null), $this->text($row['name'] ?? null, 255) ?? '', is_string($type) ? (self::LIST_OBJECTS[$type] ?? null) : null, is_numeric($size) ? (int) $size : 0);
        }

        return $lists;
    }

    /**
     * {@inheritDoc}
     */
    public function members(HubSpotToken $token, HubSpotObjectId $list, ?HubSpotCursor $after, int $limit): HubSpotIdPage
    {
        $query = ['limit' => (string) $limit];
        if ($after instanceof HubSpotCursor) {
            $query['after'] = $after->value;
        }
        $data = $this->call($token, 'GET', '/crm/v3/lists/' . rawurlencode($list->value) . '/memberships', $query);
        $ids  = [];
        foreach ($this->results($data) as $row) {
            $ids[] = $this->objectId($row['recordId'] ?? null);
        }

        return new HubSpotIdPage($ids, $this->next($data));
    }

    /**
     * One request to the constant host: the decoded JSON object.
     *
     * @param array<string, string> $query
     * @param array<string, mixed>|null $json
     *
     * @throws HubSpotException
     *
     * @return array<array-key, mixed>
     */
    private function call(HubSpotToken $token, string $method, string $path, array $query = [], ?array $json = null): array
    {
        $url     = self::BASE_URL . $path;
        $options = ['headers' => ['Authorization' => 'Bearer ' . $token->reveal(), 'Accept' => 'application/json'], 'max_redirects' => 0, 'buffer' => false];
        if ($query !== []) {
            $options['query'] = $query;
        }
        if ($json !== null) {
            $options['json'] = $json;
        }
        $resolve = $this->options?->options($url)['resolve'] ?? null;
        if (is_array($resolve)) {
            $options['resolve'] = $resolve;
        }

        $status = 0;

        try {
            $response = $this->client->request($method, $url, $options);
            $status   = $response->getStatusCode();
            $body     = '';
            foreach ($this->client->stream($response) as $chunk) {
                $body .= $chunk->getContent();
                if (strlen($body) > $this->maxResponseBytes) {
                    $response->cancel();

                    throw new HubSpotException(HubSpotException::BAD_RESPONSE, $status);
                }
            }
            if ($status < 200 || $status >= 300) {
                $headers = $response->getHeaders(false);

                throw $this->refusal($status, $headers['retry-after'][0] ?? null, $body);
            }
            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (TransportExceptionInterface) {
            throw new HubSpotException(HubSpotException::UNAVAILABLE);
        } catch (JsonException) {
            throw new HubSpotException(HubSpotException::BAD_RESPONSE, $status);
        }

        return is_array($data) ? $data : throw new HubSpotException(HubSpotException::BAD_RESPONSE, $status);
    }

    /**
     * The failure of a non-2xx answer (its body is read only for the daily-limit policy name).
     */
    private function refusal(int $status, ?string $retryAfter, string $body): HubSpotException
    {
        return match (true) {
            $status === 401                 => new HubSpotException(HubSpotException::TOKEN_INVALID, $status),
            $status === 403                 => new HubSpotException(HubSpotException::SCOPE_MISSING, $status),
            $status === 429                 => new HubSpotException(HubSpotException::RATE_LIMITED, $status, $retryAfter !== null && preg_match('/\A[0-9]{1,5}\z/D', trim($retryAfter)) === 1 ? (int) trim($retryAfter) : null, preg_match('/"policyName"\s*:\s*"DAILY"/', $body) === 1),
            $status >= 500 && $status < 600 => new HubSpotException(HubSpotException::UNAVAILABLE, $status),
            default                         => new HubSpotException(HubSpotException::BAD_RESPONSE, $status),
        };
    }

    /**
     * The `results` array of objects.
     *
     * @param array<array-key, mixed> $data
     *
     * @throws HubSpotException
     *
     * @return list<array<array-key, mixed>>
     */
    private function results(array $data): array
    {
        $results = $data['results'] ?? null;
        if (!is_array($results)) {
            throw new HubSpotException(HubSpotException::BAD_RESPONSE, 200);
        }
        $rows = [];
        foreach ($results as $row) {
            $rows[] = is_array($row) ? $row : throw new HubSpotException(HubSpotException::BAD_RESPONSE, 200);
        }

        return $rows;
    }

    /**
     * The next page's `after` token (never the `link`).
     *
     * @param array<array-key, mixed> $data
     *
     * @throws HubSpotException
     */
    private function next(array $data): ?HubSpotCursor
    {
        $paging = is_array($data['paging'] ?? null) ? $data['paging'] : [];
        $next   = is_array($paging['next'] ?? null) ? $paging['next'] : [];
        $after  = $next['after'] ?? null;
        if ($after === null) {
            return null;
        }

        return is_string($after) && HubSpotCursor::isValid($after) ? new HubSpotCursor($after) : throw new HubSpotException(HubSpotException::BAD_RESPONSE, 200);
    }

    /**
     * A numeric HubSpot id given as a string or an integer.
     *
     * @throws HubSpotException
     */
    private function objectId(mixed $value): HubSpotObjectId
    {
        $id = is_int($value) ? (string) $value : $value;

        return is_string($id) && HubSpotObjectId::isValid($id) ? new HubSpotObjectId($id) : throw new HubSpotException(HubSpotException::BAD_RESPONSE, 200);
    }

    /**
     * An account id (token info `hubId`, account info `portalId`).
     *
     * @throws HubSpotException
     */
    private function parseAccount(mixed $value): HubSpotAccountId
    {
        $id = is_int($value) ? (string) $value : $value;

        return is_string($id) && HubSpotAccountId::isValid($id) ? new HubSpotAccountId($id) : throw new HubSpotException(HubSpotException::BAD_RESPONSE, 200);
    }

    /**
     * A label or a type: valid UTF-8, control and format characters removed, at most `$max` characters; null when
     * empty or not text.
     */
    private function text(mixed $value, int $max): ?string
    {
        if (!is_string($value) || preg_match('//u', $value) !== 1) {
            return null;
        }
        $clean = trim((string) preg_replace('/[\p{Cc}\p{Cf}]/u', '', $value));

        return $clean === '' ? null : mb_substr($clean, 0, $max);
    }
}
