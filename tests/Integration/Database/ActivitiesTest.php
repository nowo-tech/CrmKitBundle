<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\CrmKitBundle\Application\ActorPersonalData;
use Nowo\CrmKitBundle\Application\Engagement\ActivityFeed;
use Nowo\CrmKitBundle\Application\Engagement\Mentions;
use Nowo\CrmKitBundle\Application\Engagement\Port\EngagementStoreInterface;
use Nowo\CrmKitBundle\Application\Pipeline\DealInput;
use Nowo\CrmKitBundle\Application\Pipeline\DealService;
use Nowo\CrmKitBundle\Application\Pipeline\FunnelService;
use Nowo\CrmKitBundle\Application\RecordPurger;
use Nowo\CrmKitBundle\Domain\Enum\ActivityDirection;
use Nowo\CrmKitBundle\Domain\Enum\ActivityKind;
use Nowo\CrmKitBundle\Domain\Enum\FeedKind;
use Nowo\CrmKitBundle\Domain\Enum\FunnelKind;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Read\AttendeeView;
use Nowo\CrmKitBundle\Domain\Read\EngagementView;
use Nowo\CrmKitBundle\Domain\Read\Feed;
use Nowo\CrmKitBundle\Domain\Read\FeedItem;
use Nowo\CrmKitBundle\Domain\Read\MentionCandidate;
use Nowo\CrmKitBundle\Domain\Service\ActivityFeedInterface;
use Nowo\CrmKitBundle\Domain\Service\CrmActorPersonalDataInterface;
use Nowo\CrmKitBundle\Domain\Service\CrmPersonalDataInterface;
use Nowo\CrmKitBundle\Domain\Service\EngagementInput;
use Nowo\CrmKitBundle\Domain\Service\EngagementServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\MentionDirectoryInterface;
use Nowo\CrmKitBundle\Domain\Service\MergeServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\NoteServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\PinnedNotesInterface;
use Nowo\CrmKitBundle\Domain\Service\RecordInput;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\CrmNotification;
use Nowo\CrmKitBundle\Domain\Value\HostActivityItem;
use Nowo\CrmKitBundle\Domain\Value\MeetingAttendee;
use Nowo\CrmKitBundle\Domain\Value\RecordId;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Nowo\CrmKitBundle\Domain\Value\TimelineFilter;
use Nowo\CrmKitBundle\Domain\Value\TimelineWindow;
use Nowo\CrmKitBundle\Tests\Fixtures\TestActivitySources;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Throwable;

use function array_filter;
use function array_map;
use function array_replace_recursive;
use function array_unique;
use function array_values;
use function end;
use function is_scalar;
use function str_pad;
use function str_repeat;

use const STR_PAD_LEFT;

/**
 * 0.11.0 (Beacon spec 254, A-1): logged calls, meetings and e-mails, their association fan-out, the activity feed with
 * its filter and keyset pages, pinned notes, @mentions, the host activity source, GDPR export / erasure / retention,
 * merges, actor anonymisation, audit entries and after-commit events.
 */
final class ActivitiesTest extends CrmTestCase
{
    /**
     * @param array<string, mixed> $config
     */
    protected function boot(array $config = []): void
    {
        /** @var array<string, mixed> $merged */
        $merged = array_replace_recursive(['activities' => ['enabled' => true, 'host_source' => 'test.activities', 'notifier' => 'test.activities'], 'pipeline' => ['enabled' => true, 'task_port' => 'test.task_port']], $config);
        parent::boot($merged);
    }

    public function testACallFansOutToTheContactsCompaniesAndOpenDeals(): void
    {
        $this->boot();
        $this->admin();
        [$ada, $acme, $globex, $deal, $won] = $this->network();
        $id                                 = $this->engagements()->log(SubjectType::Contact, $ada, $this->call(outcome: 'busy', duration: 12, title: ' Follow-up ', body: ' Asked for a quote '));
        self::assertSame(4, $this->int('SELECT COUNT(*) FROM nowo_crm_engagement_subject WHERE engagement_id = ?', [$id]), 'the contact, its two companies, its open deal');
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_engagement_subject WHERE engagement_id = ? AND is_primary = ?', [$id, true]));
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_engagement_subject WHERE subject_id = ?', [$won]), 'a won deal is left out');
        foreach ([[SubjectType::Contact, $ada], [SubjectType::Company, $acme], [SubjectType::Company, $globex], [SubjectType::Deal, $deal]] as [$type, $record]) {
            $items = $this->feed($type, $record)->items;
            self::assertCount(1, array_filter($items, static fn (FeedItem $i): bool => $i->engagement instanceof EngagementView), $type->value);
        }
        $view = $this->feed(SubjectType::Company, $acme)->items[0]->engagement;
        self::assertNotNull($view);
        self::assertSame(['Follow-up', 'Asked for a quote', 'busy', 12, ActivityDirection::Out, 'Ada Lovelace'], [$view->title, $view->body, $view->outcome, $view->durationMinutes, $view->direction, $view->subjectLabel]);
        self::assertNull($this->feed(SubjectType::Contact, $ada)->items[0]->engagement?->subjectLabel, 'its own record: no label');
        self::assertSame('Ana', $view->authorName);
        self::assertContains('crm.activity.logged', $this->recorder()->actions());
        self::assertContains('EngagementLogged', $this->recorder()->eventNames());
        self::assertSame(1, $this->int("SELECT COUNT(*) FROM nowo_crm_activity WHERE kind = 'call' AND subject_id = ?", [$ada]));

        // A company's activity goes to its open deals only.
        $company = $this->engagements()->log(SubjectType::Company, $acme, $this->email());
        self::assertSame(2, $this->int('SELECT COUNT(*) FROM nowo_crm_engagement_subject WHERE engagement_id = ?', [$company]));
        $this->expectThrows(NotAvailableException::class, fn (): string => $this->engagements()->log(SubjectType::Deal, $deal, $this->call()));
        $this->expectThrows(NotAvailableException::class, fn (): string => $this->engagements()->log(SubjectType::Contact, $ada, new EngagementInput(ActivityKind::Sms, new DateTimeImmutable())));
        $this->expectThrows(NotAvailableException::class, fn (): string => $this->engagements()->log(SubjectType::Contact, 'nope', $this->call()));
    }

