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
        private string $scopeHash,
        private string $keyHash,
        private string $tenantId,
        private string $operation,
        private string $fingerprint,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $expiresAt,
        private ?int $outcome = null,
        private ?array $resultIds = null,
    ) {
    }

    /** The scope hash. */
    public function getScopeHash(): string
    {
        return $this->scopeHash;
    }

    /** The key hash. */
    public function getKeyHash(): string
    {
        return $this->keyHash;
    }

    /** The tenant. */
    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    /** The operation. */
    public function getOperation(): string
    {
        return $this->operation;
    }

    /** The fingerprint. */
    public function getFingerprint(): string
    {
        return $this->fingerprint;
    }

    /** When it was claimed. */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** When it expires. */
    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /** The HTTP status of the first answer. */
    public function getOutcome(): ?int
    {
        return $this->outcome;
    }

    /**
     * The ids of the result.
     *
     * @return array<string, string>|null
     */
    public function getResultIds(): ?array
    {
        return $this->resultIds;
    }
}
