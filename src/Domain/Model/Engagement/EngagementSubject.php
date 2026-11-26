<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Model\Engagement;

use Nowo\CrmKitBundle\Domain\Enum\SubjectType;

/**
 * A record a logged activity shows on (0.11.0): its primary record (`primary`) and the records it was associated with
 * when it was logged (a contact's companies and open deals, a company's open deals). One row per activity and record.
 */
class EngagementSubject
{
    /**
     * @param SubjectType $subjectType company, contact or deal
     */
    public function __construct(
        private string $id,
        private string $tenantId,
        private string $engagementId,
        private SubjectType $subjectType,
        private string $subjectId,
        private bool $primary,
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

    /** The activity. */
    public function getEngagementId(): string
    {
        return $this->engagementId;
    }

    /** The record's type. */
    public function getSubjectType(): SubjectType
    {
        return $this->subjectType;
    }

    /** The record. */
    public function getSubjectId(): string
    {
        return $this->subjectId;
    }

    /** Whether it is the activity's primary record. */
    public function isPrimary(): bool
    {
        return $this->primary;
    }

    /** Points it at another record (merge). */
    public function moveTo(string $subjectId): void
    {
        $this->subjectId = $subjectId;
    }
}