    public function testValidationPerKind(): void
    {
        $this->boot();
        $this->admin();
        $ada = $this->contact();
        $log = fn (EngagementInput $input): string => $this->engagements()->log(SubjectType::Contact, $ada, $input);
        $at  = new DateTimeImmutable('2026-11-18 09:00', new DateTimeZone('UTC'));
        $this->expectErrors(['title' => 'activity_error.title', 'body' => 'error.note_invalid', 'direction' => 'error.required', 'outcome' => 'activity_error.outcome', 'duration' => 'activity_error.duration'], static fn (): string => $log(new EngagementInput(ActivityKind::Call, $at, str_repeat('t', 201), "a\x07", null, 'unknown', 1441)));
        $this->expectErrors(['occurred_at' => 'activity_error.time'], static fn (): string => $log(new EngagementInput(ActivityKind::Call, new DateTimeImmutable('1960-01-01'), direction: ActivityDirection::In)));
        $this->expectErrors(['title' => 'error.required', 'body' => 'activity_error.excerpt'], static fn (): string => $log(new EngagementInput(ActivityKind::Email, $at, ' ', str_repeat('x', 501), ActivityDirection::In)));
        $this->expectErrors(['title' => 'error.required', 'timezone' => 'activity_error.timezone', 'place' => 'activity_error.place'], static fn (): string => $log(new EngagementInput(ActivityKind::Meeting, $at, null, null, null, null, null, $at->modify('+1 hour'), 'Mars/Base', 'http://insecure.test')));
        $this->expectErrors(['ends_at' => 'error.required'], static fn (): string => $log(new EngagementInput(ActivityKind::Meeting, $at, 'Review', timezone: 'UTC')));
        $this->expectErrors(['ends_at' => 'activity_error.slot'], static fn (): string => $log(new EngagementInput(ActivityKind::Meeting, $at, 'Review', endsAt: $at, timezone: 'UTC')));
        $this->expectErrors(['attendees' => 'activity_error.attendee_unknown'], static fn (): string => $log(new EngagementInput(ActivityKind::Meeting, $at, 'Review', endsAt: $at->modify('+1 hour'), timezone: 'UTC', attendees: [MeetingAttendee::member(new ActorRef('stranger'))])));
        $this->expectErrors(['attendees' => 'activity_error.attendee_unknown'], static fn (): string => $log(new EngagementInput(ActivityKind::Meeting, $at, 'Review', endsAt: $at->modify('+1 hour'), timezone: 'UTC', attendees: [MeetingAttendee::member(new ActorRef(ActorPersonalData::PLACEHOLDER))])));
        $this->expectErrors(['attendees' => 'activity_error.attendee_unknown'], static fn (): string => $log(new EngagementInput(ActivityKind::Meeting, $at, 'Review', endsAt: $at->modify('+1 hour'), timezone: 'UTC', attendees: [MeetingAttendee::contact(new RecordId('0190a000-0000-7000-8000-00000000dead'))])));
        $hidden                = $this->contact('Hidden', 'Person');
        $this->ctx()->denied[] = 'view:' . $hidden;
        $this->expectErrors(['attendees' => 'activity_error.attendee_unknown'], static fn (): string => $log(new EngagementInput(ActivityKind::Meeting, $at, 'Review', endsAt: $at->modify('+1 hour'), timezone: 'UTC', attendees: [MeetingAttendee::contact(new RecordId($hidden))])));
        $many = [];
        for ($i = 0; $i < 51; ++$i) {
            $many[] = MeetingAttendee::contact(new RecordId('0190a000-0000-7000-8000-0000000000' . str_pad((string) $i, 2, '0', STR_PAD_LEFT)));
        }
        try {
            $log(new EngagementInput(ActivityKind::Meeting, $at, 'Review', endsAt: $at->modify('+1 hour'), timezone: 'UTC', attendees: $many));
            self::fail('accepted');
        } catch (InvalidInputException $e) {
            self::assertSame(['attendees' => 'activity_error.attendees_limit'], $e->errors);
            self::assertSame(['%max%' => '50'], $e->params);
        }
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_engagement'));
    }

