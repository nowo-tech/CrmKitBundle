<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\CrmKitBundle\Application\CrmAccess;
use Nowo\CrmKitBundle\Application\Engagement\Mentions;
use Nowo\CrmKitBundle\Bridge\NotifyKit\NotifyKitCrmNotifier;
use Nowo\CrmKitBundle\Bridge\TaskBoard\CrmMeetingCalendarSource;
use Nowo\CrmKitBundle\Domain\Enum\ActivityDirection;
use Nowo\CrmKitBundle\Domain\Enum\ActivityKind;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Port\CrmNotifierInterface;
use Nowo\CrmKitBundle\Domain\Service\ActivityFeedInterface;
use Nowo\CrmKitBundle\Domain\Service\CrmPersonalDataInterface;
use Nowo\CrmKitBundle\Domain\Service\CrmReaderInterface;
use Nowo\CrmKitBundle\Domain\Service\EngagementInput;
use Nowo\CrmKitBundle\Domain\Service\EngagementServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\NoteServiceInterface;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\MeetingAttendee;
use Nowo\CrmKitBundle\Domain\Value\RecordId;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Controller\ActivityRecordBlock;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Controller\PipelineDialogs;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Controller\UiSupport;
use Nowo\NotifyKitBundle\Contract\NotificationRequest;
use Nowo\NotifyKitBundle\Contract\NotifierInterface;
use Nowo\NotifyKitBundle\Contract\NotifyResult;
use Nowo\TaskBoardBundle\Calendar\CalendarDateRange;
use Nowo\TaskBoardBundle\Calendar\CalendarEventRange;
use Nowo\TaskBoardBundle\Calendar\CalendarViewer;
use Nowo\UiKitBundle\View\Action;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

use function array_replace_recursive;
use function iterator_to_array;
use function json_decode;
use function mb_strlen;
use function str_repeat;

/**
 * 0.11.0 (Beacon spec 254, A-1): the activity block of the 360° page, the log / edit dialogs loaded on demand, delete,
 * pin, the `.ics` download, the mention endpoints, the TaskBoard calendar source and the NotifyKit wiring.
 */
final class ActivitiesUiTest extends CrmTestCase
{
    private const DIALOG = ['HTTP_X_NOWO_DIALOG' => '1'];

    /**
     * @param array<string, mixed> $config
     */
    protected function boot(array $config = []): void
    {
        /** @var array<string, mixed> $merged */
        $merged = array_replace_recursive(['activities' => ['enabled' => true, 'notifier' => 'test.activities']], $config);
        parent::boot($merged);
    }

    public function testTheRecordPageOpensTheLogDialogsOnDemand(): void
    {
        $this->boot();
        $this->admin();
        $ada  = $this->contact();
        $page = $this->client->request('GET', '/crm/contacts/' . $ada);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $page->filter('#crm-activities'));
        self::assertCount(0, $page->filter('form[name="crm_activity"]'), 'no log form in the page');
        self::assertCount(1, $page->filter('dialog#' . PipelineDialogs::ACTIVITY_DIALOG));
        foreach (['call', 'meeting', 'email'] as $kind) {
            $opener = $page->filter('[data-testid="crm-activity-log-' . $kind . '"]');
            self::assertSame('/crm/contacts/' . $ada . '/activities/' . $kind, $opener->attr('href'));
            self::assertSame(PipelineDialogs::ACTIVITY_DIALOG, $opener->attr('data-nowo-ui-dialog-open'));
        }
        self::assertStringContainsString('No activities yet', $page->filter('#crm-activities')->text());
        $suggestions = json_decode((string) $page->filter('form[name="crm_note"] textarea')->attr('data-suggestions'), true);
        self::assertIsArray($suggestions);
        self::assertSame('/crm/contacts/' . $ada . '/mentions', $suggestions['@'] ?? null);
        self::assertSame('name', $page->filter('form[name="crm_note"] textarea')->attr('data-mention-display'));

