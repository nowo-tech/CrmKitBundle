<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Controller;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\CrmKitBundle\Application\Engagement\IcsWriter;
use Nowo\CrmKitBundle\Domain\Enum\ActivityDirection;
use Nowo\CrmKitBundle\Domain\Enum\ActivityKind;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\RecordStatus;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Read\EngagementView;
use Nowo\CrmKitBundle\Domain\Read\RecordView;
use Nowo\CrmKitBundle\Domain\Service\ActivityFeedInterface;
use Nowo\CrmKitBundle\Domain\Service\CrmReaderInterface;
use Nowo\CrmKitBundle\Domain\Service\EngagementInput;
use Nowo\CrmKitBundle\Domain\Service\EngagementServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\MentionDirectoryInterface;
use Nowo\CrmKitBundle\Domain\Service\PinnedNotesInterface;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\IcsInvite;
use Nowo\CrmKitBundle\Domain\Value\MeetingAttendee;
use Nowo\CrmKitBundle\Domain\Value\MeetingSlot;
use Nowo\CrmKitBundle\Domain\Value\MentionRef;
use Nowo\CrmKitBundle\Domain\Value\RecordId;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\ActionType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\EngagementType;
use Nowo\TransactionDoctrine\WriteUnit\AsWriteUnit;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function array_slice;
use function date_default_timezone_get;
use function explode;
use function is_array;
use function is_int;
use function is_numeric;
use function is_string;
use function preg_match;
use function strtolower;

/**
 * The pages and actions of the logged activities (0.11.0, `ui.enabled` and `activities.enabled`): the log and edit
 * forms of a call, a meeting or an e-mail (each its own page, loaded on demand into the record page's remote dialog),
 * delete, the authorised `.ics` download of a meeting, pin / unpin of a note, and MilkdownEditor's mention endpoints.
 * Every one goes through the CRM's access gate (404 outside the tenant or the feature, 403 without the permission);
 * the forms carry their CSRF token, the POST actions the action token. Stateless.
 *
 * @internal
 */
