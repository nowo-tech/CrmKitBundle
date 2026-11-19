<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Operation;

use DateTimeZone;
use Nowo\CrmKitBundle\Api\CrmApiCode;
use Nowo\CrmKitBundle\Api\CrmApiOperation;
use Nowo\CrmKitBundle\Api\CrmApiPrincipal;
use Nowo\CrmKitBundle\Api\CrmApiRefusal;
use Nowo\CrmKitBundle\Api\Input\IdempotencyKey;
use Nowo\CrmKitBundle\Api\Port\CrmApiIdempotencyClaim;
use Nowo\CrmKitBundle\Api\Port\CrmApiIdempotencyRecord;
use Nowo\CrmKitBundle\Api\Port\CrmApiIdempotencyStoreInterface;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Psr\Clock\ClockInterface;

use function array_is_list;
use function hash;
use function is_array;
use function json_encode;
use function ksort;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Exactly-once writes of the API (0.3.0, design §8): a completed key is answered again before the write unit
 * ({@see self::stored()}); otherwise the claim is the first statement of the write unit and the outcome its last
 * ({@see self::hooks()}), so a rollback removes the claim and a concurrent call with the same key waits on it, then
 * replays. Keys are scoped per principal (channel + token) and per tenant; the fingerprint is the operation and the
 * canonical input. Stateless.
 */
final readonly class CrmApiIdempotency
{
    /**
     * @param int $ttlSeconds how long a key is kept (`api.idempotency_ttl_seconds`)
     */
    public function __construct(private CrmApiIdempotencyStoreInterface $store, private ClockInterface $clock, private int $ttlSeconds = 86400)
    {
    }

    /**
     * The scope of a principal: `sha256("nowo-crm-kit|" . channel . "|" . tokenId)`.
     */
    public static function scope(string $channel, string $tokenId): string
    {
        return hash('sha256', 'nowo-crm-kit|' . $channel . '|' . $tokenId);
    }

    /**
     * The fingerprint of an input: `sha256(operation . "\n" . canonical JSON)` (keys sorted, lists in order).
     *
     * @param array<string, mixed> $canonical
     */
    public static function fingerprint(CrmApiOperation $operation, array $canonical): string
    {
        return hash('sha256', $operation->value . "\n" . json_encode(self::sorted($canonical), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * The stored answer of a completed key with the same input (a replay), or null; another input is 409 `IDEMP_002`.
     *
     * @param array<string, mixed> $canonical
     */
    public function stored(CrmApiPrincipal $principal, TenantRef $tenant, CrmApiOperation $operation, IdempotencyKey $key, array $canonical): ?CrmApiReplay
    {
        $record = $this->store->find(self::scope($principal->channel, $principal->tokenId), $this->key($tenant, $key), $this->clock->now()->setTimezone(new DateTimeZone('UTC')));

        return $record instanceof CrmApiIdempotencyRecord ? $this->answer($record, $operation, self::fingerprint($operation, $canonical)) : null;
    }

    /**
     * The hooks of the write unit: claim first (a committed row of the same key ⇒ {@see CrmApiReplay} or `IDEMP_002`),
     * the outcome last.
     *
     * @param array<string, mixed> $canonical
     * @param callable(mixed): array<string, string> $ids the result ids of the unit's result
     */
    public function hooks(CrmApiPrincipal $principal, TenantRef $tenant, CrmApiOperation $operation, IdempotencyKey $key, array $canonical, int $status, callable $ids): CrmApiUnitHooks
    {
        $scope       = self::scope($principal->channel, $principal->tokenId);
        $keyHash     = $this->key($tenant, $key);
        $fingerprint = self::fingerprint($operation, $canonical);

        return new CrmApiUnitHooks(
            function () use ($scope, $keyHash, $tenant, $operation, $fingerprint): void { // @igor-ignore - per-call closure, never kept on the service
                $now    = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
                $record = $this->store->claim(new CrmApiIdempotencyClaim($scope, $keyHash, $tenant->value, $operation->value, $fingerprint, $now, $now->modify('+' . $this->ttlSeconds . ' seconds')));
                if ($record instanceof CrmApiIdempotencyRecord) {
                    throw $this->answer($record, $operation, $fingerprint);
                }
            },
            function (mixed $result) use ($scope, $keyHash, $status, $ids): void { // @igor-ignore - per-call closure, never kept on the service
                $this->store->complete($scope, $keyHash, $status, $ids($result));
            },
        );
    }

    /**
     * The key hash: `sha256(tenant . "\n" . key)`.
     */
    private function key(TenantRef $tenant, IdempotencyKey $key): string
    {
        return hash('sha256', $tenant->value . "\n" . $key->value);
    }

    private function answer(CrmApiIdempotencyRecord $record, CrmApiOperation $operation, string $fingerprint): CrmApiReplay
    {
        if ($record->operation !== $operation->value || $record->fingerprint !== $fingerprint || $record->status === null) {
            throw CrmApiRefusal::of(CrmApiCode::IDEMP_MISMATCH);
        }

        return new CrmApiReplay($record->status, $record->resultIds);
    }

    /**
     * Sorts the keys of every object, recursively; lists keep their order.
     */
    private static function sorted(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $k => $v) {
            $value[$k] = self::sorted($v);
        }

        return $value;
    }
}