    public function testMeetingsAttendeesEditsSequenceAndDelete(): void
    {
        $this->boot();
        $this->admin();
        [$ada, $acme] = $this->network();
        $bob          = $this->contact('Bob', 'Builder');
        $at           = new DateTimeImmutable('2026-11-20 10:00', new DateTimeZone('Europe/Madrid'));
        $id           = $this->engagements()->log(SubjectType::Company, $acme, new EngagementInput(ActivityKind::Meeting, $at, 'Quarterly review', 'Agenda', endsAt: $at->modify('+1 hour'), timezone: 'Europe/Madrid', place: 'https://meet.example.test/q', attendees: [MeetingAttendee::member(new ActorRef('leo')), MeetingAttendee::member(new ActorRef('leo')), MeetingAttendee::contact(new RecordId($ada)), MeetingAttendee::contact(new RecordId($bob))]));
        self::assertSame(3, $this->int('SELECT COUNT(*) FROM nowo_crm_engagement_attendee WHERE engagement_id = ?', [$id]), 'duplicates once');
        $view = $this->service(ActivityFeedInterface::class)->engagement($id);
        self::assertSame(['Quarterly review', '2026-11-20 09:00', 'Europe/Madrid', true, 0], [$view->title, $view->occurredAt->format('Y-m-d H:i'), $view->timezone, $view->placeIsUrl, $view->sequence]);
        self::assertSame(['Leo', 'Ada Lovelace', 'Bob Builder'], array_map(static fn (AttendeeView $a): string => $a->name, $view->attendees));
        // A contact the viewer may not see is left out of the attendees.
        $this->ctx()->denied[] = 'view:' . $bob;
        self::assertSame(['Leo', 'Ada Lovelace'], array_map(static fn (AttendeeView $a): string => $a->name, $this->service(ActivityFeedInterface::class)->engagement($id)->attendees));
        $this->ctx()->denied = [];

        $edit = new EngagementInput(ActivityKind::Meeting, $at->modify('+1 day'), 'Quarterly review', 'Agenda v2', endsAt: $at->modify('+1 day +1 hour'), timezone: 'Europe/Madrid', place: 'Room 4', attendees: [MeetingAttendee::member(new ActorRef('leo'))], cancelled: true);
        $this->engagements()->edit($id, $edit, 1);
        $view = $this->service(ActivityFeedInterface::class)->engagement($id);
        self::assertSame([1, true, 'Room 4', false, 2], [$view->sequence, $view->cancelled, $view->place, $view->placeIsUrl, $view->version]);
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_engagement_attendee WHERE engagement_id = ?', [$id]));
        $this->expectConflictCode(ConflictException::STALE, fn () => $this->engagements()->edit($id, $edit, 1));
        $this->expectErrors(['kind' => 'activity_error.kind'], fn () => $this->engagements()->edit($id, $this->call(), 2));
        $this->expectThrows(NotAvailableException::class, fn () => $this->engagements()->edit('nope', $edit, 1));
        self::assertContains('crm.activity.edited', $this->recorder()->actions());
        self::assertSame(1, $this->int("SELECT COUNT(*) FROM nowo_crm_activity WHERE kind = 'activity_edited'"));

        // Someone else's activity: edit on the record is not enough, manage is.
        $this->actAs('leo', 'view', 'edit');
        $this->expectThrows(AccessDeniedException::class, fn () => $this->engagements()->delete($id));
        $own = $this->engagements()->log(SubjectType::Company, $acme, $this->email());
        $this->engagements()->delete($own);
        $this->actAs('leo', 'manage');
        $this->engagements()->delete($id);
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_engagement'));
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_engagement_subject'));
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_engagement_attendee'));
        self::assertContains('crm.activity.deleted', $this->recorder()->actions());
        self::assertContains('EngagementDeleted', $this->recorder()->eventNames());
        self::assertContains('EngagementChanged', $this->recorder()->eventNames());
    }

