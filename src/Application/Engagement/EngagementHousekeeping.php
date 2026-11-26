<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application\Engagement;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Application\Engagement\Port\EngagementStoreInterface;
use Nowo\CrmKitBundle\Application\Port\CrmStoreInterface;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Model\Engagement\Engagement;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

use function array_map;
use function array_values;

use const DATE_ATOM;

/**
 * The logged activities' part of the CRM's housekeeping (0.11.0, `activities.enabled`), inside the caller's unit:
 *
 * - a purged or erased record: the activities logged on it are deleted with their subject and attendee rows; the
 *   rows that associate other activities with it, and (a contact) its attendee rows, are deleted;
 * - a merged record: its activities, associations and attendee rows follow the survivor (a pair the survivor already
 *   has is deleted);
 * - a contact's GDPR export: the activities logged on it and the meetings it attends;
 * - retention: activities older than the tenant's `timeline_years` are deleted in batches.
 *
 * Stateless.
 */
final readonly class EngagementHousekeeping
{
    /** Activities per retention batch. */
    public const BATCH = 200;

    /**
     * Wires the stores.
     */
    public function __construct(private EngagementStoreInterface $store, private CrmStoreInterface $crm)
    {
    }

    /**
     * Forgets a record that is purged or erased (a company, a contact or a deal).
     */
    public function forgetRecord(TenantRef $tenant, SubjectType $type, string $id): void
    {
        $own = $this->store->ofPrimary($tenant, $type, $id);
        $this->delete($tenant, $own);
        $ids = [];
        foreach ($own as $engagement) {
            $ids[$engagement->getId()] = true;
        }
        foreach ($this->store->subjectRows($tenant, $type, $id) as $row) {
            if (!isset($ids[$row->getEngagementId()])) {
                $this->crm->remove($row); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            }
        }
        if ($type === SubjectType::Contact) {
            foreach ($this->store->attendeeRowsOfContact($tenant, $id) as $row) {
                if (!isset($ids[$row->getEngagementId()])) {
                    $this->crm->remove($row); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
                }
            }
        }
    }

    /**
     * Points a merged company's or contact's activities, associations and attendee rows at the survivor.
     */
    public function moveRecord(TenantRef $tenant, SubjectType $type, string $fromId, string $toId): void
    {
        foreach ($this->store->ofPrimary($tenant, $type, $fromId) as $engagement) {
            $engagement->moveTo($toId);
        }
        $have = [];
        foreach ($this->store->subjectRows($tenant, $type, $toId) as $row) {
            $have[$row->getEngagementId()] = true;
        }
        foreach ($this->store->subjectRows($tenant, $type, $fromId) as $row) {
            if (isset($have[$row->getEngagementId()])) {
                $this->crm->remove($row); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            } else {
                $have[$row->getEngagementId()] = true;
                $row->moveTo($toId);
            }
        }
        if ($type !== SubjectType::Contact) {
            return;
        }
        $attends = [];
        foreach ($this->store->attendeeRowsOfContact($tenant, $toId) as $row) {
            $attends[$row->getEngagementId()] = true;
        }
        foreach ($this->store->attendeeRowsOfContact($tenant, $fromId) as $row) {
            if (isset($attends[$row->getEngagementId()])) {
                $this->crm->remove($row); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            } else {
                $attends[$row->getEngagementId()] = true;
                $row->moveTo($toId);
            }
        }
    }

    /**
     * What the activities hold about a contact (GDPR export): the activities logged on it and the meetings it attends.
     *
     * @return array{activities: list<array<string, mixed>>, meetings_attended: list<array<string, mixed>>}
     */
    public function exportContact(TenantRef $tenant, string $contactId): array
    {
        $attended = [];
        foreach ($this->store->attendeeRowsOfContact($tenant, $contactId) as $row) {
            $attended[$row->getEngagementId()] = $row->getEngagementId();
        }

        return [
            'activities'        => array_map($this->row(...), $this->store->ofPrimary($tenant, SubjectType::Contact, $contactId)),
            'meetings_attended' => array_values(array_map($this->row(...), $this->store->engagements($tenant, array_values($attended)))),
        ];
    }

    /**
     * Deletes one batch of activities that happened before the time; how many.
     */
    public function purgeBefore(TenantRef $tenant, DateTimeImmutable $before): int
    {
        return $this->store->deleteBefore($tenant, $before, self::BATCH);
    }

    /**
     * Deletes activities with their subject and attendee rows.
     *
     * @param list<Engagement> $engagements
     */
    private function delete(TenantRef $tenant, array $engagements): void
    {
        if ($engagements === []) {
            return;
        }
        $ids = array_map(static fn (Engagement $e): string => $e->getId(), $engagements);
        foreach ($this->store->subjectsOf($tenant, $ids) as $rows) {
            foreach ($rows as $row) {
                $this->crm->remove($row); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            }
        }
        foreach ($this->store->attendeesOf($tenant, $ids) as $rows) {
            foreach ($rows as $row) {
                $this->crm->remove($row); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            }
        }
        foreach ($engagements as $engagement) {
            $this->crm->remove($engagement); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        }
    }

    /**
     * One activity in an export.
     *
     * @return array<string, mixed>
     */
    private function row(Engagement $e): array
    {
        return [
            'id'          => $e->getId(),
            'kind'        => $e->getKind()->value,
            'record'      => $e->getSubjectType()->value . ':' . $e->getSubjectId(),
            'occurred_at' => $e->getOccurredAt()->format(DATE_ATOM),
            'ends_at'     => $e->getEndsAt()?->format(DATE_ATOM),
            'timezone'    => $e->getTimezone(),
            'title'       => $e->getTitle(),
            'body'        => $e->getBody(),
            'direction'   => $e->getDirection()?->value,
            'outcome'     => $e->getOutcome(),
            'duration'    => $e->getDurationMinutes(),
            'place'       => $e->getPlace(),
            'cancelled'   => $e->isCancelled(),
            'created_at'  => $e->getCreatedAt()->format(DATE_ATOM),
        ];
    }
}