        // Log a call in the dialog: 422 without a direction, then 204.
        $fragment = $this->client->request('GET', '/crm/contacts/' . $ada . '/activities/call', [], [], self::DIALOG);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('<html', (string) $this->client->getResponse()->getContent());
        $form = $fragment->filter('form[name="crm_activity"]')->form();
        $this->client->submit($form, ['crm_activity[occurred_at]' => '2026-11-18T09:30', 'crm_activity[duration]' => '9999'], self::DIALOG);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->client->submit($form, ['crm_activity[occurred_at]' => '2026-11-18T09:30', 'crm_activity[direction]' => 'in', 'crm_activity[outcome]' => 'busy', 'crm_activity[duration]' => '5', 'crm_activity[title]' => 'Called back', 'crm_activity[body]' => 'Wants a demo'], self::DIALOG);
        self::assertSame(204, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        self::assertSame('/crm/contacts/' . $ada, $this->client->getResponse()->headers->get('X-Nowo-Redirect'));

        // Log an e-mail and a meeting on the no-JS pages (303).
        $full = $this->client->request('GET', '/crm/contacts/' . $ada . '/activities/email');
        self::assertStringContainsString('<html', (string) $this->client->getResponse()->getContent());
        $this->client->submit($full->filter('form[name="crm_activity"]')->form(), ['crm_activity[occurred_at]' => '2026-11-18T10:00', 'crm_activity[direction]' => 'out', 'crm_activity[title]' => 'Quote', 'crm_activity[body]' => 'Sent the quote']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $full = $this->client->request('GET', '/crm/contacts/' . $ada . '/activities/meeting');
        $this->client->submit($full->filter('form[name="crm_activity"]')->form(), ['crm_activity[title]' => 'Demo', 'crm_activity[occurred_at]' => '2026-11-20T10:00', 'crm_activity[ends_at]' => '2026-11-20T11:00', 'crm_activity[timezone]' => 'Europe/Madrid', 'crm_activity[place]' => 'https://meet.example.test/demo', 'crm_activity[members]' => ['leo'], 'crm_activity[contacts]' => [$ada]]);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        $meeting = $this->text("SELECT id FROM nowo_crm_engagement WHERE kind = 'meeting'");
        self::assertSame('2026-11-20 09:00:00', $this->text('SELECT occurred_at FROM nowo_crm_engagement WHERE id = ?', [$meeting]), 'read in the meeting\'s zone, stored in UTC');

        $page = $this->client->request('GET', '/crm/contacts/' . $ada);
        $feed = $page->filter('[data-testid="crm-feed-item"]');
        self::assertCount(3, $feed);
        self::assertSame(['meeting', 'email', 'call'], $feed->each(static fn ($n): string => (string) $n->attr('data-kind')));
        self::assertStringContainsString('Wants a demo', $page->filter('#crm-activities')->text());
        self::assertSame('https://meet.example.test/demo', $page->filter('#crm-activities a[rel="noopener noreferrer"]')->attr('href'));
        self::assertCount(1, $page->filter('[data-testid="crm-activity-ics"]'));
        self::assertCount(3, $page->filter('[data-testid="crm-activity-edit"]'));

        // The filter (GET) and its clear link.
        $filtered = $this->client->request('GET', '/crm/contacts/' . $ada . '?activity_kind=call&activity_person=ana');
        self::assertCount(1, $filtered->filter('[data-testid="crm-feed-item"]'));
        self::assertCount(1, $filtered->filter('[data-testid="crm-activity-filter-clear"]'));
        $none = $this->client->request('GET', '/crm/contacts/' . $ada . '?activity_kind=host&activity_person=stranger');
        self::assertStringContainsString('No activities match the filter', $none->filter('#crm-activities')->text());
    }

    public function testEditDeleteIcsAndPin(): void
    {
        $this->boot();
        $this->admin();
        $ada     = $this->contact();
        $at      = new DateTimeImmutable('2026-11-20 10:00', new DateTimeZone('Europe/Madrid'));
        $meeting = $this->engagements()->log(SubjectType::Contact, $ada, new EngagementInput(ActivityKind::Meeting, $at, 'Demo; Q1', 'Agenda', endsAt: $at->modify('+1 hour'), timezone: 'Europe/Madrid', place: 'Room 1', attendees: [MeetingAttendee::contact(new RecordId($ada)), MeetingAttendee::member(new ActorRef('leo'))]));
        $call    = $this->engagements()->log(SubjectType::Contact, $ada, new EngagementInput(ActivityKind::Call, $at, null, null, ActivityDirection::Out));

        $fragment = $this->client->request('GET', '/crm/activities/' . $meeting . '/edit', [], [], self::DIALOG);
        self::assertSame('2026-11-20T10:00', $fragment->filter('input[name="crm_activity[occurred_at]"]')->attr('value'), 'in the meeting\'s zone');
        self::assertSame('1', $fragment->filter('input[name="crm_activity[version]"]')->attr('value'));
        $form = $fragment->filter('form[name="crm_activity"]')->form();
        $this->engagements()->edit($meeting, new EngagementInput(ActivityKind::Meeting, $at, 'Demo; Q1', 'Moved', endsAt: $at->modify('+2 hour'), timezone: 'Europe/Madrid'), 1);
        $this->client->submit($form, ['crm_activity[place]' => 'Room 2'], self::DIALOG);
        self::assertSame(422, $this->client->getResponse()->getStatusCode(), 'stale version: in place');
        self::assertStringContainsString('same moment', (string) $this->client->getResponse()->getContent());
        $fragment = $this->client->request('GET', '/crm/activities/' . $meeting . '/edit', [], [], self::DIALOG);
        $this->client->submit($fragment->filter('form[name="crm_activity"]')->form(), ['crm_activity[place]' => 'Room 2', 'crm_activity[cancelled]' => '1'], self::DIALOG);
        self::assertSame(204, $this->client->getResponse()->getStatusCode());
        $full = $this->client->request('GET', '/crm/activities/' . $call . '/edit');
        $this->client->submit($full->filter('form[name="crm_activity"]')->form(), ['crm_activity[title]' => 'Short call']);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        self::assertSame('Short call', $this->text('SELECT title FROM nowo_crm_engagement WHERE id = ?', [$call]));

        // The .ics download: a cancelled meeting, sequence 2, in UTC, no notes.
        $this->client->request('GET', '/crm/activities/' . $meeting . '/meeting.ics');
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/calendar', (string) $response->headers->get('Content-Type'));
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $ics = (string) $response->getContent();
        self::assertStringContainsString('UID:' . $meeting . '@localhost', $ics);
        self::assertStringContainsString('SEQUENCE:2', $ics);
        self::assertStringContainsString('STATUS:CANCELLED', $ics);
        self::assertStringContainsString('SUMMARY:Demo\; Q1', $ics);
        self::assertStringContainsString('LOCATION:Room 2', $ics);
        self::assertStringNotContainsString('Moved', $ics);
        self::assertSame(404, $this->httpStatus('GET', '/crm/activities/' . $call . '/meeting.ics'));
        self::assertSame(404, $this->httpStatus('GET', '/crm/activities/0190a000-0000-7000-8000-00000000dead/meeting.ics'));
        $this->ctx()->denied[] = 'view:' . $ada;
        self::assertSame(403, $this->httpStatus('GET', '/crm/activities/' . $meeting . '/meeting.ics'));
        $this->ctx()->denied = [];

        // Pin from the notes card.
        $note  = $this->service(NoteServiceInterface::class)->add(SubjectType::Contact, $ada, 'Remember');
        $page  = $this->client->request('GET', '/crm/contacts/' . $ada);
        $token = $this->actionToken('/crm/contacts/' . $ada);
        self::assertCount(1, $page->filter('[data-testid="crm-note-pin"]'));
        $this->client->request('POST', '/crm/notes/' . $note . '/pin/on?version=1&type=contacts&subject=' . $ada, ['crm_action' => ['_token' => $token]]);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_note WHERE pinned = ?', [true]));
        $this->client->request('POST', '/crm/notes/' . $note . '/pin/off?version=1&type=contacts&subject=' . $ada, ['crm_action' => ['_token' => $token]]);
        self::assertSame(303, $this->client->getResponse()->getStatusCode(), 'stale: a flash and back');
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_note WHERE pinned = ?', [true]));
        $this->client->request('POST', '/crm/notes/' . $note . '/pin/off?version=2', ['crm_action' => ['_token' => $token]]);
        self::assertSame('/crm', $this->client->getResponse()->headers->get('Location'), 'no record named: the CRM home');
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_note WHERE pinned = ?', [true]));

        // Delete: without the token a flash; with it, gone.
        $this->client->request('POST', '/crm/activities/' . $call . '/delete?type=contacts&subject=' . $ada);
        self::assertSame(1, $this->int('SELECT COUNT(*) FROM nowo_crm_engagement WHERE id = ?', [$call]));
        $this->client->request('POST', '/crm/activities/' . $call . '/delete?type=contacts&subject=' . $ada, ['crm_action' => ['_token' => $token]]);
        self::assertSame('/crm/contacts/' . $ada, $this->client->getResponse()->headers->get('Location'));
        self::assertSame(0, $this->int('SELECT COUNT(*) FROM nowo_crm_engagement WHERE id = ?', [$call]));
    }

    public function testRecordStatesPermissionsPagesAndMentionEndpoints(): void
    {
        $this->boot();
        $this->admin();
        $ada = $this->contact();
        $bob = $this->contact('Bob', 'B');
        for ($i = 0; $i < 21; ++$i) {
            $this->engagements()->log(SubjectType::Contact, $ada, new EngagementInput(ActivityKind::Email, new DateTimeImmutable('2026-11-01 08:00', new DateTimeZone('UTC')), 'Mail ' . $i, null, ActivityDirection::In));
        }
        $page  = $this->client->request('GET', '/crm/contacts/' . $ada);
        $older = $page->filter('[data-testid="crm-feed-older"]')->attr('href');
        self::assertIsString($older);
        self::assertCount(20, $page->filter('[data-testid="crm-feed-item"]'));
        self::assertCount(1, $this->client->request('GET', $older)->filter('[data-testid="crm-feed-item"]'));

        // Mentions: the candidates and the names, JSON, private.
        $this->client->request('GET', '/crm/contacts/' . $ada . '/mentions?q=le');
        self::assertSame('no-store, private', $this->client->getResponse()->headers->get('Cache-Control'));
        $leo = $this->service(Mentions::class)->handle(new TenantRef('acme'), 'leo');
        self::assertSame([['id' => $leo, 'label' => 'Leo']], json_decode((string) $this->client->getResponse()->getContent(), true));
        $this->client->request('GET', '/crm/contacts/' . $ada . '/mentions/names?handles=' . $leo . ',bad,' . str_repeat('u', 30));
        self::assertSame([['handle' => $leo, 'name' => 'Leo']], json_decode((string) $this->client->getResponse()->getContent(), true));
        $this->client->request('GET', '/crm/contacts/' . $ada . '/mentions');
        self::assertCount(2, json_decode((string) $this->client->getResponse()->getContent(), true));

        // Read only: no openers, no edit; the dialog page is 403.
        $this->actAs('leo', 'view');
        $page = $this->client->request('GET', '/crm/contacts/' . $ada);
        self::assertCount(0, $page->filter('[data-testid^="crm-activity-log-"]'));
        self::assertCount(0, $page->filter('[data-testid="crm-activity-edit"]'));
        self::assertSame(403, $this->httpStatus('GET', '/crm/contacts/' . $ada . '/activities/call'));
        self::assertSame(403, $this->httpStatus('GET', '/crm/contacts/' . $ada . '/mentions'));
        $this->admin();
        self::assertSame(404, $this->httpStatus('GET', '/crm/contacts/0190a000-0000-7000-8000-00000000dead/activities/call'));
        self::assertSame(404, $this->httpStatus('GET', '/crm/activities/0190a000-0000-7000-8000-00000000dead/edit'));

        // A trashed record redirects its forms to the record; an erased contact has no feed.
        $this->records()->trash(SubjectType::Contact, $bob, 1);
        $this->client->request('GET', '/crm/contacts/' . $bob . '/activities/call');
        self::assertSame('/crm/contacts/' . $bob, $this->client->getResponse()->headers->get('Location'));
        $one = $this->text('SELECT id FROM nowo_crm_engagement LIMIT 1');
        $this->records()->trash(SubjectType::Contact, $ada, 1);
        $this->client->request('GET', '/crm/activities/' . $one . '/edit');
        self::assertSame('/crm/contacts/' . $ada, $this->client->getResponse()->headers->get('Location'));
        $acme = $this->company('Acme');
        $dora = $this->contact('Dora', 'D');
        $this->records()->affiliate($dora, $acme, null, true);
        $company = $this->client->request('GET', '/crm/companies/' . $acme . '/activities/meeting');
        self::assertCount(1, $company->filter('input[name="crm_activity[contacts][]"][value="' . $dora . '"]'), 'the company\'s contacts as attendees');
        $carl = $this->contact('Carl', 'C');
        $this->service(CrmPersonalDataInterface::class)->erase($carl);
        self::assertCount(0, $this->client->request('GET', '/crm/contacts/' . $carl)->filter('#crm-activities'));
    }

    public function testWithoutMentionsOrActivitiesOrUiKitDialogs(): void
    {
        $this->boot(['activities' => ['mentions' => ['enabled' => false]]]);
        $this->admin();
        $ada  = $this->contact();
        $page = $this->client->request('GET', '/crm/contacts/' . $ada);
        self::assertNull($page->filter('form[name="crm_note"] textarea')->attr('data-suggestions'), 'a plain text area');
        self::assertSame(404, $this->httpStatus('GET', '/crm/contacts/' . $ada . '/mentions'));
        self::assertSame(404, $this->httpStatus('GET', '/crm/contacts/' . $ada . '/mentions/names'));

        // Without UiKit's remote dialog responder the openers are plain links to the form pages.
        $this->boot();
        $this->admin();
        $ada = $this->contact();
        $this->engagements()->log(SubjectType::Contact, $ada, new EngagementInput(ActivityKind::Call, new DateTimeImmutable(), null, null, ActivityDirection::Out));
        $ui      = $this->service(UiSupport::class);
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stack = $this->kernel->getContainer()->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $stack);
        $stack->push($request);
        $block = new ActivityRecordBlock($ui, $this->service(ActivityFeedInterface::class), $this->service(CrmAccess::class), $this->service(CrmReaderInterface::class), new PipelineDialogs($ui, null, true, PipelineDialogs::ACTIVITY_DIALOG), new RequestStack());
        /** @var array{dialog: mixed, openers: list<Action>, texts: array<string, string>, items: list<array{buttons: list<Action>}>} $vars */
        $vars = $block->variables($this->service(CrmReaderInterface::class)->record(SubjectType::Contact, $ada), true, []);
        self::assertNull($vars['dialog']);
        self::assertSame('/crm/contacts/' . $ada . '/activities/call', $vars['openers'][0]->href);
        self::assertArrayNotHasKey('data-nowo-ui-dialog-open', $vars['openers'][0]->attributes);
        self::assertSame([], $vars['texts']);
        self::assertSame('/crm/activities/' . $this->text('SELECT id FROM nowo_crm_engagement') . '/edit', $vars['items'][0]['buttons'][0]->href);
        self::assertNull($block->noteEditor($this->service(CrmReaderInterface::class)->record(SubjectType::Contact, $ada)));
        $stack->pop();

        parent::boot();
        $this->admin();
        $ada = $this->contact();
        self::assertCount(0, $this->client->request('GET', '/crm/contacts/' . $ada)->filter('#crm-activities'));
        self::assertSame(404, $this->httpStatus('GET', '/crm/contacts/' . $ada . '/activities/call'));
    }

