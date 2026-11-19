<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Model\Api;

use DateTimeImmutable;

/**
 * A stored idempotency key of the API (0.3.0; mapped only with `api.enabled`): ids and outcome only, never a body.
 * Written and read through DBAL by the store; the mapping exists for the schema.
 *
 * @internal
 */
class ApiIdempotency
{
    /**
     * @param array<string, string>|null $resultIds
     */
    public function __construct(
        public string $scopeHash,
        public string $keyHash,
        public string $tenantId,
        public string $operation,
        public string $fingerprint,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $expiresAt,
        public ?int $outcome = null,
        public ?array $resultIds = null,
    ) {
    }
}
