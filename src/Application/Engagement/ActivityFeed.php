<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application\Engagement;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Application\CrmContext;
use Nowo\CrmKitBundle\Application\Engagement\Port\EngagementStoreInterface;
use Nowo\CrmKitBundle\Application\Pipeline\PipelineSupport;
use Nowo\CrmKitBundle\Application\RecordViews;
use Nowo\CrmKitBundle\Application\WriteSupport;
use Nowo\CrmKitBundle\Domain\Enum\ActivityKind;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\FeedKind;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Model\CrmRecord;
use Nowo\CrmKitBundle\Domain\Model\Engagement\Engagement;
use Nowo\CrmKitBundle\Domain\Model\Note;
use Nowo\CrmKitBundle\Domain\Port\CrmHostActivitySourceInterface;
use Nowo\CrmKitBundle\Domain\Read\AttendeeView;
use Nowo\CrmKitBundle\Domain\Read\EngagementView;
use Nowo\CrmKitBundle\Domain\Read\Feed;
use Nowo\CrmKitBundle\Domain\Read\FeedItem;
use Nowo\CrmKitBundle\Domain\Read\NoteView;
use Nowo\CrmKitBundle\Domain\Service\ActivityFeedInterface;
use Nowo\CrmKitBundle\Domain\Value\HostActivityItem;
use Nowo\CrmKitBundle\Domain\Value\MeetingPlace;
use Nowo\CrmKitBundle\Domain\Value\RecordId;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\Domain\Value\TimelineFilter;
use Nowo\CrmKitBundle\Domain\Value\TimelineWindow;

use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function usort;

/**
 * The activity feed of a record (0.11.0): its notes, the activities shown on it (logged on it or associated with it)
 * and the host's items, filtered by kind and person, newest first, a keyset page at a time. Every activity is shown
 * only while the viewer may view its primary record (the same batch decision as a list), so a contact's call never
 * reaches a company page of someone who may not see that contact; a meeting's contacts the viewer may not view are left
 * out of its attendees. Host items of another record or outside the page are dropped. Stateless.
 */
