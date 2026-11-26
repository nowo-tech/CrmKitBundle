<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Unit\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Nowo\CrmKitBundle\Domain\Enum\ActivityDirection;
use Nowo\CrmKitBundle\Domain\Enum\ActivityKind;
use Nowo\CrmKitBundle\Domain\Enum\FeedKind;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Model\Engagement\Engagement;
use Nowo\CrmKitBundle\Domain\Model\Engagement\EngagementAttendee;
use Nowo\CrmKitBundle\Domain\Model\Engagement\EngagementSubject;
use Nowo\CrmKitBundle\Domain\Model\Note;
use Nowo\CrmKitBundle\Domain\Value\ActivityDuration;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\CallOutcomeKey;
use Nowo\CrmKitBundle\Domain\Value\CrmNotification;
use Nowo\CrmKitBundle\Domain\Value\EmailExcerpt;
use Nowo\CrmKitBundle\Domain\Value\HostActivityItem;
use Nowo\CrmKitBundle\Domain\Value\IcsInvite;
use Nowo\CrmKitBundle\Domain\Value\MeetingAttendee;
use Nowo\CrmKitBundle\Domain\Value\MeetingPlace;
use Nowo\CrmKitBundle\Domain\Value\MeetingSlot;
use Nowo\CrmKitBundle\Domain\Value\MentionRef;
use Nowo\CrmKitBundle\Domain\Value\RecordId;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Nowo\CrmKitBundle\Domain\Value\TimelineFilter;
use Nowo\CrmKitBundle\Domain\Value\TimelineWindow;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * 0.11.0: the value objects, enums and entities of the logged activities.
 */
final class ActivityValuesTest extends TestCase
{
    private const ID = '0190a000-0000-7000-8000-000000000001';

    public function testKindsAndDirections(): void
    {
        self::assertTrue(ActivityKind::Email->isLoggable());
        self::assertFalse(ActivityKind::Sms->isLoggable());
        self::assertFalse(ActivityKind::NoteAdded->isLoggable());
        self::assertSame(ActivityKind::Meeting, FeedKind::Meeting->activityKind());
        self::assertSame(ActivityKind::Call, FeedKind::Call->activityKind());
        self::assertSame(ActivityKind::Email, FeedKind::Email->activityKind());
        self::assertNull(FeedKind::Note->activityKind());
        self::assertSame(FeedKind::Email, FeedKind::ofActivity(ActivityKind::Email));
        self::assertSame(FeedKind::Call, FeedKind::ofActivity(ActivityKind::Call));
        self::assertSame(FeedKind::Meeting, FeedKind::ofActivity(ActivityKind::Meeting));
        self::assertNull(FeedKind::ofActivity(ActivityKind::Created));
        self::assertSame('in', ActivityDirection::In->value);
    }

    public function testSmallValues(): void
    {
        self::assertSame('left_voicemail', (new CallOutcomeKey('left_voicemail'))->value);
        self::assertSame(1440, (new ActivityDuration(1440))->minutes);
        self::assertSame("Note\n with a line", (new EmailExcerpt(" Note\n with a line "))->value);
        foreach ([static fn (): CallOutcomeKey => new CallOutcomeKey('Bad'), static fn (): ActivityDuration => new ActivityDuration(-1), static fn (): ActivityDuration => new ActivityDuration(1441), static fn (): EmailExcerpt => new EmailExcerpt(' '), static fn (): EmailExcerpt => new EmailExcerpt(str_repeat('x', 501)), static fn (): EmailExcerpt => new EmailExcerpt("a\x07b"), static fn (): EmailExcerpt => new EmailExcerpt("a\u{202E}b"), static fn (): EmailExcerpt => new EmailExcerpt("\xFF")] as $bad) {
            $this->refused($bad);
        }
    }

