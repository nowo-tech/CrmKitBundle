<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Port;

use DateTimeImmutable;

/**
 * A claim of an idempotency key (0.3.0): scope (channel + token), key (tenant + key), operation, fingerprint of the
 * input and the instants.
 */
final readonly class CrmApiIdempotencyClaim
{
    /**
     * Wires the claim.
     */
    public function __construct(
        public string $scopeHash,
        public string $keyHash,
        public string $tenant,
        public string $operation,
        public string $fingerprint,
        public DateTimeImmutable $now,
        public DateTimeImmutable $expiresAt,
    ) {
    }
}
