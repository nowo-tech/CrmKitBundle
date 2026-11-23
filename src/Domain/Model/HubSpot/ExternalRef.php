<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Model\HubSpot;

use DateTimeImmutable;

/**
 * The HubSpot object behind a CRM row (0.10.0, table `crm_external_ref`): unique per tenant, source, account, object
 * kind and HubSpot id, so one HubSpot object is one CRM record (a re-run updates it). Moved by a merge, deleted with
 * its target on purge; after a GDPR erasure kept as a tombstone (`erased`, no personal data), so a re-run never
 * brings the person back (Q-H6).
 */
class ExternalRef
{
    /** The only source of 0.10.0. */
    public const HUBSPOT = 'hubspot';

    private bool $erased = false;

    private DateTimeImmutable $updatedAt;

    /**
     * @param string $objectType `company`, `contact` or `list`
     * @param string $targetType `company`, `contact` or `tag`
     */
    public function __construct(
        private string $id,
        private string $tenantId,
        private string $accountId,
        private string $objectType,
        private string $externalId,
        private string $targetType,
        private string $targetId,
        private DateTimeImmutable $createdAt,
        private string $source = self::HUBSPOT,
    ) {
        $this->updatedAt = $createdAt;
    }

    /**
     * Points the reference at another row (a merged record's survivor).
     */
    public function retarget(string $targetId, DateTimeImmutable $now): void
    {
        $this->targetId  = $targetId;
        $this->updatedAt = $now;
    }

    /** The row id. */
    public function getId(): string
    {
        return $this->id;
    }

    /** The tenant. */
    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    /** `hubspot`. */
    public function getSource(): string
    {
        return $this->source;
    }

    /** The HubSpot account. */
    public function getAccountId(): string
    {
        return $this->accountId;
    }

    /** `company`, `contact` or `list`. */
    public function getObjectType(): string
    {
        return $this->objectType;
    }

    /** The HubSpot id. */
    public function getExternalId(): string
    {
        return $this->externalId;
    }

    /** `company`, `contact` or `tag`. */
    public function getTargetType(): string
    {
        return $this->targetType;
    }

    /** The CRM row. */
    public function getTargetId(): string
    {
        return $this->targetId;
    }

    /** Whether the person was erased (a tombstone: skipped on every re-run). */
    public function isErased(): bool
    {
        return $this->erased;
    }

    /** When it was created. */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** When it last changed. */
    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