    public function testTheFeedFiltersPagesAndHidesWhatTheViewerMayNotSee(): void
    {
        $this->boot();
        $this->admin();
        [$ada, $acme] = $this->network();
        $eve          = $this->contact('Eve', 'Hidden');
        $this->records()->affiliate($eve, $acme, null, false);
        $base = new DateTimeImmutable('2026-11-10 08:00', new DateTimeZone('UTC'));
        for ($i = 0; $i < 3; ++$i) {
            $this->engagements()->log(SubjectType::Contact, $ada, $this->call(at: $base->modify('+' . $i . ' hour')));
        }
        $this->engagements()->log(SubjectType::Contact, $eve, $this->email());
        $this->service(NoteServiceInterface::class)->add(SubjectType::Company, $acme, 'A company note');
        $this->actAs('leo', 'view', 'edit');
        $this->engagements()->log(SubjectType::Company, $acme, $this->call(at: $base->modify('-1 day')));
        $this->admin();

        self::assertSame([FeedKind::Note, FeedKind::Email, FeedKind::Call, FeedKind::Call, FeedKind::Call, FeedKind::Call], array_map(static fn (FeedItem $i): FeedKind => $i->kind, $this->feed(SubjectType::Company, $acme)->items));
        // Eve's e-mail disappears from the company for a viewer who may not see Eve.
        $this->ctx()->denied[] = 'view:' . $eve;
        self::assertNotContains(FeedKind::Email, array_map(static fn (FeedItem $i): FeedKind => $i->kind, $this->feed(SubjectType::Company, $acme)->items));
        $this->ctx()->denied = [];

        $calls = $this->feed(SubjectType::Company, $acme, new TimelineFilter([FeedKind::Call]))->items;
        self::assertCount(4, $calls);
        $leos = $this->feed(SubjectType::Company, $acme, new TimelineFilter([], new ActorRef('leo')))->items;
        self::assertCount(1, $leos);
        self::assertSame('leo', $leos[0]->actorId);
        self::assertCount(1, $this->feed(SubjectType::Company, $acme, new TimelineFilter([FeedKind::Note]))->items);
        self::assertSame([], $this->feed(SubjectType::Company, $acme, new TimelineFilter([FeedKind::Meeting]))->items);

        // Keyset pages of two: every entry exactly once, newest first.
        $seen   = [];
        $cursor = null;
        do {
            $page   = $this->feed(SubjectType::Company, $acme, new TimelineFilter(), TimelineWindow::after($cursor, 2));
            $seen   = [...$seen, ...array_map(static fn (FeedItem $i): string => $i->id, $page->items)];
            $cursor = $page->next;
        } while ($cursor !== null);
        self::assertCount(6, $seen);
        self::assertSame($seen, array_values(array_unique($seen)));
        $this->expectThrows(NotAvailableException::class, fn (): Feed => $this->feed(SubjectType::Contact, 'nope'));
        $this->ctx()->grants['acme']['ana'] = [];
        $this->expectThrows(AccessDeniedException::class, fn (): Feed => $this->feed(SubjectType::Contact, $ada));
    }

    public function testHostItemsAreFilteredToTheRecordWindowAndPerson(): void
    {
        $this->boot();
        $this->admin();
        $ada            = $this->contact();
        $other          = $this->contact('Other', 'One');
        $sources        = $this->sources();
        $subject        = SubjectRef::of(SubjectType::Contact, $ada);
        $at             = new DateTimeImmutable('2026-11-15 10:00', new DateTimeZone('UTC'));
        $sources->items = [
            new HostActivityItem($subject, 't1', 'Task done', $at, '/tasks/1', new ActorRef('ana'), 'tabler:checkbox'),
            new HostActivityItem(SubjectRef::of(SubjectType::Contact, $other), 't2', 'Not this record', $at),
            new HostActivityItem(SubjectRef::of(SubjectType::Company, $ada), 't3', 'Wrong type', $at),
            new HostActivityItem($subject, 't4', 'Time entry', $at->modify('-1 day'), null, new ActorRef('leo')),
            new HostActivityItem($subject, 't5', 'Third', $at->modify('-2 day')),
        ];
        $items = $this->feed(SubjectType::Contact, $ada, new TimelineFilter([FeedKind::Host]))->items;
        self::assertSame(['host:t1', 'host:t4', 'host:t5'], array_map(static fn (FeedItem $i): string => $i->id, $items));
        self::assertSame(['Ana', 'Leo'], [$items[0]->actorName, $items[1]->actorName]);
        self::assertSame([['acme', 'ana']], [$sources->asked[0]]);
        self::assertSame(['host:t4'], array_map(static fn (FeedItem $i): string => $i->id, $this->feed(SubjectType::Contact, $ada, new TimelineFilter([], new ActorRef('leo')))->items));
        // A page of one: the source's extra items are cut, the window drops what is not before the cursor.
        $page = $this->feed(SubjectType::Contact, $ada, new TimelineFilter([FeedKind::Host]), new TimelineWindow(1));
        self::assertCount(1, $page->items);
        self::assertNotNull($page->next);
        $next = $this->feed(SubjectType::Contact, $ada, new TimelineFilter([FeedKind::Host]), TimelineWindow::after($page->next, 1));
        self::assertSame(['host:t4'], array_map(static fn (FeedItem $i): string => $i->id, $next->items));
        $sources->items = [new HostActivityItem(SubjectRef::of(SubjectType::Contact, $other), 't2', 'Not this record', $at)];
        self::assertSame([], $this->feed(SubjectType::Contact, $ada, new TimelineFilter([FeedKind::Host]))->items);
    }

