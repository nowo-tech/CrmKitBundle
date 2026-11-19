<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Api\Controller;

use JsonException;
use JsonSerializable;
use Nowo\CrmKitBundle\Api\CrmApiPrincipal;
use Nowo\CrmKitBundle\Api\CrmApiRefusal;
use Nowo\CrmKitBundle\Api\Input\CrmApiAffiliationSpec;
use Nowo\CrmKitBundle\Api\Input\CrmApiCursor;
use Nowo\CrmKitBundle\Api\Input\CrmApiDealChanges;
use Nowo\CrmKitBundle\Api\Input\CrmApiDealDraft;
use Nowo\CrmKitBundle\Api\Input\CrmApiDealFields;
use Nowo\CrmKitBundle\Api\Input\CrmApiDealQuery;
use Nowo\CrmKitBundle\Api\Input\CrmApiFields;
use Nowo\CrmKitBundle\Api\Input\CrmApiFunnelStatsQuery;
use Nowo\CrmKitBundle\Api\Input\CrmApiNextActionDraft;
use Nowo\CrmKitBundle\Api\Input\CrmApiNoteDraft;
use Nowo\CrmKitBundle\Api\Input\CrmApiPageRequest;
use Nowo\CrmKitBundle\Api\Input\CrmApiRecordChanges;
use Nowo\CrmKitBundle\Api\Input\CrmApiRecordDraft;
use Nowo\CrmKitBundle\Api\Input\CrmApiSearchQuery;
use Nowo\CrmKitBundle\Api\Input\CrmApiSort;
use Nowo\CrmKitBundle\Api\Input\CrmApiStageFilter;
use Nowo\CrmKitBundle\Api\Input\CrmApiStageMove;
use Nowo\CrmKitBundle\Api\Input\CrmApiTagRef;
use Nowo\CrmKitBundle\Api\Input\IdempotencyKey;
use Nowo\CrmKitBundle\Api\Input\RecordVersion;
use Nowo\CrmKitBundle\Api\Operation\CrmApiDealOperations;
use Nowo\CrmKitBundle\Api\Operation\CrmApiNextActionOperations;
use Nowo\CrmKitBundle\Api\Operation\CrmApiReadOperations;
use Nowo\CrmKitBundle\Api\Operation\CrmApiRecordOperations;
use Nowo\CrmKitBundle\Api\Operation\CrmApiRelationOperations;
use Nowo\CrmKitBundle\Api\Read\CrmApiItem;
use Nowo\CrmKitBundle\Api\Read\CrmApiWritten;
use Nowo\CrmKitBundle\Domain\Enum\DealStatus;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\ExactId;
use Nowo\CrmKitBundle\Domain\Value\RecordId;
use Nowo\CrmKitBundle\Domain\Value\RoleKey;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Api\CrmApiAuthenticatorInterface;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Api\CrmApiExceptionListener;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Api\CrmApiTenantResolverInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

use function array_filter;
use function array_is_list;
use function array_map;
use function array_values;
use function explode;
use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function json_decode;
use function preg_match;

use const JSON_THROW_ON_ERROR;

/**
 * The `/crm/v1` JSON API (0.3.0, design §12): thin actions over the operations. Each reads the principal
 * ({@see CrmApiAuthenticatorInterface}), the tenant ({@see CrmApiTenantResolverInterface}), the path, the query, the
 * JSON body, `Idempotency-Key` and `If-Match`; answers JSON with `ETag` on single-record answers and
 * `Idempotency-Replayed: true` on replays. Refusals become JSON in {@see CrmApiExceptionListener}. Stateless.
 */
