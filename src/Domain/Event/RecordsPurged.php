<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Event;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * The scheduled retention purge deleted a batch of records for good (trashed records past `trash_days`, merge
 * tombstones past `merge_keep_days`), with their links, tags, roles, affiliations, notes and timeline. One event per
 * committed batch (at most 200 records of one type), no actor;
 * the subjects carry type and id only (no owner). A manual `purge()` dispatches {@see RecordPurged} instead.
 */
final class RecordsPurged extends CrmEvent
{
    /**
     * @param non-empty-list<SubjectRef> $subjects the deleted records (type and id)
     */
    public function __construct(
        TenantRef $tenant,
        DateTimeImmutable $occurredAt,
        public readonly array $subjects,
    ) {
        parent::__construct($tenant, null, $occurredAt);
    }
}