    public function testPinnedNotesKeepTheLimit(): void
    {
        $this->boot();
        $this->admin();
        $ada   = $this->contact();
        $notes = $this->service(NoteServiceInterface::class);
        $pins  = $this->service(PinnedNotesInterface::class);
        $first = $notes->add(SubjectType::Contact, $ada, 'First', true);
        $this->clock()->modify('+1 minute');
        $second = $notes->add(SubjectType::Contact, $ada, 'Second', true);
        self::assertSame([$second], $this->pinned($ada), 'one pinned note per record (default)');
        $pins->pin($first, true, 2);
        self::assertSame([$first], $this->pinned($ada));
        $this->expectConflictCode(ConflictException::STALE, static fn () => $pins->pin($second, true, 1));
        $pins->pin($first, false, 3);
        self::assertSame([], $this->pinned($ada));
        $pins->pin($first, false, 4);
        $notes->edit($second, 'Second, edited', true, 2);
        self::assertSame([$second], $this->pinned($ada));
        $this->expectThrows(NotAvailableException::class, static fn () => $pins->pin('nope', true, 1));
        self::assertContains('crm.note.pinned', $this->recorder()->actions());
        self::assertContains('crm.note.unpinned', $this->recorder()->actions());
        self::assertContains('NotePinned', $this->recorder()->eventNames());
        // Others may pin with edit on the record (the text stays the author's).
        $this->actAs('leo', 'view', 'edit');
        $pins->pin($first, true, 4);
        self::assertSame([$first], $this->pinned($ada));
    }

    public function testNoPinLimitWithZeroAndWhileActivitiesAreOff(): void
    {
        $this->boot(['activities' => ['max_pinned' => 2]]);
        $this->admin();
        $ada   = $this->contact();
        $notes = $this->service(NoteServiceInterface::class);
        foreach (['A', 'B', 'C'] as $i => $text) {
            $this->clock()->modify('+' . ($i + 1) . ' minute');
            $notes->add(SubjectType::Contact, $ada, $text, true);
        }
        self::assertCount(2, $this->pinned($ada), 'the two newest');
        $this->boot(['activities' => ['enabled' => false]]);
        $this->admin();
        $bob = $this->contact('Bob', 'B');
        $this->service(NoteServiceInterface::class)->add(SubjectType::Contact, $bob, 'A', true);
        $this->service(NoteServiceInterface::class)->add(SubjectType::Contact, $bob, 'B', true);
        self::assertCount(2, $this->pinned($bob), 'as before 0.11.0');
    }

    public function testMentionsNotifyOnlyNewMembersWhoMayViewTheRecord(): void
    {
        $this->boot();
        $this->admin();
        $this->ctx()->owners['acme']['eva'] = 'Eva';
        $this->ctx()->emails['acme']        = ['leo' => 'leo@example.test'];
        $ada                                = $this->contact();
        $mentions                           = $this->service(Mentions::class);
        $tenant                             = new TenantRef('acme');
        $leo                                = $mentions->handle($tenant, 'leo');
        $eva                                = $mentions->handle($tenant, 'eva');
        self::assertNotSame($leo, $mentions->handle(new TenantRef('globex'), 'leo'), 'a handle is per tenant');
        self::assertMatchesRegularExpression('/\Au[0-9a-f]{24}\z/', $leo);
        $candidates = $this->service(MentionDirectoryInterface::class)->candidates(SubjectType::Contact, $ada, '');
        self::assertSame(['Ana', 'Eva', 'Leo'], array_map(static fn (MentionCandidate $c): string => $c->name, $candidates));
        self::assertSame([$leo], array_map(static fn (MentionCandidate $c): string => $c->handle, $this->service(MentionDirectoryInterface::class)->candidates(SubjectType::Contact, $ada, 'LEO@EXAMPLE')));
        self::assertSame([$leo => 'Leo'], $this->service(MentionDirectoryInterface::class)->names(SubjectType::Contact, $ada, [$leo, 'u' . str_repeat('0', 24), 'bad']));

        $sources = $this->sources();
        $notes   = $this->service(NoteServiceInterface::class);
        $note    = $notes->add(SubjectType::Contact, $ada, 'Hi @' . $leo . ' and @' . $mentions->handle($tenant, 'ana') . ' and @u' . str_repeat('f', 24));
        self::assertCount(1, $sources->sent);
        self::assertSame(['crm.mention', 'crm-note:' . $note, ['leo'], 'ana', 'contact:' . $ada], [$sources->sent[0]->type, $sources->sent[0]->target, array_map(static fn (ActorRef $a): string => $a->value, $sources->sent[0]->recipients), $sources->sent[0]->actor->value, $sources->sent[0]->subject->key()]);
        self::assertContains('NoteMentioned', $this->recorder()->eventNames());
        // An edit notifies only who is new; the same text again notifies nobody.
        $notes->edit($note, 'Hi @' . $leo . ' and @' . $eva, false, 1);
        $last = end($sources->sent);
        self::assertInstanceOf(CrmNotification::class, $last);
        self::assertSame(['eva'], array_map(static fn (ActorRef $a): string => $a->value, $last->recipients));
        $notes->edit($note, 'Hi @' . $leo . ' and @' . $eva . '!', false, 2);
        self::assertCount(2, $sources->sent);
        // Shown with names in the feed.
        $item = $this->feed(SubjectType::Contact, $ada, new TimelineFilter([FeedKind::Note]))->items[0];
        self::assertSame('Hi @Leo and @Eva!', $item->text);
        $this->expectThrows(AccessDeniedException::class, function (): void {
            $this->ctx()->grants['acme']['ana'] = ['view'];
            $this->service(MentionDirectoryInterface::class)->candidates(SubjectType::Contact, $this->contactIdOf('Ada'), '');
        });
    }

