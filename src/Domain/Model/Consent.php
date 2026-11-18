<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Model;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Enum\ConsentSource;

/**
 * One consent decision of a contact for a purpose (insert only; the latest per purpose is the current state).
 */
class Consent
{
    /**
     * @param string|null $evidence e.g. "web form 2026-11-18" (≤ 255)
     */
    public function __construct(
        private string $id,
        private string $tenantId,
        private string $contactId,
        private string $purpose,
        private bool $granted,
        private ConsentSource $source,
        private ?string $evidence,
        private ?string $recordedById,
        private DateTimeImmutable $recordedAt,
    ) {
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

    /** The contact. */
    public function getContactId(): string
    {
        return $this->contactId;
    }

    /** The purpose key. */
    public function getPurpose(): string
    {
        return $this->purpose;
    }

    /** Granted (true) or withdrawn. */
    public function isGranted(): bool
    {
        return $this->granted;
    }

    /** Where it came from. */
    public function getSource(): ConsentSource
    {
        return $this->source;
    }

    /** The evidence text. */
    public function getEvidence(): ?string
    {
        return $this->evidence;
    }

    /** Who recorded it. */
    public function getRecordedById(): ?string
    {
        return $this->recordedById;
    }

    /** When. */
    public function getRecordedAt(): DateTimeImmutable
    {
        return $this->recordedAt;
    }

    /** Points it at another contact (merge). */
    public function moveTo(string $contactId): void
    {
        $this->contactId = $contactId;
    }

    /** Removes the evidence text and the recorder (erasure keeps only the proof, contract section 16.3). */
    public function anonymise(): void
    {
        $this->evidence     = null;
        $this->recordedById = null;
    }
}