    public function testTheCalendarSourceAndTheNotifyKitBridge(): void
    {
        $sent = new class implements NotifierInterface {
            /** @var list<NotificationRequest> */
            public array $requests = [];

            public function notify(NotificationRequest $request): NotifyResult
            {
                $this->requests[] = $request;

                return new NotifyResult(1);
            }
        };
        $this->bootWithUiKit(['activities' => ['enabled' => true]], [], static function (ServicesConfigurator $services) use ($sent): void {
            $services->set(NotifierInterface::class, $sent::class)->public();
        });
        $this->admin();
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        self::assertInstanceOf(NotifyKitCrmNotifier::class, $container->get(CrmNotifierInterface::class));
        $notifier = $container->get(NotifierInterface::class);
        self::assertInstanceOf($sent::class, $notifier);
        $ada = $this->contact();
        $leo = $this->service(Mentions::class)->handle(new TenantRef('acme'), 'leo');
        $this->service(NoteServiceInterface::class)->add(SubjectType::Contact, $ada, 'cc @' . $leo);
        self::assertCount(1, $notifier->requests);
        self::assertSame(['crm.mention', '/crm/contacts/' . $ada, 'user', 'leo'], [$notifier->requests[0]->type, $notifier->requests[0]->url, $notifier->requests[0]->recipients[0]->type, $notifier->requests[0]->recipients[0]->id]);

        // TaskBoard's calendar: the meetings of the signed-in person, read-only, on the CRM's tenant only.
        $at = new DateTimeImmutable('2026-11-20 10:00', new DateTimeZone('UTC'));
        $this->engagements()->log(SubjectType::Contact, $ada, new EngagementInput(ActivityKind::Meeting, $at, 'Demo', endsAt: $at->modify('+1 hour'), timezone: 'UTC'));
        $this->engagements()->log(SubjectType::Contact, $ada, new EngagementInput(ActivityKind::Meeting, $at, str_repeat('Long ', 39), endsAt: $at->modify('+1 hour'), timezone: 'UTC'));
        $source = $container->get(CrmMeetingCalendarSource::class);
        self::assertInstanceOf(CrmMeetingCalendarSource::class, $source);
        self::assertSame('crm-meetings', $source->key());
        $range  = new CalendarEventRange(new CalendarDateRange('2026-11-16', '2026-11-22'), 'UTC');
        $events = iterator_to_array($source->events(new CalendarViewer('1', 'board', 'acme'), $range));
        self::assertCount(2, $events);
        self::assertSame(['Demo · Ada Lovelace', 'acme', '/crm/contacts/' . $ada . '#crm-activities', true, 'crm-meeting'], [$events[0]->title, $events[0]->tenantKey, $events[0]->url, $events[0]->readOnly, $events[0]->category]);
        self::assertSame(200, mb_strlen($events[1]->title));
        self::assertCount(2, iterator_to_array($source->events(new CalendarViewer('1', 'board'), $range)));
        self::assertSame([], iterator_to_array($source->events(new CalendarViewer('1', 'board', 'globex'), $range)), 'another tenant\'s board');
        $this->ctx()->tenant = null;
        self::assertSame([], iterator_to_array($source->events(new CalendarViewer('1', 'board', 'acme'), $range)), 'the CRM is not available');

        // Without the CRM's pages the events have no link.
        $this->bootWithUiKit(['ui' => ['enabled' => false], 'activities' => ['enabled' => true]]);
        $this->admin();
        $bob = $this->contact('Bob', 'B');
        $this->engagements()->log(SubjectType::Contact, $bob, new EngagementInput(ActivityKind::Meeting, $at, 'Demo', endsAt: $at->modify('+1 hour'), timezone: 'UTC'));
        $source = $this->service(CrmMeetingCalendarSource::class);
        self::assertNull(iterator_to_array($source->events(new CalendarViewer('1', 'board', 'acme'), $range))[0]->url);
    }

    private function engagements(): EngagementServiceInterface
    {
        return $this->service(EngagementServiceInterface::class);
    }
}