    public function testResourceScopingNarrowsTheMentionAudience(): void
    {
        $this->boot(['security' => ['resource_scoping' => true]]);
        $this->admin();
        $owned = $this->records()->create(new RecordInput(SubjectType::Contact, ['first_name' => 'Owned', 'last_name' => 'By Leo'], owner: 'leo'));
        self::assertSame(['Leo'], array_map(static fn (MentionCandidate $c): string => $c->name, $this->service(MentionDirectoryInterface::class)->candidates(SubjectType::Contact, $owned, '')));
        $ada = $this->contact();
        self::assertSame(['Ana', 'Leo'], array_map(static fn (MentionCandidate $c): string => $c->name, $this->service(MentionDirectoryInterface::class)->candidates(SubjectType::Contact, $ada, '')));
        $sources = $this->sources();
        $this->service(NoteServiceInterface::class)->add(SubjectType::Contact, $owned, 'cc @' . $this->service(Mentions::class)->handle(new TenantRef('acme'), 'leo'));
        self::assertCount(1, $sources->sent);
    }

    public function testGdprMergePurgeRetentionAndActorAnonymisation(): void
    {
        $this->boot();
        $this->admin();
        [$ada, $acme] = $this->network();
        $bob          = $this->contact('Bob', 'Builder');
        $at           = new DateTimeImmutable('2026-11-20 10:00', new DateTimeZone('UTC'));
        $call         = $this->engagements()->log(SubjectType::Contact, $ada, $this->call(body: 'Private notes'));
        $meeting      = $this->engagements()->log(SubjectType::Company, $acme, new EngagementInput(ActivityKind::Meeting, $at, 'Review', endsAt: $at->modify('+1 hour'), timezone: 'UTC', attendees: [MeetingAttendee::contact(new RecordId($ada)), MeetingAttendee::contact(new RecordId($bob)), MeetingAttendee::member(new ActorRef('ana'))]));
        $export       = $this->service(CrmPersonalDataInterface::class)->export($ada);
        /** @var list<array<string, mixed>> $activities */
        $activities = $export['activities'];
        /** @var list<array<string, mixed>> $attended */
        $attended = $export['meetings_attended'];
        self::assertSame([$call], array_map(static fn (array $r): mixed => $r['id'], $activities));
        self::assertSame('Private notes', $activities[0]['body'] ?? null);
        self::assertSame([$meeting], array_map(static fn (array $r): mixed => $r['id'], $attended));

        // Merge Bob into Ada: Bob's attendance of the meeting is the same as Ada's (dropped), his own call moves.
        $bobs = $this->engagements()->log(SubjectType::Contact, $bob, $this->email());
        $this->records()->affiliate($bob, $acme, null, false);
        $this->service(MergeServiceInterface::class)->merge(SubjectType::Contact, $ada, $bob, [], $this->int('SELECT version FROM nowo_crm_contact WHERE id = ?', [$ada]), $this->int('SELECT version FROM nowo_crm_contact WHERE id = ?', [$bob]));
        self::assertSame($ada, $this->text('SELECT subject_id FROM nowo_crm_engagement WHERE id = ?', [$bobs]));
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_engagement_attendee WHERE contact_id = ?', [$ada]));
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_engagement_attendee WHERE contact_id = ?', [$bob]));

        // Erasure: Ada's activities go; she leaves the company's meeting, which stays.
        $this->service(CrmPersonalDataInterface::class)->erase($ada);
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_engagement WHERE subject_id = ?', [$ada]));
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_engagement_subject WHERE subject_id = ?', [$ada]));
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_engagement_attendee WHERE contact_id = ?', [$ada]));
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_engagement WHERE id = ?', [$meeting]));

        // The account of a member is anonymised: the authors and the member attendees.
        self::assertGreaterThan(0, $this->service(CrmActorPersonalDataInterface::class)->anonymiseActor('ana'));
        self::assertSame(ActorPersonalData::PLACEHOLDER, $this->text('SELECT author_id FROM nowo_crm_engagement WHERE id = ?', [$meeting]));
        self::assertSame(0, $this->int("SELECT COUNT(*) FROM nowo_crm_engagement_attendee WHERE actor_id = 'ana'"));

        // Retention: older than the timeline's years, deleted; a purged company takes its activities along.
        $this->admin();
        $old    = $this->engagements()->log(SubjectType::Company, $acme, $this->call(at: new DateTimeImmutable('2019-01-01 10:00', new DateTimeZone('UTC'))));
        $counts = $this->service(RecordPurger::class)->purge(new TenantRef('acme'));
        self::assertSame(1, $counts['engagements'] ?? null);
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_engagement WHERE id = ?', [$old]));
        self::assertSame(0, $this->service(RecordPurger::class)->purge(new TenantRef('acme'), true)['engagements'] ?? null);
        $this->records()->trash(SubjectType::Company, $acme, $this->int('SELECT version FROM nowo_crm_company WHERE id = ?', [$acme]));
        $this->clock()->modify('+400 days');
        $this->service(RecordPurger::class)->purge(new TenantRef('acme'));
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_engagement'));
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_engagement_attendee'));
    }

