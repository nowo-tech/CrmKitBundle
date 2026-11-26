<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Service;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Enum\ActivityDirection;
use Nowo\CrmKitBundle\Domain\Enum\ActivityKind;
use Nowo\CrmKitBundle\Domain\Value\MeetingAttendee;

/**
 * What a person logs (0.11.0): the values of a call, a meeting or an e-mail, validated by
 * {@see EngagementServiceInterface} (input errors come back per field: `occurred_at`, `ends_at`, `timezone`, `title`,
 * `body`, `direction`, `outcome`, `duration`, `place`, `attendees`).
 *
 * - call: `occurredAt`, `direction`, optional `outcome` (an `activities.call_outcomes` key), `durationMinutes`
 *   (0–1440), `title` (≤ 200) and `body` (≤ 10 000);
 * - meeting: `title` (required), `occurredAt` (start), `endsAt` (after the start, ≤ 7 days), `timezone` (IANA),
 *   optional `place` (≤ 200 or an https URL), `attendees` (≤ 50 members of the tenant or contacts the person may view),
 *   `body`, `cancelled`;
 * - email: `occurredAt`, `direction`, `title` (the subject, required), `body` (the excerpt, ≤ 500).
 */
final readonly class EngagementInput
{
    /**
     * @param list<MeetingAttendee> $attendees
     */
    public function __construct(
        public ActivityKind $kind,
        public DateTimeImmutable $occurredAt,
        public ?string $title = null,
        public ?string $body = null,
        public ?ActivityDirection $direction = null,
        public ?string $outcome = null,
        public ?int $durationMinutes = null,
        public ?DateTimeImmutable $endsAt = null,
        public ?string $timezone = null,
        public ?string $place = null,
        public array $attendees = [],
        public bool $cancelled = false,
    ) {
    }
}
