<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Model\HubSpot;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Enum\HubSpotConnectionStatus;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotConnectionView;
use Nowo\CrmKitBundle\Domain\Value\HubSpotAccountId;
use Nowo\CrmKitBundle\Domain\Value\SealedSecret;

/**
 * A tenant's HubSpot connection (0.10.0, table `crm_hubspot_connection`, unique per tenant): the sealed private app
 * token with the id of the key that sealed it, its last four characters, the account and the scopes HubSpot
 * reported. Replaced under its version; a disconnect deletes the row.
 */
class HubSpotConnection
{
    private string $kind = HubSpotConnectionView::PRIVATE_APP;

    private HubSpotConnectionStatus $status = HubSpotConnectionStatus::Active;

    private string $accountId;

    private string $tokenKeyId;

    private string $tokenSealed;

    private ?string $updatedById;

    private DateTimeImmutable $updatedAt;

    private int $version = 1;

    /**
     * @param list<string> $scopes
     */
    public function __construct(
        private string $id,
        private string $tenantId,
        HubSpotAccountId $accountId,
        private string $label,
        private string $tokenLast4,
        SealedSecret $token,
        private array $scopes,
        private DateTimeImmutable $validatedAt,
        private ?string $createdById,
        private DateTimeImmutable $createdAt,
    ) {
        $this->accountId   = $accountId->value;
        $this->tokenKeyId  = $token->keyId;
        $this->tokenSealed = $token->ciphertext;
        $this->updatedById = $createdById;
        $this->updatedAt   = $createdAt;
    }

    /**
     * Replaces the token (validated again), its account, label and scopes; the connection is active again.
     *
     * @param list<string> $scopes
     */
    public function replace(HubSpotAccountId $accountId, string $label, string $tokenLast4, SealedSecret $token, array $scopes, DateTimeImmutable $now, ?string $actor): void
    {
        $this->accountId   = $accountId->value;
        $this->label       = $label;
        $this->tokenLast4  = $tokenLast4;
        $this->tokenKeyId  = $token->keyId;
        $this->tokenSealed = $token->ciphertext;
        $this->scopes      = $scopes;
        $this->status      = HubSpotConnectionStatus::Active;
        $this->validatedAt = $now;
        $this->updatedById = $actor;
        $this->updatedAt   = $now;
    }

    /**
     * Re-seals the token under another key (rotation; the plaintext is the same).
     */
    public function reseal(SealedSecret $token, DateTimeImmutable $now): void
    {
        $this->tokenKeyId  = $token->keyId;
        $this->tokenSealed = $token->ciphertext;
        $this->updatedAt   = $now;
    }

    /**
     * Marks the connection invalid (HubSpot refused the token, or it no longer opens).
     */
    public function invalidate(DateTimeImmutable $now): void
    {
        $this->status    = HubSpotConnectionStatus::Invalid;
        $this->updatedAt = $now;
    }

    /** The row id (the secret's context). */
    public function getId(): string
    {
        return $this->id;
    }

    /** The tenant. */
    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    /** `active` or `invalid`. */
    public function getStatus(): HubSpotConnectionStatus
    {
        return $this->status;
    }

    /** Whether imports may use it. */
    public function isActive(): bool
    {
        return $this->status === HubSpotConnectionStatus::Active;
    }

    /** The HubSpot account. */
    public function getAccountId(): HubSpotAccountId
    {
        return new HubSpotAccountId($this->accountId);
    }

    /** The label shown. */
    public function getLabel(): string
    {
        return $this->label;
    }

    /** The sealed token. */
    public function getToken(): SealedSecret
    {
        return new SealedSecret($this->tokenKeyId, $this->tokenSealed);
    }

    /**
     * The scopes HubSpot reported.
     *
     * @return list<string>
     */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    /** The optimistic version. */
    public function getVersion(): int
    {
        return $this->version;
    }

    /** Who connected it. */
    public function getCreatedById(): ?string
    {
        return $this->createdById;
    }

    /** When it was connected first. */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** When it last changed. */
    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** Who changed it last. */
    public function getUpdatedById(): ?string
    {
        return $this->updatedById;
    }

    /**
     * The connection as shown (no secret).
     */
    public function view(): HubSpotConnectionView
    {
        return new HubSpotConnectionView($this->id, $this->kind, $this->status, $this->accountId, $this->label, $this->tokenLast4, $this->scopes, $this->validatedAt, $this->version);
    }
}