    public function testDealsAndMeetingsForTheCalendar(): void
    {
        $this->boot();
        $this->admin();
        [$ada, $acme, , $deal] = $this->network();
        $at                    = new DateTimeImmutable('2026-11-20 10:00', new DateTimeZone('UTC'));
        $mine                  = $this->engagements()->log(SubjectType::Company, $acme, new EngagementInput(ActivityKind::Meeting, $at, 'Mine', endsAt: $at->modify('+1 hour'), timezone: 'UTC'));
        $invited               = $this->engagements()->log(SubjectType::Contact, $ada, new EngagementInput(ActivityKind::Meeting, $at->modify('+2 hour'), 'Invited', endsAt: $at->modify('+3 hour'), timezone: 'UTC', attendees: [MeetingAttendee::member(new ActorRef('leo'))]));
        $this->engagements()->log(SubjectType::Contact, $ada, new EngagementInput(ActivityKind::Meeting, $at, 'Cancelled', endsAt: $at->modify('+1 hour'), timezone: 'UTC', cancelled: true));
        $feed = $this->service(ActivityFeed::class);
        self::assertSame([$mine, $invited], array_map(static fn (EngagementView $m): string => $m->id, $feed->meetings($at->modify('-1 day'), $at->modify('+1 day'))));
        self::assertSame('Acme', $feed->meetings($at->modify('-1 day'), $at->modify('+1 day'))[0]->subjectLabel);
        $this->actAs('leo', 'view');
        self::assertSame([$invited], array_map(static fn (EngagementView $m): string => $m->id, $feed->meetings($at->modify('-1 day'), $at->modify('+1 day'))));
        $this->ctx()->denied[] = 'view:' . $ada;
        self::assertSame([], $feed->meetings($at->modify('-1 day'), $at->modify('+1 day')), 'a record the person may not view: no meeting');
        $this->ctx()->denied = [];
        $this->admin();
        // The deal's feed shows the associated activities; purging the deal drops the association only.
        self::assertCount(3, $this->feed(SubjectType::Deal, $deal)->items, 'the meetings of the company and of the contact');
        $this->expectThrows(NotAvailableException::class, fn (): Feed => $this->feed(SubjectType::Deal, 'nope'));
        $this->service(DealService::class)->trash($deal, 1);
        $this->clock()->modify('+400 days');
        $this->service(RecordPurger::class)->purge(new TenantRef('acme'));
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_engagement_subject WHERE subject_id = ?', [$deal]));
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_engagement WHERE id = ?', [$invited]));
    }

    public function testMergesKeepOneRowPerActivityAndTheStoreGuardsItsInputs(): void
    {
        $this->boot();
        $this->admin();
        [$ada, $acme, $globex] = $this->network();
        $bob                   = $this->contact('Bob', 'Builder');
        $call                  = $this->engagements()->log(SubjectType::Contact, $ada, $this->call());
        $at                    = new DateTimeImmutable('2026-11-20 10:00', new DateTimeZone('UTC'));
        $both                  = $this->engagements()->log(SubjectType::Company, $acme, new EngagementInput(ActivityKind::Meeting, $at, 'Both', endsAt: $at->modify('+1 hour'), timezone: 'UTC', attendees: [MeetingAttendee::contact(new RecordId($ada)), MeetingAttendee::contact(new RecordId($bob))]));
        $only                  = $this->engagements()->log(SubjectType::Company, $acme, new EngagementInput(ActivityKind::Meeting, $at, 'Bob only', endsAt: $at->modify('+1 hour'), timezone: 'UTC', attendees: [MeetingAttendee::contact(new RecordId($bob))]));
        // Globex into Acme: the call was associated with both; one row stays.
        $this->service(MergeServiceInterface::class)->merge(SubjectType::Company, $acme, $globex, [], $this->int('SELECT version FROM nowo_crm_company WHERE id = ?', [$acme]), $this->int('SELECT version FROM nowo_crm_company WHERE id = ?', [$globex]));
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_engagement_subject WHERE engagement_id = ? AND subject_id = ?', [$call, $acme]));
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_engagement_subject WHERE subject_id = ?', [$globex]));
        // Bob into Ada: his attendance of "Both" is dropped, of "Bob only" moved.
        $this->service(MergeServiceInterface::class)->merge(SubjectType::Contact, $ada, $bob, [], $this->int('SELECT version FROM nowo_crm_contact WHERE id = ?', [$ada]), $this->int('SELECT version FROM nowo_crm_contact WHERE id = ?', [$bob]));
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_engagement_attendee WHERE engagement_id = ?', [$both]));
        self::assertSame($ada, $this->text('SELECT contact_id FROM nowo_crm_engagement_attendee WHERE engagement_id = ?', [$only]));

        $store = $this->service(EngagementStoreInterface::class);
        $acmeT = new TenantRef('acme');
        self::assertSame([], $store->engagements($acmeT, []));
        self::assertSame([], $store->subjectsOf($acmeT, []));
        self::assertSame([], $store->attendeesOf($acmeT, []));
        self::assertNull($store->engagement(new TenantRef('globex'), $call));
        self::assertSame([], $this->service(MentionDirectoryInterface::class)->candidates(SubjectType::Contact, $ada, 'nobody-matches'));
    }