final readonly class ActivityController
{
    /** Most handles per mention names call. */
    public const MAX_HANDLES = 50;

    /**
     * @param list<string> $callOutcomes `activities.call_outcomes`
     */
    public function __construct(
        private UiSupport $ui,
        private EngagementServiceInterface $engagements,
        private ActivityFeedInterface $feed,
        private PinnedNotesInterface $pins,
        private CrmReaderInterface $reader,
        private PipelineDialogs $dialogs,
        private array $callOutcomes = [],
        private ?MentionDirectoryInterface $mentions = null,
    ) {
    }

    /**
     * Logs a call, a meeting or an e-mail on a record (its own page; GET shows the form).
     */
    public function log(Request $request, string $type, string $id, string $kind): Response
    {
        return $this->ui->guard(function () use ($request, $type, $id, $kind): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $subject = RecordController::type($type);
            // The route accepts call, meeting and email only.
            $loggable = ActivityKind::from($kind);
            $view     = $this->reader->record($subject, $id);
            if ($view->status !== RecordStatus::Active) {
                return $this->ui->toRecord($subject, $view->mergedIntoId ?? $id);
            }
            $this->ui->requireOn(CrmPermission::Edit, $view);
            $zone = date_default_timezone_get();
            $form = $this->form($loggable, $view, ['occurred_at' => (new DateTimeImmutable('now', new DateTimeZone($zone)))->format(EngagementType::LOCAL), 'direction' => ActivityDirection::Out->value, 'timezone' => $zone], []);
            $form->handleRequest($request);
            $status = $request->isMethod('POST') ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;
            if ($form->isSubmitted() && $form->isValid()) {
                $status = $this->dialogs->attempt($request, $form, function () use ($request, $form, $loggable, $subject, $id): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
                    $this->engagements->log($subject, $id, $this->input($loggable, $form));
                    $this->ui->flash($request, 'success', 'activity_log.logged_' . $loggable->value);

                    return $this->dialogs->done($request, 'nowo_crm_kit_show', ['type' => $subject->segment(), 'id' => $id]);
                });
                if ($status instanceof Response) {
                    return $status;
                }
            }

            return $this->dialogs->render($request, $form, $this->ui->url('nowo_crm_kit_activity_log', ['type' => $subject->segment(), 'id' => $id, 'kind' => $loggable->value]), 'activity_log.log_' . $loggable->value, 'activity_log.save', $this->ui->url('nowo_crm_kit_show', ['type' => $subject->segment(), 'id' => $id]), 'crm-activity', $status);
        });
    }

    /**
     * Changes an activity (its own page; GET shows the form with the stored values and version).
     */
    public function edit(Request $request, string $id): Response
    {
        return $this->ui->guard(function () use ($request, $id): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $engagement = $this->feed->engagement($id);
            $subject    = $engagement->subjectType;
            $view       = $this->reader->record($subject, $engagement->subjectId);
            if ($view->status !== RecordStatus::Active) {
                return $this->ui->toRecord($subject, $view->mergedIntoId ?? $view->id);
            }
            $this->ui->requireOn(CrmPermission::Edit, $view);
            $form = $this->form($engagement->kind, $view, $this->values($engagement), $engagement->attendees === [] ? [] : $this->attendeeChoices($engagement), true);
            $form->handleRequest($request);
            $status = $request->isMethod('POST') ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;
            if ($form->isSubmitted() && $form->isValid()) {
                $status = $this->dialogs->attempt($request, $form, function () use ($request, $form, $engagement, $subject, $view): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
                    $version = $form->has('version') ? $form->get('version')->getData() : null;
                    $this->engagements->edit($engagement->id, $this->input($engagement->kind, $form), is_numeric($version) ? (int) $version : -1);
                    $this->ui->flash($request, 'success', 'activity_log.saved');

                    return $this->dialogs->done($request, 'nowo_crm_kit_show', ['type' => $subject->segment(), 'id' => $view->id]);
                });
                if ($status instanceof Response) {
                    return $status;
                }
            }

            return $this->dialogs->render($request, $form, $this->ui->url('nowo_crm_kit_activity_edit', ['id' => $engagement->id]), 'activity_log.edit_' . $engagement->kind->value, 'activity_log.save', $this->ui->url('nowo_crm_kit_show', ['type' => $subject->segment(), 'id' => $view->id]), 'crm-activity-edit', $status);
        });
    }

    /**
     * Deletes an activity (`?type=…&subject=…` names the page to go back to).
     */
    #[AsWriteUnit]
    public function delete(Request $request, string $id): Response
    {
        $back = $this->back($request);

        return $this->ui->act($request, function () use ($request, $id, $back): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->csrf($request);
            $this->engagements->delete($id);
            $this->ui->flash($request, 'success', 'activity_log.deleted');

            return $back;
        }, $back);
    }

    /**
     * Pins (`on`) or unpins (`off`) a note shown at `?version=…`.
     */
    #[AsWriteUnit]
    public function pin(Request $request, string $id, string $state): Response
    {
        $back = $this->back($request);

        return $this->ui->act($request, function () use ($request, $id, $state, $back): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->csrf($request);
            $version = $request->query->get('version');
            $this->pins->pin($id, $state === 'on', is_numeric($version) ? (int) $version : -1);
            $this->ui->flash($request, 'success', $state === 'on' ? 'activity_log.pinned' : 'activity_log.unpinned');

            return $back;
        }, $back);
    }

    /**
     * A meeting as an `.ics` file (`view` on its record): its title, time, place, status, the record's page; never
     * its notes or the people's addresses.
     */
    public function ics(Request $request, string $id): Response
    {
        return $this->ui->guard(function () use ($request, $id): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $meeting = $this->feed->engagement($id);
            if ($meeting->kind !== ActivityKind::Meeting || !$meeting->endsAt instanceof DateTimeImmutable) {
                throw new NotAvailableException();
            }
            $host   = strtolower($request->getHost());
            $url    = $request->getSchemeAndHttpHost() . $this->ui->url('nowo_crm_kit_show', ['type' => $meeting->subjectType->segment(), 'id' => $meeting->subjectId]);
            $invite = new IcsInvite($meeting->id . '@' . (preg_match('/\A[a-z0-9.-]{1,200}\z/D', $host) === 1 ? $host : 'crm.invalid'), $meeting->sequence, 'PUBLISH', $meeting->occurredAt, $meeting->endsAt, $meeting->title ?? '', $meeting->place, $url, $meeting->cancelled, $meeting->updatedAt);

            return new Response(IcsWriter::write($invite), Response::HTTP_OK, [
                'Content-Type'           => 'text/calendar; charset=utf-8',
                'Content-Disposition'    => 'attachment; filename="meeting-' . $meeting->id . '.ics"',
                'Cache-Control'          => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        });
    }

    /**
     * MilkdownEditor's `@` suggestion list (`GET ?q=`): up to 10 members who may view the record.
     */
    public function mentions(Request $request, string $type, string $id): Response
    {
        return $this->ui->guard(function () use ($request, $type, $id): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $mentions = $this->mentions ?? throw new NotAvailableException();
            $query    = $request->query->get('q');
            $out      = [];
            foreach ($mentions->candidates(RecordController::type($type), $id, is_string($query) ? $query : '') as $candidate) {
                $out[] = ['id' => $candidate->handle, 'label' => $candidate->name];
            }

            return $this->json($out);
        });
    }

    /**
     * MilkdownEditor's mention names (`GET ?handles=a,b`).
     */
    public function mentionNames(Request $request, string $type, string $id): Response
    {
        return $this->ui->guard(function () use ($request, $type, $id): Response { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $mentions = $this->mentions ?? throw new NotAvailableException();
            $raw      = $request->query->get('handles');
            $handles  = [];
            foreach (array_slice(explode(',', is_string($raw) ? $raw : ''), 0, self::MAX_HANDLES) as $handle) {
                if (preg_match(MentionRef::PATTERN, $handle) === 1) {
                    $handles[] = $handle;
                }
            }
            $out = [];
            foreach ($mentions->names(RecordController::type($type), $id, $handles) as $handle => $name) {
                $out[] = ['handle' => $handle, 'name' => $name];
            }

            return $this->json($out);
        });
    }

    /**
     * The form of a kind on a record.
     *
     * @param array<string, mixed> $data
     * @param array<string, string> $contacts extra contacts (name ⇒ id) the meeting already has
     *
     * @return FormInterface<mixed>
     */
    private function form(ActivityKind $kind, RecordView $view, array $data, array $contacts, bool $versioned = false): FormInterface
    {
        $outcomes = [];
        foreach ($this->callOutcomes as $outcome) {
            $outcomes['call_outcome.' . $outcome] = $outcome;
        }
        $members = [];
        foreach ($this->reader->owners() as $profile) {
            $members[$profile->name] = $profile->ref->value;
        }
        if ($view->type === SubjectType::Contact) {
            $contacts = [$view->label => $view->id] + $contacts;
        } else {
            foreach ($view->affiliations as $affiliation) {
                $contacts[$affiliation->contactName] = $affiliation->contactId;
            }
        }

        return $this->ui->form(EngagementType::class, $data, ['kind' => $kind->value, 'outcomes' => $outcomes, 'members' => $members, 'contacts' => $contacts, 'versioned' => $versioned], 'crm_activity');
    }

    /**
     * The contacts a stored meeting has (name ⇒ id).
     *
     * @return array<string, string>
     */
    private function attendeeChoices(EngagementView $engagement): array
    {
        $out = [];
        foreach ($engagement->attendees as $attendee) {
            if ($attendee->contactId !== null) {
                $out[$attendee->name] = $attendee->contactId;
            }
        }

        return $out;
    }

    /**
     * The form values of a stored activity (times in its zone, or the application's).
     *
     * @return array<string, mixed>
     */
    private function values(EngagementView $engagement): array
    {
        $zone    = new DateTimeZone($engagement->timezone !== null && MeetingSlot::isZone($engagement->timezone) ? $engagement->timezone : date_default_timezone_get());
        $members = [];
        $people  = [];
        foreach ($engagement->attendees as $attendee) {
            if ($attendee->actorId !== null) {
                $members[] = $attendee->actorId;
            } elseif ($attendee->contactId !== null) {
                $people[] = $attendee->contactId;
            }
        }

        return [
            'occurred_at' => $engagement->occurredAt->setTimezone($zone)->format(EngagementType::LOCAL),
            'ends_at'     => $engagement->endsAt?->setTimezone($zone)->format(EngagementType::LOCAL),
            'timezone'    => $zone->getName(),
            'title'       => $engagement->title,
            'body'        => $engagement->body,
            'direction'   => $engagement->direction?->value,
            'outcome'     => $engagement->outcome,
            'duration'    => $engagement->durationMinutes,
            'place'       => $engagement->place,
            'members'     => $members,
            'contacts'    => $people,
            'cancelled'   => $engagement->cancelled,
            'version'     => (string) $engagement->version,
        ];
    }

    /**
     * The domain input of a submitted form; local times are read in the meeting's zone (or the application's).
     *
     * @param FormInterface<mixed> $form
     */
    private function input(ActivityKind $kind, FormInterface $form): EngagementInput
    {
        $data      = $form->getData();
        $data      = is_array($data) ? $data : [];
        $zone      = $kind === ActivityKind::Meeting && is_string($data['timezone'] ?? null) && MeetingSlot::isZone($data['timezone']) ? $data['timezone'] : date_default_timezone_get();
        $at        = $this->local($data['occurred_at'] ?? null, $zone) ?? throw InvalidInputException::field('occurred_at', 'error.required');
        $attendees = [];
        foreach (is_array($data['members'] ?? null) ? $data['members'] : [] as $member) {
            if (is_string($member) && $member !== '') {
                $attendees[] = MeetingAttendee::member(new ActorRef($member));
            }
        }
        foreach (is_array($data['contacts'] ?? null) ? $data['contacts'] : [] as $contact) {
            if (is_string($contact) && RecordId::isValid($contact)) {
                $attendees[] = MeetingAttendee::contact(new RecordId($contact));
            }
        }
        $duration = $data['duration'] ?? null;

        return new EngagementInput(
            $kind,
            $at,
            is_string($data['title'] ?? null) ? $data['title'] : null,
            is_string($data['body'] ?? null) ? $data['body'] : null,
            ActivityDirection::tryFrom(is_string($data['direction'] ?? null) ? $data['direction'] : ''),
            is_string($data['outcome'] ?? null) ? $data['outcome'] : null,
            is_int($duration) ? $duration : null,
            $this->local($data['ends_at'] ?? null, $zone),
            $kind === ActivityKind::Meeting ? (is_string($data['timezone'] ?? null) ? $data['timezone'] : '') : null,
            is_string($data['place'] ?? null) ? $data['place'] : null,
            $attendees,
            ($data['cancelled'] ?? false) === true,
        );
    }

    /**
     * A local date-time (`Y-m-d\TH:i`) in a zone, or null.
     */
    private function local(mixed $value, string $zone): ?DateTimeImmutable
    {
        if (!is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}\z/D', $value) !== 1) {
            return null;
        }
        $at = DateTimeImmutable::createFromFormat('!' . EngagementType::LOCAL, $value, new DateTimeZone($zone));

        return $at instanceof DateTimeImmutable ? $at : null;
    }

    /**
     * Checks the action token of a POST action.
     */
    private function csrf(Request $request): void
    {
        $form = $this->ui->form(ActionType::class, null, [], 'crm_action');
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            throw InvalidInputException::field('_', 'error.csrf');
        }
    }

    /**
     * Where an action returns: the record named by `?type=…&subject=…` (at its feed), else the CRM home.
     */
    private function back(Request $request): RedirectResponse
    {
        $type    = SubjectType::fromSegment((string) $request->query->get('type', ''));
        $subject = (string) $request->query->get('subject', '');

        return $type instanceof SubjectType && RecordId::isValid($subject) ? $this->ui->toRecord($type, $subject) : $this->ui->redirect('nowo_crm_kit_home');
    }

    /**
     * A private JSON answer.
     *
     * @param list<array<string, string>> $data
     */
    private function json(array $data): JsonResponse
    {
        $response = new JsonResponse($data);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
