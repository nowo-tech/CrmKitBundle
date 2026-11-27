<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application\HubSpot;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\CrmKitBundle\Application\CrmContext;
use Nowo\CrmKitBundle\Application\Engagement\EngagementService;
use Nowo\CrmKitBundle\Application\Engagement\Port\EngagementStoreInterface;
use Nowo\CrmKitBundle\Application\HubSpot\Port\HubSpotStoreInterface;
use Nowo\CrmKitBundle\Application\NoteService;
use Nowo\CrmKitBundle\Application\Pipeline\BuiltInCrmTaskPort;
use Nowo\CrmKitBundle\Application\Pipeline\NextActionService;
use Nowo\CrmKitBundle\Application\Pipeline\Port\PipelineStoreInterface;
use Nowo\CrmKitBundle\Application\WriteSupport;
use Nowo\CrmKitBundle\Domain\Enum\ActivityDirection;
use Nowo\CrmKitBundle\Domain\Enum\ActivityKind;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\HubSpotActivity;
use Nowo\CrmKitBundle\Domain\Enum\NextActionStatus;
use Nowo\CrmKitBundle\Domain\Enum\RecordStatus;
use Nowo\CrmKitBundle\Domain\Enum\RowOutcome;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\CrmKitBundle\Domain\Model\CrmRecord;
use Nowo\CrmKitBundle\Domain\Model\Engagement\Engagement;
use Nowo\CrmKitBundle\Domain\Model\Engagement\EngagementAttendee;
use Nowo\CrmKitBundle\Domain\Model\Engagement\EngagementSubject;
use Nowo\CrmKitBundle\Domain\Model\HubSpot\ExternalRef;
use Nowo\CrmKitBundle\Domain\Model\Note;
use Nowo\CrmKitBundle\Domain\Model\Pipeline\NextAction;
use Nowo\CrmKitBundle\Domain\Read\HubSpot\HubSpotEngagement;
use Nowo\CrmKitBundle\Domain\Value\ActivityDuration;
use Nowo\CrmKitBundle\Domain\Value\MeetingPlace;
use Nowo\CrmKitBundle\Domain\Value\MeetingSlot;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;

use function array_map;
use function array_unique;
use function array_values;
use function count;
use function hash;
use function sort;
use function strtoupper;

/**
 * Settles one page of HubSpot notes, calls, meetings or tasks inside the caller's unit (0.12.0, Beacon spec 254
 * FR-100 A): notes become notes, calls and meetings logged activities (`activities.enabled`), tasks next actions on
 * the built-in list (`pipeline.enabled`). Per item the external reference first (a re-run updates the item it made;
 * empty HubSpot values never clear a value; associations and attendees only add), else a new item on its primary
 * record: the first associated contact with a live record of the same account, else the first company. The other
 * associated records become an activity's subject rows (a meeting's contacts its attendees); a note or a next action
 * has one record, so the rest count as skipped associations. Bodies are HTML turned into plain text. Owners map to
 * authors and assignees by e-mail as for records (unmatched: none). The records are locked first, contacts before
 * companies, each in id order. Nothing is announced per item (the import's completion event and audit cover it).
 * Stateless.
 *
 * @internal
 */