    public function testThePurgeCommandReportsDeletedActivities(): void
    {
        $this->boot();
        $this->admin();
        $acme = $this->company('Acme');
        $this->engagements()->log(SubjectType::Company, $acme, $this->call(at: new DateTimeImmutable('2019-01-01 10:00', new DateTimeZone('UTC'))));
        $application = new Application($this->kernel);
        $tester      = new CommandTester($application->find('nowo:crm-kit:purge'));
        $tester->execute([]);
        self::assertStringContainsString('logged activities: 1', $tester->getDisplay());
        $tester->execute([]);
        self::assertStringNotContainsString('logged activities', $tester->getDisplay());
    }

    /**
     * A contact with two companies, an open deal and a won deal.
     *
     * @return array{string, string, string, string, string}
     */
    private function network(): array
    {
        $ada    = $this->contact();
        $acme   = $this->company('Acme');
        $globex = $this->company('Globex');
        $this->records()->affiliate($ada, $acme, 'CTO', true);
        $this->records()->affiliate($ada, $globex, null, false);
        $funnel = $this->service(FunnelService::class)->create(FunnelKind::Deal, null, 'Sales');
        $deals  = $this->service(DealService::class);
        $open   = $deals->create(new DealInput('Engines', $funnel, companyId: $acme, contactId: $ada));
        $won    = $deals->create(new DealInput('Old one', $funnel, contactId: $ada));
        $this->connection()->executeStatement("UPDATE nowo_crm_deal SET status = 'won' WHERE id = ?", [$won]);

        return [$ada, $acme, $globex, $open, $won];
    }

    private function call(?DateTimeImmutable $at = null, ?string $outcome = null, ?int $duration = null, ?string $title = null, ?string $body = null): EngagementInput
    {
        return new EngagementInput(ActivityKind::Call, $at ?? new DateTimeImmutable('2026-11-18 09:00', new DateTimeZone('UTC')), $title, $body, ActivityDirection::Out, $outcome, $duration);
    }

    private function email(): EngagementInput
    {
        return new EngagementInput(ActivityKind::Email, new DateTimeImmutable('2026-11-18 09:30', new DateTimeZone('UTC')), 'Quote', 'We sent the quote.', ActivityDirection::Out);
    }

    private function sources(): TestActivitySources
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        $sources = $container->get('test.activities');
        self::assertInstanceOf(TestActivitySources::class, $sources);

        return $sources;
    }

    private function engagements(): EngagementServiceInterface
    {
        return $this->service(EngagementServiceInterface::class);
    }

    private function feed(SubjectType $type, string $id, ?TimelineFilter $filter = null, ?TimelineWindow $window = null): Feed
    {
        return $this->service(ActivityFeedInterface::class)->feed($type, $id, $filter ?? new TimelineFilter(), $window ?? new TimelineWindow());
    }

    /**
     * @return list<string>
     */
    private function pinned(string $contact): array
    {
        $ids = [];
        foreach ($this->connection()->fetchFirstColumn('SELECT id FROM nowo_crm_note WHERE subject_id = ? AND pinned = ? ORDER BY id', [$contact, true]) as $id) {
            $ids[] = is_scalar($id) ? (string) $id : '';
        }

        return $ids;
    }

    private function contactIdOf(string $first): string
    {
        return $this->text('SELECT id FROM nowo_crm_contact WHERE first_name = ?', [$first]);
    }

    /**
     * @param array<string, string> $errors
     * @param callable(): mixed $call
     */
    private function expectErrors(array $errors, callable $call): void
    {
        try {
            $call();
            self::fail('accepted');
        } catch (InvalidInputException $e) {
            self::assertSame($errors, $e->errors);
        }
    }

    /**
     * @param class-string<Throwable> $class
     * @param callable(): mixed $call
     */
    private function expectThrows(string $class, callable $call): void
    {
        try {
            $call();
            self::fail('no ' . $class);
        } catch (Throwable $e) {
            self::assertInstanceOf($class, $e);
        }
    }

    /**
     * @param callable(): mixed $call
     */
    private function expectConflictCode(string $code, callable $call): void
    {
        try {
            $call();
            self::fail('no conflict');
        } catch (ConflictException $e) {
            self::assertSame($code, $e->errorCode);
        }
    }
}