    public function testMeetingSlotAndPlace(): void
    {
        $slot = new MeetingSlot(new DateTimeImmutable('2030-03-04 10:00', new DateTimeZone('Europe/Madrid')), new DateTimeImmutable('2030-03-04 11:30', new DateTimeZone('Europe/Madrid')), 'Europe/Madrid');
        self::assertSame('2030-03-04 09:00', $slot->start->format('Y-m-d H:i'));
        self::assertSame('UTC', $slot->end->getTimezone()->getName());
        self::assertSame(90, $slot->minutes());
        self::assertTrue(MeetingSlot::isZone('UTC'));
        self::assertFalse(MeetingSlot::isZone('Europe/madrid'));
        self::assertFalse(MeetingSlot::isZone('+02:00'));
        self::assertFalse(MeetingSlot::isZone('CET'));
        self::assertFalse(MeetingSlot::isZone('Nowhere/City'));
        $at = new DateTimeImmutable('2030-03-04 10:00');
        $this->refused(static fn (): MeetingSlot => new MeetingSlot($at, $at, 'UTC'));
        $this->refused(static fn (): MeetingSlot => new MeetingSlot($at, $at->modify('+8 days'), 'UTC'));
        $this->refused(static fn (): MeetingSlot => new MeetingSlot($at, $at->modify('+1 hour'), 'CET'));
        $place = new MeetingPlace(' https://meet.example.test/abc ');
        self::assertSame('https://meet.example.test/abc', $place->value);
        self::assertTrue($place->isUrl());
        self::assertFalse((new MeetingPlace('Room 4'))->isUrl());
        self::assertFalse(MeetingPlace::isHttpsUrl('https://user:pw@example.test'));
        self::assertFalse(MeetingPlace::isHttpsUrl('https:///path'));
        self::assertFalse(MeetingPlace::isHttpsUrl('https://a b.test'));
        foreach (['', str_repeat('x', 201), "a\x00b", 'http://example.test', 'javascript://x', "a\u{2028}b", "\xFF"] as $bad) {
            $this->refused(static fn (): MeetingPlace => new MeetingPlace($bad));
        }
        $member  = MeetingAttendee::member(new ActorRef('ana'));
        $contact = MeetingAttendee::contact(new RecordId(self::ID));
        self::assertSame('actor:ana', $member->key());
        self::assertSame('contact:' . self::ID, $contact->key());
        self::assertNull($member->contact);
        self::assertNull($contact->actor);
    }

    public function testFilterAndWindow(): void
    {
        $filter = new TimelineFilter([FeedKind::Call, FeedKind::Call, FeedKind::Note], new ActorRef('ana'));
        self::assertSame([FeedKind::Call, FeedKind::Note], $filter->kinds);
        self::assertTrue($filter->shows(FeedKind::Note));
        self::assertFalse($filter->shows(FeedKind::Host));
        self::assertTrue($filter->by('ana'));
        self::assertFalse($filter->by('leo'));
        self::assertFalse($filter->by(null));
        $all = new TimelineFilter();
        self::assertTrue($all->shows(FeedKind::Host));
        self::assertTrue($all->by(null));
        $at     = new DateTimeImmutable('2030-03-04 10:00:00.250000', new DateTimeZone('UTC'));
        $window = TimelineWindow::after(TimelineWindow::cursor($at, 'b'), 5);
        self::assertSame(5, $window->limit);
        self::assertSame('b', $window->beforeId);
        self::assertSame($at->format('U.u'), $window->before?->format('U.u'));
        self::assertTrue($window->admits($at->modify('-1 second'), 'z'));
        self::assertTrue($window->admits($at, 'a'));
        self::assertFalse($window->admits($at, 'b'));
        self::assertFalse($window->admits($at, 'c'));
        self::assertFalse($window->admits($at->modify('+1 second'), 'a'));
        self::assertFalse((new TimelineWindow(5, $at))->admits($at, 'a'), 'no id: only earlier times');
        self::assertTrue((new TimelineWindow())->admits($at, 'x'));
        foreach ([null, '', str_repeat('a', 301), '***', 'bm8tcGlwZQ', 'eHw', 'MTIzfA', 'YWJjfGI'] as $bad) {
            self::assertNull(TimelineWindow::after($bad)->before, (string) $bad);
        }
        self::assertNull(TimelineWindow::after(TimelineWindow::cursor($at, str_repeat('i', 129)))->before);
        $this->refused(static fn (): TimelineWindow => new TimelineWindow(0));
        $this->refused(static fn (): TimelineWindow => new TimelineWindow(101));
    }

