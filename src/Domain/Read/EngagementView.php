<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Read;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Enum\ActivityDirection;
use Nowo\CrmKitBundle\Domain\Enum\ActivityKind;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;

/**
 * A logged call, meeting or e-mail (0.11.0). Text values are plain text (print them escaped).
 */
final readonly class EngagementView
{
    /**
     * @param string|null $subjectLabel the primary record's label when it is not the record the feed is about
     * @param list<AttendeeView> $attendees a meeting's people the viewer may see (members by name, contacts they may view)
     */
    public function __construct(
        public string $id,
        public ActivityKind $kind,
        public SubjectType $subjectType,
        public string $subjectId,
        public ?string $subjectLabel,
        public DateTimeImmutable $occurredAt,
        public ?DateTimeImmutable $endsAt,
        public ?string $timezone,
        public ?string $title,
        public ?string $body,
        public ?ActivityDirection $direction,
        public ?string $outcome,
        public ?int $durationMinutes,
        public ?string $place,
        public bool $placeIsUrl,
        public bool $cancelled,
        public int $sequence,
        public array $attendees,
        public ?string $authorId,
        public ?string $authorName,
        public int $version,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
