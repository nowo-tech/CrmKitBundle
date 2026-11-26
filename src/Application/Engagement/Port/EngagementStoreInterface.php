<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application\Engagement\Port;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Enum\ActivityKind;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Model\Engagement\Engagement;
use Nowo\CrmKitBundle\Domain\Model\Engagement\EngagementAttendee;
use Nowo\CrmKitBundle\Domain\Model\Engagement\EngagementSubject;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * Where the logged activities live (0.11.0, only while `activities.enabled`). Every query names the tenant and every
 * result is re-checked with `===` against it. New rows and removals go through the CRM store's `add()` / `remove()`
 * (the same entity manager and unit).
 *
 * @internal
 */
interface EngagementStoreInterface
{
    /** One activity of the tenant (the writes lock its primary record instead of the row). */
    public function engagement(TenantRef $tenant, string $id): ?Engagement;

    /**
     * Activities of the tenant by id (missing ids are absent).
     *
     * @param list<string> $ids
     *
     * @return array<string, Engagement>
     */
    public function engagements(TenantRef $tenant, array $ids): array;

    /**
     * The activities shown on a record (through their subject rows), newest first (time, then id), strictly before
     * the position, of the given kinds (empty: every loggable kind) and author (null: anybody).
     *
     * @param list<ActivityKind> $kinds
     *
     * @return list<Engagement>
     */
    public function feed(TenantRef $tenant, SubjectType $type, string $id, array $kinds, ?string $author, ?DateTimeImmutable $before, ?string $beforeId, int $limit): array;

    /**
     * The activities whose primary record is this one.
     *
     * @return list<Engagement>
     */
    public function ofPrimary(TenantRef $tenant, SubjectType $type, string $id): array;

    /**
     * The subject rows of activities, by activity id.
     *
     * @param list<string> $engagementIds
     *
     * @return array<string, list<EngagementSubject>>
     */
    public function subjectsOf(TenantRef $tenant, array $engagementIds): array;

    /**
     * The subject rows that point at a record.
     *
     * @return list<EngagementSubject>
     */
    public function subjectRows(TenantRef $tenant, SubjectType $type, string $id): array;

    /**
     * The attendee rows of meetings, by meeting id.
     *
     * @param list<string> $engagementIds
     *
     * @return array<string, list<EngagementAttendee>>
     */
    public function attendeesOf(TenantRef $tenant, array $engagementIds): array;

    /**
     * The attendee rows of a contact.
     *
     * @return list<EngagementAttendee>
     */
    public function attendeeRowsOfContact(TenantRef $tenant, string $contactId): array;

    /**
     * Meetings (not cancelled) a member logged or attends that overlap `[$from, $to)`, by start, at most `$limit`.
     *
     * @return list<Engagement>
     */
    public function meetingsOf(TenantRef $tenant, string $actorId, DateTimeImmutable $from, DateTimeImmutable $to, int $limit): array;

    /**
     * Deletes up to `$limit` activities that happened before the time (with their subject and attendee rows); how many.
     */
    public function deleteBefore(TenantRef $tenant, DateTimeImmutable $before, int $limit): int;
}