    public function testHostItemsMentionsNotificationsAndInvites(): void
    {
        $subject = SubjectRef::of(SubjectType::Contact, self::ID);
        $at      = new DateTimeImmutable('2030-03-04 10:00');
        $item    = new HostActivityItem($subject, 'task-1', 'Call back', $at, '/tasks/1', new ActorRef('ana'), 'tabler:checkbox');
        self::assertSame('/tasks/1', $item->url);
        self::assertTrue(HostActivityItem::safeUrl('https://example.test/x'));
        foreach (['', '//evil.test', 'javascript:alert(1)', '/a b', '/a\\b', 'http://example.test', str_repeat('/a', 1100)] as $url) {
            self::assertFalse(HostActivityItem::safeUrl($url), $url);
        }
        foreach ([
            static fn (): HostActivityItem => new HostActivityItem($subject, '', 'Title', $at),
            static fn (): HostActivityItem => new HostActivityItem($subject, 'id', "Ti\u{202E}tle", $at),
            static fn (): HostActivityItem => new HostActivityItem($subject, 'id', str_repeat('t', 201), $at),
            static fn (): HostActivityItem => new HostActivityItem($subject, 'id', "\xFF", $at),
            static fn (): HostActivityItem => new HostActivityItem($subject, 'id', 'Title', $at, '//evil.test'),
            static fn (): HostActivityItem => new HostActivityItem($subject, 'id', 'Title', $at, null, null, 'Bad Icon'),
        ] as $bad) {
            $this->refused($bad);
        }
        self::assertSame('u' . str_repeat('a', 24), (new MentionRef(new ActorRef('ana'), 'u' . str_repeat('a', 24)))->handle);
        $this->refused(static fn (): MentionRef => new MentionRef(new ActorRef('ana'), 'ana'));
        $note = new CrmNotification(CrmNotification::MENTION, new TenantRef('acme'), [new ActorRef('leo')], new ActorRef('ana'), $subject, 'crm-note:' . self::ID);
        self::assertSame('crm.mention', $note->type);
        $this->refused(static fn (): CrmNotification => new CrmNotification('Bad', new TenantRef('acme'), [], new ActorRef('ana'), $subject, 't'));
        $this->refused(static fn (): CrmNotification => new CrmNotification('crm.mention', new TenantRef('acme'), [], new ActorRef('ana'), $subject, 'bad target'));
        $invite = new IcsInvite('x@example.test', 1, 'PUBLISH', $at, $at->modify('+1 hour'), 'Review', null, 'https://example.test/crm', false, $at);
        self::assertSame(1, $invite->sequence);
        foreach ([
            static fn (): IcsInvite => new IcsInvite('bad uid', 0, 'PUBLISH', $at, $at->modify('+1 hour'), 'R', null, null, false, $at),
            static fn (): IcsInvite => new IcsInvite('x@y', -1, 'PUBLISH', $at, $at->modify('+1 hour'), 'R', null, null, false, $at),
            static fn (): IcsInvite => new IcsInvite('x@y', 0, 'ADD', $at, $at->modify('+1 hour'), 'R', null, null, false, $at),
            static fn (): IcsInvite => new IcsInvite('x@y', 0, 'PUBLISH', $at, $at, 'R', null, null, false, $at),
            static fn (): IcsInvite => new IcsInvite('x@y', 0, 'PUBLISH', $at, $at->modify('+1 hour'), 'R', null, 'javascript:x', false, $at),
        ] as $bad) {
            $this->refused($bad);
        }
    }

