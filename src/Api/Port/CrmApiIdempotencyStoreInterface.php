<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Port;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Api\CrmApiRefusal;

/**
 * Where the API keeps its idempotency keys (0.3.0, design §8): ids and outcomes only, never a body. `claim()` and
 * `complete()` run inside the caller's write unit, so a rollback removes the claim.
 */
interface CrmApiIdempotencyStoreInterface
{
    /**
     * Claims the key as the first statement of the write unit: null when this call claimed it; else the stored row of
     * the other writer (committed: the claim waited on its key lock). An expired row of the same key is deleted first.
     *
     * @throws CrmApiRefusal `IDEMP_004` when the store fails (a transient lock failure is rethrown as is)
     */
    public function claim(CrmApiIdempotencyClaim $claim): ?CrmApiIdempotencyRecord;

    /**
     * The committed, unexpired row of a key, without locking (the replay check before the write unit).
     */
    public function find(string $scopeHash, string $keyHash, DateTimeImmutable $now): ?CrmApiIdempotencyRecord;

    /**
     * Stores the outcome of the claimed key, inside the same write unit.
     *
     * @param array<string, string> $resultIds ids only
     */
    public function complete(string $scopeHash, string $keyHash, int $status, array $resultIds): void;

    /**
     * Forgets every key of a token (a revoked token, an erased person); how many rows.
     */
    public function forget(string $channel, string $tokenId): int;

    /**
     * Deletes up to `$limit` expired rows; how many.
     */
    public function purgeExpired(DateTimeImmutable $now, int $limit): int;
}