final readonly class ActivityFeed implements ActivityFeedInterface
{
    /** Most store pages one feed page reads past hidden activities (audit ZCR-M1). */
    public const SCAN_ROUNDS = 5;

    /**
     * Wires the collaborators.
     */
    public function __construct(
        private WriteSupport $support,
        private EngagementStoreInterface $engagements,
        private RecordViews $views,
        private CrmHostActivitySourceInterface $hosts,
        private ?Mentions $mentions = null,
        private ?PipelineSupport $pipeline = null,
    ) {
    }

    /**
     * A page of the feed.
     */
    public function feed(SubjectType $type, string $id, TimelineFilter $filter, TimelineWindow $window): Feed
    {
        $context                      = $this->support->access->base();
        [$subject, $items, $boundary] = $this->support->read(function () use ($context, $type, $id, $filter, $window): array { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $subject  = $this->subject($context, $type, $id);
            $items    = [];
            $boundary = null;
            $kinds    = [];
            foreach (ActivityKind::LOGGABLE as $kind) {
                $feedKind = FeedKind::ofActivity($kind);
                if ($feedKind instanceof FeedKind && $filter->shows($feedKind)) {
                    $kinds[] = $kind;
                }
            }
            if ($kinds !== []) {
                [$visible, $boundary] = $this->scan($context, $type, $id, $kinds, $filter, $window);
                foreach ($this->engagementViews($context, $visible, $subject) as $view) {
                    $items[] = new FeedItem(FeedKind::ofActivity($view->kind) ?? FeedKind::Call, $view->id, $view->occurredAt, $view->authorId, $view->authorName, engagement: $view);
                }
            }
            if ($filter->shows(FeedKind::Note) && $type->isRecord()) {
                $items = [...$items, ...$this->notes($context, $type, $id, $filter, $window)];
            }

            return [$subject, $items, $boundary];
        });
        if ($filter->shows(FeedKind::Host)) {
            // Asked outside the read unit: a host source may run its own queries.
            $items = [...$items, ...$this->hostItems($context, $subject, $filter, $window)];
        }
        if ($boundary instanceof TimelineWindow) {
            // The scan stopped on hidden activities: this page ends at the last scanned row, everything older (notes and
            // host items included) waits for the next page, which continues from there.
            $items = array_values(array_filter($items, static fn (FeedItem $i): bool => !$boundary->admits($i->at, $i->id)));
        }
        usort($items, static fn (FeedItem $a, FeedItem $b): int => [(int) $b->at->format('U'), (int) $b->at->format('u'), $b->id] <=> [(int) $a->at->format('U'), (int) $a->at->format('u'), $a->id]);
        $page = array_slice($items, 0, $window->limit);
        $last = $page[count($page) - 1] ?? null;

        if (count($items) > $window->limit && $last instanceof FeedItem) {
            return new Feed($page, TimelineWindow::cursor($last->at, $last->id));
        }

        return new Feed($page, $boundary instanceof TimelineWindow && $boundary->before instanceof DateTimeImmutable && $boundary->beforeId !== null ? TimelineWindow::cursor($boundary->before, $boundary->beforeId) : null);
    }

    /**
     * The visible activities of a feed page (audit ZCR-M1): the store is read in pages of `limit + 1` rows and the
     * hidden ones (a primary record the viewer may not view) are dropped; while fewer than `limit + 1` are left and the
     * store still had a full page, the next one is read, at most {@see self::SCAN_ROUNDS} times. When the scan stops
     * with rows left, the position of its last row is returned (the page ends there and the next one starts there), so
     * hidden activities never end the feed early.
     *
     * @param list<ActivityKind> $kinds
     *
     * @return array{list<Engagement>, TimelineWindow|null}
     */
    private function scan(CrmContext $context, SubjectType $type, string $id, array $kinds, TimelineFilter $filter, TimelineWindow $window): array
    {
        $visible  = [];
        $before   = $window->before;
        $beforeId = $window->beforeId;
        for ($round = 1; $round <= self::SCAN_ROUNDS; ++$round) {
            $rows    = $this->engagements->feed($context->tenant, $type, $id, $kinds, $filter->person?->value, $before, $beforeId, $window->limit + 1);
            $visible = [...$visible, ...$this->viewableEngagements($context, $rows)];
            $last    = $rows[count($rows) - 1] ?? null;
            if (count($rows) <= $window->limit || count($visible) > $window->limit || !$last instanceof Engagement) {
                return [$visible, null];
            }
            $before   = $last->getOccurredAt();
            $beforeId = $last->getId();
        }

        return [$visible, new TimelineWindow($window->limit, $before, $beforeId)];
    }

    /**
     * One activity.
     */
    public function engagement(string $id): EngagementView
    {
        $context = $this->support->access->base();

        return $this->support->read(function () use ($context, $id): EngagementView { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $engagement = RecordId::isValid($id) ? $this->engagements->engagement($context->tenant, $id) : null;
            if (!$engagement instanceof Engagement) {
                throw new NotAvailableException();
            }
            $subject = $this->subject($context, $engagement->getSubjectType(), $engagement->getSubjectId());

            return $this->engagementViews($context, [$engagement], $subject)[0];
        });
    }

    /**
     * The meetings (not cancelled) the actor logged or attends that overlap `[$from, $to)`, on records the actor may
     * view, by start (0.11.0, TaskBoard's calendar source). Their `subjectLabel` is the primary record's label.
     *
     * @return list<EngagementView>
     */
    public function meetings(DateTimeImmutable $from, DateTimeImmutable $to, int $limit = 200): array
    {
        $context = $this->support->access->base();

        return $this->support->read(function () use ($context, $from, $to, $limit): array { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $rows = $this->engagements->meetingsOf($context->tenant, $context->actor->value, $from, $to, $limit);

            // No page record: every meeting carries its primary record's label.
            return $this->engagementViews($context, $this->viewableEngagements($context, $rows), null);
        });
    }

    /**
     * The record of the feed after the `view` check: a company or contact in any status (merged and trashed records
     * keep showing their history), or a deal with the funnels on.
     */
    private function subject(CrmContext $context, SubjectType $type, string $id): SubjectRef
    {
        if ($type === SubjectType::Deal) {
            $deal = $this->pipeline?->deal($context, $id, CrmPermission::View, trashed: true) ?? throw new NotAvailableException();

            return SubjectRef::of(SubjectType::Deal, $deal->getId(), $deal->getOwnerId());
        }
        $record = $this->support->load($context, $type, $id, CrmPermission::View, anyStatus: true);

        return SubjectRef::of($type, $record->getId(), $record->getOwnerId());
    }

    /**
     * The activities whose primary record exists and the viewer may view (one batch decision).
     *
     * @param list<Engagement> $rows
     *
     * @return list<Engagement>
     */
    private function viewableEngagements(CrmContext $context, array $rows): array
    {
        $records = $this->primaries($context, $rows);
        $refs    = [];
        foreach ($records as $record) {
            $refs[] = SubjectRef::of($record::type(), $record->getId(), $record->getOwnerId());
        }
        $granted = $this->support->access->viewable($context, $refs);
        $out     = [];
        foreach ($rows as $row) {
            $key = $row->getSubjectType()->value . ':' . $row->getSubjectId();
            if (isset($records[$key], $granted[$key])) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * The primary records of activities, keyed `<type>:<id>`.
     *
     * @param list<Engagement> $rows
     *
     * @return array<string, CrmRecord>
     */
    private function primaries(CrmContext $context, array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $ids[$row->getSubjectType()->value][$row->getSubjectId()] = $row->getSubjectId();
        }
        $out = [];
        foreach ([SubjectType::Company, SubjectType::Contact] as $type) {
            $wanted = array_values($ids[$type->value] ?? []);
            foreach ($wanted === [] ? [] : $this->support->store->records($type, $context->tenant, $wanted) as $record) {
                $out[$type->value . ':' . $record->getId()] = $record;
            }
        }

        return $out;
    }

    /**
     * The views of activities (one query for their attendees, one for the names, one for the contacts).
     *
     * @param list<Engagement> $rows
     *
     * @return list<EngagementView>
     */
    private function engagementViews(CrmContext $context, array $rows, ?SubjectRef $page): array
    {
        if ($rows === []) {
            return [];
        }
        $ids    = [];
        $actors = [];
        foreach ($rows as $row) {
            $ids[]    = $row->getId();
            $actors[] = $row->getAuthorId();
        }
        $attendees = $this->engagements->attendeesOf($context->tenant, $ids);
        $contacts  = [];
        foreach ($attendees as $list) {
            foreach ($list as $attendee) {
                $actors[] = $attendee->getActorId();
                if ($attendee->getContactId() !== null) {
                    $contacts[$attendee->getContactId()] = $attendee->getContactId();
                }
            }
        }
        $names  = $this->views->actorNames($context->tenant, $actors);
        $people = $contacts === [] ? [] : $this->support->access->viewableRecords($context, array_values($this->support->store->records(SubjectType::Contact, $context->tenant, array_values($contacts))));
        $labels = [];
        foreach ($people as $person) {
            $labels[$person->getId()] = $person->label();
        }
        $primary = $this->primaries($context, $rows);
        $views   = [];
        foreach ($rows as $row) {
            $list = [];
            foreach ($attendees[$row->getId()] ?? [] as $attendee) {
                $actor   = $attendee->getActorId();
                $contact = $attendee->getContactId();
                if ($actor !== null) {
                    $list[] = new AttendeeView($actor, null, $names[$actor] ?? $actor);
                } elseif ($contact !== null && isset($labels[$contact])) {
                    $list[] = new AttendeeView(null, $contact, $labels[$contact]);
                }
            }
            $key     = $row->getSubjectType()->value . ':' . $row->getSubjectId();
            $own     = $page instanceof SubjectRef && $row->getSubjectType() === $page->type && $row->getSubjectId() === $page->id->value;
            $author  = $row->getAuthorId();
            $place   = $row->getPlace();
            $views[] = new EngagementView(
                $row->getId(),
                $row->getKind(),
                $row->getSubjectType(),
                $row->getSubjectId(),
                $own ? null : ($primary[$key] ?? null)?->label(),
                $row->getOccurredAt(),
                $row->getEndsAt(),
                $row->getTimezone(),
                $row->getTitle(),
                $row->getBody(),
                $row->getDirection(),
                $row->getOutcome(),
                $row->getDurationMinutes(),
                $place,
                $place !== null && MeetingPlace::isHttpsUrl($place),
                $row->isCancelled(),
                $row->getSequence(),
                $list,
                $author,
                $author === null ? null : ($names[$author] ?? null),
                $row->getVersion(),
                $row->getCreatedAt(),
                $row->getUpdatedAt(),
            );
        }

        return $views;
    }

    /**
     * The record's notes on the page (by time written), with their mentions shown as names.
     *
     * @return list<FeedItem>
     */
    private function notes(CrmContext $context, SubjectType $type, string $id, TimelineFilter $filter, TimelineWindow $window): array
    {
        $rows = [];
        foreach ($this->support->store->notes($context->tenant, $type, $id) as $note) {
            if ($filter->by($note->getAuthorId()) && $window->admits($note->getCreatedAt(), $note->getId())) {
                $rows[] = $note;
            }
        }
        usort($rows, static fn (Note $a, Note $b): int => [$b->getCreatedAt()->getTimestamp(), $b->getId()] <=> [$a->getCreatedAt()->getTimestamp(), $a->getId()]);
        $rows   = array_slice($rows, 0, $window->limit + 1);
        $names  = $this->views->actorNames($context->tenant, array_map(static fn (Note $n): ?string => $n->getAuthorId(), $rows));
        $labels = $this->mentions?->labels($context->tenant) ?? [];
        $items  = [];
        foreach ($rows as $note) {
            $author  = $note->getAuthorId();
            $name    = $author === null ? null : ($names[$author] ?? null);
            $view    = new NoteView($note->getId(), $note->getBody(), $note->isPinned(), $author, $name, $note->getVersion(), $note->getCreatedAt(), $note->getUpdatedAt());
            $items[] = new FeedItem(FeedKind::Note, $note->getId(), $note->getCreatedAt(), $author, $name, note: $view, text: Mentions::display($note->getBody(), $labels));
        }

        return $items;
    }

    /**
     * The host's items of the record on the page: only items of this record, inside the window and by the person.
     *
     * @return list<FeedItem>
     */
    private function hostItems(CrmContext $context, SubjectRef $subject, TimelineFilter $filter, TimelineWindow $window): array
    {
        $kept = [];
        foreach ($this->hosts->items($context->tenant, $context->actor, $subject, $window) as $item) {
            if (!$item instanceof HostActivityItem || $item->subject->type !== $subject->type || $item->subject->id->value !== $subject->id->value) {
                continue;
            }
            $id = 'host:' . $item->id;
            if (!$window->admits($item->at, $id) || !$filter->by($item->actor?->value)) {
                continue;
            }
            $kept[] = new FeedItem(FeedKind::Host, $id, $item->at, $item->actor?->value, null, host: $item);
            if (count($kept) > $window->limit) {
                break;
            }
        }
        if ($kept === []) {
            return [];
        }
        $names = $this->support->read(fn (): array => $this->views->actorNames($context->tenant, array_map(static fn (FeedItem $i): ?string => $i->actorId, $kept)));
        $out   = [];
        foreach ($kept as $item) {
            $out[] = new FeedItem(FeedKind::Host, $item->id, $item->at, $item->actorId, $item->actorId === null ? null : ($names[$item->actorId] ?? null), host: $item->host);
        }

        return $out;
    }
}