final readonly class CrmApiController
{
    /**
     * Wires the operations.
     */
    public function __construct(
        private CrmApiAuthenticatorInterface $authenticator,
        private CrmApiTenantResolverInterface $tenants,
        private CrmApiReadOperations $reads,
        private CrmApiRecordOperations $records,
        private CrmApiRelationOperations $relations,
        private CrmApiDealOperations $deals,
        private CrmApiNextActionOperations $nextActions,
        private int $pageSize = 25,
    ) {
    }

    /** `GET /me`. */
    public function identity(Request $request): JsonResponse
    {
        return self::ok($this->reads->identity($this->principal($request), $this->tenant($request)));
    }

    /** `GET /search`, `GET /companies`, `GET /contacts`. */
    public function search(Request $request): JsonResponse
    {
        $q      = $request->query;
        $fixed  = $request->attributes->get('types');
        $types  = is_string($fixed) ? [$fixed] : self::list($q->get('types'), 'types');
        $funnel = self::queryId($request, 'funnel');
        $stage  = self::queryId($request, 'stage');
        if (($funnel === null) !== ($stage === null)) {
            throw CrmApiRefusal::malformed($funnel === null ? 'funnel' : 'stage');
        }
        $sort  = CrmApiSort::tryFrom(self::str($request, 'sort') ?? 'name') ?? throw CrmApiRefusal::malformed('sort');
        $query = new CrmApiSearchQuery(
            $types === [] ? [SubjectType::Company, SubjectType::Contact] : array_map(static fn (string $t): SubjectType => in_array($t, ['company', 'contact'], true) ? SubjectType::from($t) : throw CrmApiRefusal::malformed('types'), $types),
            self::str($request, 'q'),
            self::role($request),
            self::queryId($request, 'tag'),
            self::actor($request, 'owner'),
            $funnel === null || $stage === null ? null : new CrmApiStageFilter($funnel, $stage),
            $sort,
            $this->page($request),
            self::str($request, 'segment'),
        );

        return self::ok($this->reads->search($this->principal($request), $this->tenant($request), $query));
    }

    /** `GET /companies/{id}`, `GET /contacts/{id}`. */
    public function record(Request $request): JsonResponse
    {
        $item = self::type($request) === SubjectType::Company
            ? $this->reads->company($this->principal($request), $this->tenant($request), self::id($request))
            : $this->reads->contact($this->principal($request), $this->tenant($request), self::id($request));

        return self::ok($item, 200, $item->fact('version'));
    }

    /** `POST /companies`, `POST /contacts`. */
    public function create(Request $request): JsonResponse
    {
        $type  = self::type($request);
        $draft = CrmApiRecordDraft::fromArray($type, self::body($request));
        $p     = $this->principal($request);
        $t     = $this->tenant($request);
        $key   = self::key($request);

        return self::written($type === SubjectType::Company ? $this->records->createCompany($p, $t, $key, $draft) : $this->records->createContact($p, $t, $key, $draft));
    }

    /** `PATCH /companies/{id}`, `PATCH /contacts/{id}`. */
    public function update(Request $request): JsonResponse
    {
        $type    = self::type($request);
        $id      = self::id($request);
        $changes = CrmApiRecordChanges::fromArray($type, self::body($request));
        $p       = $this->principal($request);
        $t       = $this->tenant($request);
        $key     = self::key($request);
        $base    = RecordVersion::required(self::ifMatch($request));

        return self::written($type === SubjectType::Company ? $this->records->updateCompany($p, $t, $key, $id, $base, $changes) : $this->records->updateContact($p, $t, $key, $id, $base, $changes));
    }

    /** `DELETE /companies/{id}`, `DELETE /contacts/{id}`. */
    public function trash(Request $request): JsonResponse
    {
        return self::written($this->records->trash($this->principal($request), $this->tenant($request), self::key($request), self::type($request), self::id($request), self::ifMatch($request)));
    }

    /** `GET /companies/{id}/contacts`, `GET /contacts/{id}/companies`. */
    public function affiliations(Request $request): JsonResponse
    {
        return self::ok($this->reads->affiliations($this->principal($request), $this->tenant($request), self::type($request), self::id($request), $this->page($request)));
    }

    /** `PUT /contacts/{contactId}/companies/{companyId}`. */
    public function setAffiliation(Request $request): JsonResponse
    {
        $spec = CrmApiAffiliationSpec::fromArray(self::body($request), self::id($request, 'contactId'), self::id($request, 'companyId'));

        return self::written($this->relations->setAffiliation($this->principal($request), $this->tenant($request), self::key($request), $spec));
    }

    /** `DELETE /contacts/{contactId}/companies/{companyId}`. */
    public function removeAffiliation(Request $request): JsonResponse
    {
        return self::written($this->relations->removeAffiliation($this->principal($request), $this->tenant($request), self::key($request), self::id($request, 'contactId'), self::id($request, 'companyId')));
    }

    /** `GET /{companies|contacts|deals}/{id}/timeline`. */
    public function timeline(Request $request): JsonResponse
    {
        return self::ok($this->reads->timeline($this->principal($request), $this->tenant($request), self::type($request), self::id($request), $this->page($request)));
    }

    /** `GET /{companies|contacts}/{id}/notes`. */
    public function notes(Request $request): JsonResponse
    {
        return self::ok($this->reads->notes($this->principal($request), $this->tenant($request), self::type($request), self::id($request), $this->page($request)));
    }

    /** `POST /{companies|contacts}/{id}/notes`. */
    public function addNote(Request $request): JsonResponse
    {
        $note = CrmApiNoteDraft::fromArray(self::body($request));

        return self::written($this->relations->addNote($this->principal($request), $this->tenant($request), self::key($request), self::type($request), self::id($request), $note));
    }

    /** `PUT` / `DELETE /{companies|contacts}/{id}/tags/{tagId}`. */
    public function tag(Request $request): JsonResponse
    {
        $tag = CrmApiTagRef::byId(self::id($request, 'tagId'));
        $p   = $this->principal($request);
        $t   = $this->tenant($request);

        return self::written($request->getMethod() === 'PUT'
            ? $this->relations->addTag($p, $t, self::key($request), self::type($request), self::id($request), $tag)
            : $this->relations->removeTag($p, $t, self::key($request), self::type($request), self::id($request), $tag));
    }

    /** `GET /tags`. */
    public function tags(Request $request): JsonResponse
    {
        return self::ok($this->reads->tags($this->principal($request), $this->tenant($request)));
    }

    /** `GET /{companies|contacts}/{id}/links`. */
    public function links(Request $request): JsonResponse
    {
        return self::ok($this->reads->links($this->principal($request), $this->tenant($request), self::type($request), self::id($request)));
    }

    /** `GET /contacts/{id}/consents?history=`. */
    public function consents(Request $request): JsonResponse
    {
        $history = self::str($request, 'history');
        if (!in_array($history, [null, 'true', 'false', '1', '0'], true)) {
            throw CrmApiRefusal::malformed('history');
        }

        return self::ok($this->reads->consents($this->principal($request), $this->tenant($request), self::id($request), in_array($history, ['true', '1'], true)));
    }

    /** `POST /{companies|contacts|deals}/{id}/next-actions`. */
    public function nextAction(Request $request): JsonResponse
    {
        $draft = CrmApiNextActionDraft::fromArray(self::type($request), self::id($request), self::body($request));

        return self::written($this->nextActions->set($this->principal($request), $this->tenant($request), self::key($request), $draft));
    }

    /** `GET /funnels`. */
    public function funnels(Request $request): JsonResponse
    {
        return self::ok($this->deals->funnels($this->principal($request), $this->tenant($request)));
    }

    /** `GET /funnels/{id}/stats?at=&owner=&role=&tag=`. */
    public function stats(Request $request): JsonResponse
    {
        $at    = self::str($request, 'at');
        $query = new CrmApiFunnelStatsQuery(self::id($request), $at === null ? null : CrmApiDealFields::instant(['at' => $at], 'at'), self::actor($request, 'owner'), self::role($request), self::queryId($request, 'tag'));

        return self::ok($this->deals->stats($this->principal($request), $this->tenant($request), $query));
    }

    /** `GET /deals?funnel=&stage=&status=&owner=&company=&contact=&q=&stale=&sort=&limit=&cursor=`. */
    public function dealSearch(Request $request): JsonResponse
    {
        $stages = self::list($request->query->get('stage'), 'stage');
        $status = self::str($request, 'status');
        $stale  = self::str($request, 'stale');
        $query  = new CrmApiDealQuery(
            self::queryId($request, 'funnel'),
            $stages === [] ? null : array_map(static fn (string $s): RecordId => RecordId::isValid($s) ? new RecordId($s) : throw CrmApiRefusal::malformed('stage'), $stages),
            $status === null ? null : (DealStatus::tryFrom($status) ?? throw CrmApiRefusal::malformed('status')),
            self::actor($request, 'owner'),
            self::queryId($request, 'company'),
            self::queryId($request, 'contact'),
            self::str($request, 'q'),
            in_array($stale, ['true', '1'], true),
            self::str($request, 'sort') ?? 'updated',
            $this->page($request),
        );

        return self::ok($this->deals->search($this->principal($request), $this->tenant($request), $query));
    }

    /** `GET /deals/{id}`. */
    public function deal(Request $request): JsonResponse
    {
        $item = $this->deals->deal($this->principal($request), $this->tenant($request), self::id($request));

        return self::ok($item, 200, $item->fact('version'));
    }

    /** `POST /deals`. */
    public function dealCreate(Request $request): JsonResponse
    {
        $draft = CrmApiDealDraft::fromArray(self::body($request));

        return self::written($this->deals->create($this->principal($request), $this->tenant($request), self::key($request), $draft));
    }

    /** `PATCH /deals/{id}`. */
    public function dealUpdate(Request $request): JsonResponse
    {
        $id      = self::id($request);
        $changes = CrmApiDealChanges::fromArray(self::body($request));
        $p       = $this->principal($request);
        $t       = $this->tenant($request);
        $key     = self::key($request);

        return self::written($this->deals->update($p, $t, $key, $id, RecordVersion::required(self::ifMatch($request)), $changes));
    }

    /** `POST /deals/{id}/stage`. */
    public function dealMove(Request $request): JsonResponse
    {
        $id   = self::id($request);
        $move = CrmApiStageMove::fromArray(self::body($request));

        return self::written($this->deals->moveStage($this->principal($request), $this->tenant($request), self::key($request), $id, $move, self::ifMatch($request)));
    }

    /** `DELETE /deals/{id}`. */
    public function dealTrash(Request $request): JsonResponse
    {
        return self::written($this->deals->trash($this->principal($request), $this->tenant($request), self::key($request), self::id($request), self::ifMatch($request)));
    }

    private function principal(Request $request): CrmApiPrincipal
    {
        return $this->authenticator->authenticate($request) ?? throw CrmApiRefusal::notFound();
    }

    private function tenant(Request $request): TenantRef
    {
        return $this->tenants->tenant($request) ?? throw CrmApiRefusal::notFound();
    }

    private function page(Request $request): CrmApiPageRequest
    {
        $limit  = self::str($request, 'limit');
        $cursor = self::str($request, 'cursor');
        if ($limit !== null && preg_match('/\A[1-9][0-9]{0,2}\z/', $limit) !== 1) {
            throw CrmApiRefusal::malformed('limit');
        }

        return new CrmApiPageRequest($cursor === null ? null : new CrmApiCursor($cursor), $limit === null ? $this->pageSize : (int) $limit);
    }

    private static function type(Request $request): SubjectType
    {
        $type = $request->attributes->get('type');

        return is_string($type) ? SubjectType::from($type) : SubjectType::Company;
    }

    private static function id(Request $request, string $name = 'id'): RecordId
    {
        $id = $request->attributes->get($name);

        return is_string($id) && RecordId::isValid($id) ? new RecordId($id) : throw CrmApiRefusal::notFound();
    }

    private static function str(Request $request, string $name): ?string
    {
        $value = $request->query->all()[$name] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        return is_string($value) ? $value : throw CrmApiRefusal::malformed($name);
    }

    private static function queryId(Request $request, string $name): ?RecordId
    {
        return CrmApiFields::id([$name => self::str($request, $name)], $name);
    }

    private static function actor(Request $request, string $name): ?ActorRef
    {
        $value = self::str($request, $name);

        return $value === null ? null : (ExactId::isValid($value) && !ActorRef::isReserved($value) ? new ActorRef($value) : throw CrmApiRefusal::malformed($name));
    }

    private static function role(Request $request): ?RoleKey
    {
        $role = self::str($request, 'role');

        return $role === null ? null : (RoleKey::isValid($role) ? new RoleKey($role) : throw CrmApiRefusal::malformed('role'));
    }

    /**
     * A comma-separated query list.
     *
     * @return list<string>
     */
    private static function list(mixed $value, string $name): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        return is_string($value) ? array_values(array_filter(explode(',', $value), static fn (string $v): bool => $v !== '')) : throw CrmApiRefusal::malformed($name);
    }

    private static function key(Request $request): IdempotencyKey
    {
        return IdempotencyKey::from($request->headers->get('Idempotency-Key'));
    }

    private static function ifMatch(Request $request): ?RecordVersion
    {
        return RecordVersion::fromIfMatch($request->headers->get('If-Match'));
    }

    /**
     * The JSON object of the body (an empty body is `{}`).
     *
     * @return array<string, mixed>
     */
    private static function body(Request $request): array
    {
        $content = $request->getContent();
        if ($content === '') {
            return [];
        }
        try {
            $data = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw CrmApiRefusal::malformed('body');
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw CrmApiRefusal::malformed('body');
        }
        $body = [];
        foreach ($data as $key => $value) {
            $body[(string) $key] = $value;
        }

        return $body;
    }

    private static function ok(JsonSerializable $data, int $status = 200, mixed $version = null): JsonResponse
    {
        $response = new JsonResponse($data, $status, ['Cache-Control' => 'no-store, private']);
        if (is_numeric($version)) {
            $response->setEtag((string) $version);
        }

        return $response;
    }

    private static function written(CrmApiWritten $written): JsonResponse
    {
        $result   = $written->result;
        $record   = $result instanceof CrmApiItem ? ($result->kind === 'created' ? $result->fact('record') : $result) : null;
        $response = self::ok($written, $written->status, $record instanceof CrmApiItem ? $record->fact('version') : null);
        if ($written->replayed) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }
}
