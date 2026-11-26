<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Model\Engagement;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Enum\ActivityDirection;
use Nowo\CrmKitBundle\Domain\Enum\ActivityKind;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;

/**
 * A logged activity (0.11.0, `activities.enabled`): a call, a meeting or an e-mail a member logged on a company or a
 * contact (its primary record). It also shows on the records it was associated with when it was logged
 * ({@see EngagementSubject}: a contact's companies and open deals). Plain text only, shown escaped. Versioned
 * (optimistic): an edit from a stale form is refused. A meeting carries an ICS sequence that moves whenever its time,
 * place, title or cancellation changes.
 */
class Engagement
{
    private ?string $title = null;

    private ?string $body = null;

    private ?ActivityDirection $direction = null;

    private ?string $outcome = null;

    private ?int $durationMinutes = null;

    private ?DateTimeImmutable $endsAt = null;

    private ?string $timezone = null;

    private ?string $place = null;

    private bool $cancelled = false;

    private int $sequence = 0;

    private int $version = 1;

    private DateTimeImmutable $updatedAt;

    /**
     * @param ActivityKind $kind `call`, `meeting` or `email`
     * @param SubjectType $subjectType the primary record's type (company or contact)
     * @param DateTimeImmutable $occurredAt when the call or e-mail happened, when the meeting starts (UTC)
     */
    public function __construct(
        private string $id,
        private string $tenantId,
        private ActivityKind $kind,
        private SubjectType $subjectType,
        private string $subjectId,
        private DateTimeImmutable $occurredAt,
        private ?string $authorId,
        private DateTimeImmutable $createdAt,
    ) {
        $this->updatedAt = $createdAt;
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

    /** `call`, `meeting` or `email`. */
    public function getKind(): ActivityKind
    {
        return $this->kind;
    }

    /** The primary record's type. */
    public function getSubjectType(): SubjectType
    {
        return $this->subjectType;
    }

    /** The primary record. */
    public function getSubjectId(): string
    {
        return $this->subjectId;
    }

    /** When it happened (a meeting: its start). */
    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    /** A meeting's end. */
    public function getEndsAt(): ?DateTimeImmutable
    {
        return $this->endsAt;
    }

    /** A meeting's IANA time zone. */
    public function getTimezone(): ?string
    {
        return $this->timezone;
    }

    /** The title: a meeting's name, an e-mail's subject, a call's summary. */
    public function getTitle(): ?string
    {
        return $this->title;
    }

    /** The notes of the call or meeting, or the e-mail's excerpt (plain text). */
    public function getBody(): ?string
    {
        return $this->body;
    }

    /** A call's or e-mail's direction. */
    public function getDirection(): ?ActivityDirection
    {
        return $this->direction;
    }

    /** A call's outcome key. */
    public function getOutcome(): ?string
    {
        return $this->outcome;
    }

    /** A call's duration in minutes. */
    public function getDurationMinutes(): ?int
    {
        return $this->durationMinutes;
    }

    /** A meeting's place. */
    public function getPlace(): ?string
    {
        return $this->place;
    }

    /** Whether a meeting was cancelled. */
    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    /** The ICS sequence of a meeting. */
    public function getSequence(): int
    {
        return $this->sequence;
    }

    /** Who logged it. */
    public function getAuthorId(): ?string
    {
        return $this->authorId;
    }

    /** The optimistic version. */
    public function getVersion(): int
    {
        return $this->version;
    }

    /** When it was logged. */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** When it last changed. */
    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Sets the content (validated by the service). A meeting's sequence moves when its time, end, zone, title, place
     * or cancellation changes after it was first written.
     */
    public function describe(
        DateTimeImmutable $occurredAt,
        ?string $title,
        ?string $body,
        ?ActivityDirection $direction,
        ?string $outcome,
        ?int $durationMinutes,
        ?DateTimeImmutable $endsAt,
        ?string $timezone,
        ?string $place,
        bool $cancelled,
        DateTimeImmutable $now,
        bool $initial = false,
    ): void {
        $moved = $this->occurredAt != $occurredAt || $this->endsAt != $endsAt || $this->timezone !== $timezone || $this->title !== $title || $this->place !== $place || $this->cancelled !== $cancelled;
        if (!$initial && $moved && $this->kind === ActivityKind::Meeting) {
            ++$this->sequence;
        }
        $this->occurredAt      = $occurredAt;
        $this->title           = $title;
        $this->body            = $body;
        $this->direction       = $direction;
        $this->outcome         = $outcome;
        $this->durationMinutes = $durationMinutes;
        $this->endsAt          = $endsAt;
        $this->timezone        = $timezone;
        $this->place           = $place;
        $this->cancelled       = $cancelled;
        $this->updatedAt       = $now;
    }

    /** Points it at another record (merge). */
    public function moveTo(string $subjectId): void
    {
        $this->subjectId = $subjectId;
    }

    /** Replaces the author (an anonymised person). */
    public function changeAuthor(?string $authorId): void
    {
        $this->authorId = $authorId;
    }
}
