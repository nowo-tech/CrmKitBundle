<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Model\Engagement;

/**
 * Who takes part in a logged meeting (0.11.0): a member of the tenant (`actorId`) or a contact (`contactId`), one of
 * the two. Members see the meeting on TaskBoard's calendar.
 */
class EngagementAttendee
{
    /**
     * Wires the row.
     */
    public function __construct(
        private string $id,
        private string $tenantId,
        private string $engagementId,
        private ?string $actorId,
        private ?string $contactId,
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

    /** The meeting. */
    public function getEngagementId(): string
    {
        return $this->engagementId;
    }

    /** A member. */
    public function getActorId(): ?string
    {
        return $this->actorId;
    }

    /** A contact. */
    public function getContactId(): ?string
    {
        return $this->contactId;
    }

    /** Points a contact attendee at another contact (merge). */
    public function moveTo(string $contactId): void
    {
        $this->contactId = $contactId;
    }
}