    public function testEntities(): void
    {
        $at = new DateTimeImmutable('2030-03-04 10:00');
        $e  = new Engagement(self::ID, 'acme', ActivityKind::Meeting, SubjectType::Contact, 'c1', $at, 'ana', $at);
        $e->describe($at, 'Review', 'Notes', null, null, null, $at->modify('+1 hour'), 'UTC', 'Room', false, $at, true);
        self::assertSame(0, $e->getSequence(), 'the first write is sequence 0');
        $e->describe($at, 'Review', 'Other notes', null, null, null, $at->modify('+1 hour'), 'UTC', 'Room', false, $at);
        self::assertSame(0, $e->getSequence(), 'the notes do not move the sequence');
        $e->describe($at, 'Review', 'Other notes', null, null, null, $at->modify('+2 hour'), 'UTC', 'Room', true, $at->modify('+1 minute'));
        self::assertSame(1, $e->getSequence());
        self::assertTrue($e->isCancelled());
        self::assertSame(['acme', 'c1', 'Review', 'Other notes', 'Room', 'UTC', 'ana', 1], [$e->getTenantId(), $e->getSubjectId(), $e->getTitle(), $e->getBody(), $e->getPlace(), $e->getTimezone(), $e->getAuthorId(), $e->getVersion()]);
        self::assertSame(ActivityKind::Meeting, $e->getKind());
        self::assertSame(SubjectType::Contact, $e->getSubjectType());
        self::assertEquals($at->modify('+2 hour'), $e->getEndsAt());
        self::assertEquals($at, $e->getOccurredAt());
        self::assertEquals($at, $e->getCreatedAt());
        self::assertEquals($at->modify('+1 minute'), $e->getUpdatedAt());
        self::assertNull($e->getDirection());
        self::assertNull($e->getOutcome());
        self::assertNull($e->getDurationMinutes());
        $e->moveTo('c2');
        $e->changeAuthor(null);
        self::assertSame(['c2', null], [$e->getSubjectId(), $e->getAuthorId()]);
        $call = new Engagement('id2', 'acme', ActivityKind::Call, SubjectType::Company, 'co', $at, 'ana', $at);
        $call->describe($at->modify('+1 day'), null, null, ActivityDirection::In, 'busy', 3, null, null, null, false, $at);
        self::assertSame([0, ActivityDirection::In, 'busy', 3, 'id2'], [$call->getSequence(), $call->getDirection(), $call->getOutcome(), $call->getDurationMinutes(), $call->getId()]);
        $s = new EngagementSubject('s1', 'acme', 'id2', SubjectType::Deal, 'd1', false);
        $s->moveTo('d2');
        self::assertSame(['s1', 'acme', 'id2', SubjectType::Deal, 'd2', false], [$s->getId(), $s->getTenantId(), $s->getEngagementId(), $s->getSubjectType(), $s->getSubjectId(), $s->isPrimary()]);
        $a = new EngagementAttendee('a1', 'acme', 'id2', null, 'c1');
        $a->moveTo('c3');
        self::assertSame(['a1', 'acme', 'id2', null, 'c3'], [$a->getId(), $a->getTenantId(), $a->getEngagementId(), $a->getActorId(), $a->getContactId()]);
        $note = new Note('n1', 'acme', SubjectType::Contact, 'c1', 'Body', false, 'ana', $at);
        $note->pin(true, $at->modify('+1 minute'));
        self::assertTrue($note->isPinned());
        self::assertEquals($at->modify('+1 minute'), $note->getUpdatedAt());
    }

    /**
     * @param callable(): mixed $call
     */
    private function refused(callable $call): void
    {
        try {
            $call();
            self::fail('accepted');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }
}