final readonly class HubSpotEngagementWriter
{
    /** Length of a meeting without a usable end (minutes). */
    public const DEFAULT_MEETING = 30;

    /**
     * Wires the collaborators.
     *
     * @param EngagementStoreInterface|null $engagements null: `activities.enabled` is off (nothing is imported)
     * @param PipelineStoreInterface|null $pipeline null: `pipeline.enabled` is off (tasks are not imported)
     */
    public function __construct(
        private WriteSupport $support,
        private HubSpotStoreInterface $store,
        private HubSpotRecordWriter $records,
        private ?EngagementStoreInterface $engagements = null,
        private ?PipelineStoreInterface $pipeline = null,
    ) {
    }

    /**
     * The message key of a kind the configuration does not import, or null when it is imported.
     */
    public function unavailable(HubSpotActivity $kind): ?string
    {
        return match (true) {
            !$this->engagements instanceof EngagementStoreInterface                               => 'import.hubspot.activities_off',
            $kind === HubSpotActivity::Task && !$this->pipeline instanceof PipelineStoreInterface => 'import.hubspot.tasks_off',
            default                                                                               => null,
        };
    }

    /**
     * Settles the items: one row per item (outcome, primary record id, message key, HubSpot id, primary record type)
     * and the associations it could not write.
     *
     * @param list<HubSpotEngagement> $items
     * @param array<string, string> $owners `o<HubSpot owner id>` => actor id
     *
     * @throws NeedsPersonException
     *
     * @return array{rows: list<array{RowOutcome, string|null, string|null, string, SubjectType|null}>, skipped: int}
     */
    public function settle(CrmContext $context, string $account, HubSpotActivity $kind, array $items, array $owners, bool $attended): array
    {
        $hids     = array_map(static fn (HubSpotEngagement $e): string => $e->id->value, $items);
        $refs     = $this->store->refs($context->tenant, $account, $kind->refType(), $hids);
        $existing = $this->existing($context, $account, $kind, $items, $refs);
        $people   = $this->store->refs($context->tenant, $account, 'contact', $this->ids($items, true));
        $contacts = $this->records->targets($context, SubjectType::Contact, $people);
        $partners = $this->records->targets($context, SubjectType::Company, $this->store->refs($context->tenant, $account, 'company', $this->ids($items, false)));
        $lock     = [SubjectType::Contact->value => array_values($contacts), SubjectType::Company->value => array_values($partners)];
        foreach ($existing as $item) {
            $lock[$item->getSubjectType()->value][] = $item->getSubjectId();
        }
        // Locks: contacts first, then companies, each kind in id order (the order of every other path).
        $locked = [];
        foreach ([SubjectType::Contact, SubjectType::Company] as $type) {
            $ids = array_values(array_unique($lock[$type->value]));
            sort($ids);
            $locked[$type->value] = $this->support->store->lockRecords($type, $context->tenant, $ids);
        }
        $rows    = [];
        $skipped = 0;
        foreach ($items as $item) {
            $hid = $item->id->value;
            if ($this->erased($item, $refs[$hid] ?? null, $people)) {
                // Q-H6 (audit ZDE-M2): nothing said with or about an erased person comes back, not even on the other
                // records it was associated with.
                $rows[] = [RowOutcome::Duplicate, null, 'error.record_erased', $hid, null];

                continue;
            }
            $subjects   = [];
            $associated = 0;
            foreach ([[SubjectType::Contact, $item->contacts, $contacts], [SubjectType::Company, $item->companies, $partners]] as [$type, $ids, $targets]) {
                foreach ($ids as $id) {
                    ++$associated;
                    $record = $locked[$type->value][$targets[$id->value] ?? ''] ?? null;
                    if ($record instanceof CrmRecord && $record->isActive()) {
                        $subjects[$type->value . ':' . $record->getId()] = $record;
                    }
                }
            }
            $subjects = array_values($subjects);
            $found    = $existing[$hid] ?? null;
            $current  = $found === null ? null : ($locked[$found->getSubjectType()->value][$found->getSubjectId()] ?? null);
            if ($found !== null && $current instanceof CrmRecord) {
                $row = $this->update($context, $kind, $item, $found, $current, $subjects, $owners, $attended);
                $skipped += $row[5];
            } else {
                [$row, $written] = $this->create($context, $kind, $item, $subjects, $owners, $account, $attended);
                // The associations of a new item it could not carry (its other side has no live record, or a note or a
                // next action has one record only).
                $skipped += $written > 0 ? $associated - $written : 0;
                $found = $written > 0 ? $row[5] : null;
            }
            if ($found !== null) {
                $this->remember($context, $account, $kind, $hid, $refs[$hid] ?? null, $found);
            }
            $rows[] = [$row[0], $row[1], $row[2], $hid, $row[4]];
        }

        return ['rows' => $rows, 'skipped' => $skipped];
    }

    /**
     * The items earlier runs made, by HubSpot id (an item a person deleted is absent; a task is also found by its
     * dedupe key).
     *
     * @param list<HubSpotEngagement> $items
     * @param array<string, ExternalRef> $refs
     *
     * @return array<string, Engagement|NextAction|Note>
     */
    private function existing(CrmContext $context, string $account, HubSpotActivity $kind, array $items, array $refs): array
    {
        $found = [];
        if ($kind === HubSpotActivity::Call || $kind === HubSpotActivity::Meeting) {
            $byId = $this->engagements?->engagements($context->tenant, array_values(array_map(static fn (ExternalRef $r): string => $r->getTargetId(), $refs))) ?? [];
            foreach ($refs as $hid => $ref) {
                $engagement = $byId[$ref->getTargetId()] ?? null;
                if ($engagement instanceof Engagement && $engagement->getKind() === $this->activityKind($kind)) {
                    $found[(string) $hid] = $engagement;
                }
            }

            return $found;
        }
        foreach ($items as $item) {
            $ref    = $refs[$item->id->value] ?? null;
            $target = $ref?->getTargetId();
            $row    = match ($kind) {
                HubSpotActivity::Note => $target === null ? null : $this->support->store->note($context->tenant, $target),
                default               => ($target === null ? null : $this->pipeline?->nextAction($context->tenant, $target)) ?? $this->pipeline?->nextActionByDedupe($context->tenant, self::dedupeKey($account, $item->id->value)),
            };
            if ($row !== null) {
                $found[$item->id->value] = $row;
            }
        }

        return $found;
    }

    /**
     * Updates an item an earlier run made, on its own record (never moved): [outcome, record id, message, -, type,
     * associations it could not add].
     *
     * @param list<CrmRecord> $subjects
     * @param array<string, string> $owners
     *
     * @throws NeedsPersonException
     *
     * @return array{RowOutcome, string|null, string|null, null, SubjectType|null, int}
     */
    private function update(CrmContext $context, HubSpotActivity $kind, HubSpotEngagement $item, Note|Engagement|NextAction $found, CrmRecord $record, array $subjects, array $owners, bool $attended): array
    {
        $refused = match ($record->getStatus()) {
            RecordStatus::Trashed => 'error.record_trashed',
            RecordStatus::Erased  => 'error.record_erased',
            RecordStatus::Merged  => 'error.record_merged',
            RecordStatus::Active  => null,
        };
        if ($refused !== null) {
            return [RowOutcome::Duplicate, null, $refused, null, null, 0];
        }
        if (!$this->allowed($context, $record, $attended, CrmPermission::Edit)) {
            if (!$attended) {
                throw new NeedsPersonException();
            }

            return [RowOutcome::Invalid, null, 'error.forbidden', null, null, 0];
        }
        $author  = $this->author($item, $owners);
        $skipped = 0;
        if ($found instanceof Note) {
            $found->edit(HubSpotText::plain($item->value('hs_note_body'), NoteService::MAX) ?? $found->getBody(), $found->isPinned(), $context->now);
        } elseif ($found instanceof Engagement) {
            $this->describe($found, $kind, $item, $context->now, false);
            $skipped = $this->associate($context, $found, $subjects, $attended)[1];
        } else {
            $found->revise(
                HubSpotText::line($item->value('hs_task_subject'), NextActionService::MAX_TITLE) ?? $found->getTitle(),
                HubSpotText::time($item->value('hs_timestamp')) ?? $found->getDueAt(),
                // An existing next action changes assignee only when a person runs the page (as a record's owner).
                $attended && $author !== null ? $author : $found->getAssigneeId(),
            );
            $this->closeIfDone($found, $item, $context->now);
        }

        return [RowOutcome::Updated, $this->visible($record, $attended), null, null, $record::type(), $skipped];
    }

    /**
     * Creates the item on its primary record; the row and how many associations were written (0: nothing).
     *
     * @param list<CrmRecord> $subjects active associated records, contacts first
     * @param array<string, string> $owners
     *
     * @throws NeedsPersonException
     *
     * @return array{array{RowOutcome, string|null, string|null, null, SubjectType|null, Engagement|NextAction|Note|null}, int}
     */
    private function create(CrmContext $context, HubSpotActivity $kind, HubSpotEngagement $item, array $subjects, array $owners, string $account, bool $attended): array
    {
        if ($subjects === []) {
            return [[RowOutcome::Invalid, null, 'import.hubspot.no_record', null, null, null], 0];
        }
        // The first record the run may write on (audit ZDE-I2: past records it may not, attended or unattended).
        $primary = null;
        foreach ($subjects as $subject) {
            if ($primary === null && $this->allowed($context, $subject, $attended, CrmPermission::Edit)) {
                $primary = $subject;
            }
        }
        if (!$primary instanceof CrmRecord) {
            if (!$attended) {
                // Unattended: only records owned by someone else are left; a person's "Continue" decides.
                throw new NeedsPersonException();
            }

            return [[RowOutcome::Invalid, null, 'error.forbidden', null, null, null], 0];
        }
        $type   = $primary::type();
        $author = $this->author($item, $owners);
        $id     = $this->support->id();
        $made   = match ($kind) {
            HubSpotActivity::Note => $this->note($context, $item, $id, $primary, $author),
            HubSpotActivity::Task => $this->task($context, $item, $id, $primary, $author, $account),
            default               => $this->engagement($context, $kind, $item, $id, $primary, $author),
        };
        if (!$made instanceof Note && !$made instanceof Engagement && !$made instanceof NextAction) {
            return [[RowOutcome::Invalid, null, $made, null, null, null], 0];
        }
        $this->support->store->add($made); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        $written = 1;
        if ($made instanceof Engagement) {
            $this->support->store->add(new EngagementSubject($this->support->id(), $context->tenant->value, $id, $type, $primary->getId(), true)); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            $written += $this->associate($context, $made, $subjects, $attended, [$type->value . ':' . $primary->getId() => true])[0];
        }

        return [[RowOutcome::Created, $this->visible($primary, $attended), null, null, $type, $made], $written];
    }

    /**
     * A note, or the message key of why not.
     */
    private function note(CrmContext $context, HubSpotEngagement $item, string $id, CrmRecord $record, ?string $author): Note|string
    {
        $body = HubSpotText::plain($item->value('hs_note_body'), NoteService::MAX);
        if ($body === null) {
            return 'error.required';
        }

        return new Note($id, $context->tenant->value, $record::type(), $record->getId(), $body, false, $author, HubSpotText::time($item->value('hs_timestamp')) ?? $context->now);
    }

    /**
     * A next action on the built-in list (its own task), or the message key of why not.
     */
    private function task(CrmContext $context, HubSpotEngagement $item, string $id, CrmRecord $record, ?string $author, string $account): NextAction|string
    {
        $title = HubSpotText::line($item->value('hs_task_subject'), NextActionService::MAX_TITLE);
        $due   = HubSpotText::time($item->value('hs_timestamp'));
        if ($title === null || !$due instanceof DateTimeImmutable) {
            return $title === null ? 'error.required' : 'activity_error.time';
        }
        $action = new NextAction($id, $context->tenant->value, $record::type(), $record->getId(), $title, $due, $author, BuiltInCrmTaskPort::TYPE, $id, self::dedupeKey($account, $item->id->value), $context->actor->value, $context->now);
        $this->closeIfDone($action, $item, $context->now);

        return $action;
    }

    /**
     * A call or a meeting, or the message key of why not.
     */
    private function engagement(CrmContext $context, HubSpotActivity $kind, HubSpotEngagement $item, string $id, CrmRecord $record, ?string $author): Engagement|string
    {
        $at = $this->start($kind, $item);
        if (!$at instanceof DateTimeImmutable) {
            return 'activity_error.time';
        }
        $engagement = new Engagement($id, $context->tenant->value, $this->activityKind($kind), $record::type(), $record->getId(), $at, $author, $context->now);
        $this->describe($engagement, $kind, $item, $context->now, true);

        return $engagement;
    }

    /**
     * Sets a call's or a meeting's content; on a re-run an empty HubSpot value keeps the stored one.
     */
    private function describe(Engagement $engagement, HubSpotActivity $kind, HubSpotEngagement $item, DateTimeImmutable $now, bool $initial): void
    {
        $at        = $this->start($kind, $item) ?? $engagement->getOccurredAt();
        $prefix    = $kind === HubSpotActivity::Call ? 'hs_call_' : 'hs_meeting_';
        $title     = HubSpotText::line($item->value($prefix . 'title'), EngagementService::MAX_TITLE) ?? $engagement->getTitle();
        $body      = HubSpotText::plain($item->value($prefix . 'body'), NoteService::MAX) ?? $engagement->getBody();
        $direction = null;
        $duration  = null;
        $ends      = null;
        $zone      = null;
        $place     = null;
        $cancelled = false;
        if ($kind === HubSpotActivity::Call) {
            $direction = match (strtoupper($item->value('hs_call_direction') ?? '')) {
                'INBOUND'  => ActivityDirection::In,
                'OUTBOUND' => ActivityDirection::Out,
                default    => $engagement->getDirection(),
            };
            $duration = HubSpotText::minutes($item->value('hs_call_duration'), ActivityDuration::MAX) ?? $engagement->getDurationMinutes();
        } else {
            $slot      = $this->slot($at, HubSpotText::time($item->value('hs_meeting_end_time')));
            $at        = $slot->start;
            $ends      = $slot->end;
            $zone      = $slot->zone;
            $place     = $this->place($item->value('hs_meeting_location')) ?? $engagement->getPlace();
            $cancelled = strtoupper($item->value('hs_meeting_outcome') ?? '') === 'CANCELED';
        }
        $engagement->describe($at, $title, $body, $direction, $engagement->getOutcome(), $duration, $ends, $zone, $place, $cancelled, $now, $initial);
    }

    /**
     * When a call happened, when a meeting starts.
     */
    private function start(HubSpotActivity $kind, HubSpotEngagement $item): ?DateTimeImmutable
    {
        return ($kind === HubSpotActivity::Meeting ? HubSpotText::time($item->value('hs_meeting_start_time')) : null) ?? HubSpotText::time($item->value('hs_timestamp'));
    }

    /**
     * A meeting's slot in UTC: HubSpot's end when it is after the start (at most 7 days), else the default length.
     */
    private function slot(DateTimeImmutable $start, ?DateTimeImmutable $end): MeetingSlot
    {
        try {
            return new MeetingSlot($start, $end ?? $start, 'UTC');
        } catch (InvalidArgumentException) {
            return new MeetingSlot($start, $start->add(new DateInterval('PT' . self::DEFAULT_MEETING . 'M')), 'UTC');
        }
    }

    /**
     * A meeting's place (text or an https address), or null when HubSpot's value is not one.
     */
    private function place(?string $location): ?string
    {
        $line = HubSpotText::line($location, MeetingPlace::MAX);
        if ($line === null) {
            return null;
        }

        try {
            return (new MeetingPlace($line))->value;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Adds the subject rows (and a meeting's contact attendees) an activity does not have yet: how many were added and
     * how many were left out. Audit ZDE-M1 (as an affiliation of the records import, ZAM-1): each new record needs `view`
     * when a person runs the page and passes the owner rule unattended; any other is skipped and counted.
     *
     * @param list<CrmRecord> $subjects
     * @param array<string, true> $have `type:id` of the subject rows already there
     *
     * @return array{int, int}
     */
    private function associate(CrmContext $context, Engagement $engagement, array $subjects, bool $attended, array $have = []): array
    {
        $id        = $engagement->getId();
        $attendees = [];
        if ($have === []) {
            foreach ($this->engagements?->subjectsOf($context->tenant, [$id])[$id] ?? [] as $row) {
                $have[$row->getSubjectType()->value . ':' . $row->getSubjectId()] = true;
            }
            foreach ($this->engagements?->attendeesOf($context->tenant, [$id])[$id] ?? [] as $row) {
                $attendees[(string) $row->getContactId()] = true;
            }
        }
        $added   = 0;
        $denied  = 0;
        $meeting = $engagement->getKind() === ActivityKind::Meeting;
        foreach ($subjects as $subject) {
            $key = $subject::type()->value . ':' . $subject->getId();
            if (!isset($have[$key])) {
                if (!$this->allowed($context, $subject, $attended, CrmPermission::View)) {
                    ++$denied;

                    continue;
                }
                $have[$key] = true;
                $this->support->store->add(new EngagementSubject($this->support->id(), $context->tenant->value, $id, $subject::type(), $subject->getId(), false)); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
                ++$added;
            }
            if ($meeting && $subject::type() === SubjectType::Contact && !isset($attendees[$subject->getId()]) && count($attendees) < EngagementService::MAX_ATTENDEES) {
                $attendees[$subject->getId()] = true;
                $this->support->store->add(new EngagementAttendee($this->support->id(), $context->tenant->value, $id, null, $subject->getId())); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            }
        }

        return [$added, $denied];
    }

    /**
     * Writes or moves the item's reference.
     */
    private function remember(CrmContext $context, string $account, HubSpotActivity $kind, string $hid, ?ExternalRef $ref, Note|Engagement|NextAction $item): void
    {
        if (!$ref instanceof ExternalRef) {
            $this->support->store->add(new ExternalRef($this->support->id(), $context->tenant->value, $account, $kind->refType(), $hid, $kind->targetType(), $item->getId(), $context->now)); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        } elseif ($ref->getTargetId() !== $item->getId()) {
            // The item it named was deleted (or the task was found by its dedupe key): it now names this one.
            $this->store->retarget($ref, $item->getId(), $context->now);
        }
    }

    /**
     * Closes an open next action whose HubSpot task is completed (a re-run never reopens one).
     */
    private function closeIfDone(NextAction $action, HubSpotEngagement $item, DateTimeImmutable $now): void
    {
        if ($action->getStatus() === NextActionStatus::Open && strtoupper($item->value('hs_task_status') ?? '') === 'COMPLETED') {
            $action->close(NextActionStatus::Done, $now);
        }
    }

    /**
     * Whether the run may write on the record: the permission when a person runs the page; unattended, the owner rule
     * of `security.resource_scoping` (records without an owner or owned by the person who confirmed).
     */
    private function allowed(CrmContext $context, CrmRecord $record, bool $attended, CrmPermission $permission): bool
    {
        try {
            if ($attended) {
                $this->support->access->require($context, $permission, SubjectRef::of($record::type(), $record->getId(), $record->getOwnerId()));
            } else {
                $this->records->mayWriteUnattended($context, $record);
            }
        } catch (AccessDeniedException|NeedsPersonException) {
            return false;
        }

        return true;
    }

    /**
     * Whether the item was made by an import before its HubSpot reference became a tombstone (its CRM item was deleted
     * with an erased contact), or is associated with a contact whose reference is one.
     *
     * @param array<string, ExternalRef> $people the contact references of the page, by HubSpot id
     */
    private function erased(HubSpotEngagement $item, ?ExternalRef $ref, array $people): bool
    {
        if ($ref instanceof ExternalRef && $ref->isErased()) {
            return true;
        }
        foreach ($item->contacts as $contact) {
            if (($people[$contact->value] ?? null)?->isErased() === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * The record id a row shows: when a person runs the page (who may edit it); an unattended run names none.
     */
    private function visible(CrmRecord $record, bool $attended): ?string
    {
        return $attended ? $record->getId() : null;
    }

    /**
     * The actor of the item's HubSpot owner, or null.
     *
     * @param array<string, string> $owners
     */
    private function author(HubSpotEngagement $item, array $owners): ?string
    {
        $owner = $item->value('hubspot_owner_id');

        return $owner === null ? null : ($owners['o' . $owner] ?? null);
    }

    /**
     * The distinct associated contact (or company) ids of the page.
     *
     * @param list<HubSpotEngagement> $items
     *
     * @return list<string>
     */
    private function ids(array $items, bool $contacts): array
    {
        $ids = [];
        foreach ($items as $item) {
            foreach ($contacts ? $item->contacts : $item->companies as $id) {
                $ids[$id->value] = $id->value;
            }
        }

        return array_values($ids);
    }

    /**
     * The activity kind of a call or a meeting.
     */
    private function activityKind(HubSpotActivity $kind): ActivityKind
    {
        return $kind === HubSpotActivity::Meeting ? ActivityKind::Meeting : ActivityKind::Call;
    }

    /**
     * The dedupe key of a task's next action (64 hex, unique per tenant): the same HubSpot task is one next action.
     */
    public static function dedupeKey(string $account, string $hid): string
    {
        return hash('sha256', 'hubspot|' . $account . '|task|' . $hid);
    }
}
