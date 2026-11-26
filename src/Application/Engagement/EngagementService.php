<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application\Engagement;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Nowo\CrmKitBundle\Application\CrmContext;
use Nowo\CrmKitBundle\Application\Engagement\Port\EngagementStoreInterface;
use Nowo\CrmKitBundle\Application\NoteService;
use Nowo\CrmKitBundle\Application\Pipeline\Port\PipelineStoreInterface;
use Nowo\CrmKitBundle\Application\RecordValidator;
use Nowo\CrmKitBundle\Application\Shared\Effects;
use Nowo\CrmKitBundle\Application\WriteSupport;
use Nowo\CrmKitBundle\Domain\Enum\ActivityDirection;
use Nowo\CrmKitBundle\Domain\Enum\ActivityKind;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\DealStatus;
use Nowo\CrmKitBundle\Domain\Enum\RecordStatus;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Event\EngagementChanged;
use Nowo\CrmKitBundle\Domain\Event\EngagementDeleted;
use Nowo\CrmKitBundle\Domain\Event\EngagementLogged;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Model\Contact;
use Nowo\CrmKitBundle\Domain\Model\CrmRecord;
use Nowo\CrmKitBundle\Domain\Model\Engagement\Engagement;
use Nowo\CrmKitBundle\Domain\Model\Engagement\EngagementAttendee;
use Nowo\CrmKitBundle\Domain\Model\Engagement\EngagementSubject;
use Nowo\CrmKitBundle\Domain\Port\CrmActorDirectoryInterface;
use Nowo\CrmKitBundle\Domain\Service\EngagementInput;
use Nowo\CrmKitBundle\Domain\Service\EngagementServiceInterface;
use Nowo\CrmKitBundle\Domain\Value\ActivityDuration;
use Nowo\CrmKitBundle\Domain\Value\ActorProfile;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\EmailExcerpt;
use Nowo\CrmKitBundle\Domain\Value\MeetingAttendee;
use Nowo\CrmKitBundle\Domain\Value\MeetingPlace;
use Nowo\CrmKitBundle\Domain\Value\MeetingSlot;
use Nowo\CrmKitBundle\Domain\Value\RecordId;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;

use function array_values;
use function count;
use function in_array;
use function trim;

/**
 * Logged activities (0.11.0, `activities.enabled`): calls, meetings and e-mails on a company or a contact. Each write
 * locks the primary record (`FOR UPDATE`, as notes do), checks `edit` on it (`manage` too for someone else's
 * activity), validates the values into the kit's value objects (input errors per field), writes the activity, its
 * subject rows and its attendees, a timeline entry on the primary record and an audit entry, and releases its event
 * after the commit. When it is logged, a contact's activity is associated with the contact's companies and open
 * deals, a company's with its open deals (the subject rows; the associations are not changed by later edits).
 * Stateless.
 */
final readonly class EngagementService implements EngagementServiceInterface
{
    /** Longest title. */
    public const MAX_TITLE = 200;

    /** Most attendees of a meeting. */
    public const MAX_ATTENDEES = 50;

    /**
     * @param list<string> $callOutcomes the `activities.call_outcomes` keys
     */
    public function __construct(
        private WriteSupport $support,
        private EngagementStoreInterface $engagements,
        private CrmActorDirectoryInterface $directory,
        private array $callOutcomes = [],
        private ?PipelineStoreInterface $pipeline = null,
    ) {
    }

    /**
     * Logs an activity.
     */
    public function log(SubjectType $type, string $subjectId, EngagementInput $input): string
    {
        $context = $this->support->access->base();
        if (!$type->isRecord() || !$input->kind->isLoggable()) {
            throw new NotAvailableException();
        }
        $values = $this->validate($input);

        return $this->support->write(function (Effects $effects) use ($context, $type, $subjectId, $input, $values): string { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $record     = $this->support->load($context, $type, $subjectId, CrmPermission::Edit, lock: true);
            $attendees  = $this->attendees($context, $input);
            $id         = $this->support->id();
            $engagement = new Engagement($id, $context->tenant->value, $input->kind, $type, $subjectId, $values['at'], $context->actor->value, $context->now);
            $this->describe($engagement, $values, $context->now, true);
            $store = $this->support->store;
            $store->add($engagement); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            foreach ($this->subjects($context, $record) as [$subjectType, $subject, $primary]) {
                $store->add(new EngagementSubject($this->support->id(), $context->tenant->value, $id, $subjectType, $subject, $primary)); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            }
            $this->addAttendees($context, $id, $attendees);
            $this->support->activity($context, $type, $subjectId, $input->kind, ['engagement' => $id]);
            $effects->event(new EngagementLogged($context->tenant, $context->actor, $context->now, $type, $subjectId, $id, $input->kind));
            $this->support->audit($effects, $context, 'crm.activity.logged', $type, $subjectId, ['activity' => $id, 'kind' => $input->kind->value]);

            return $id;
        });
    }

    /**
     * Changes an activity.
     */
    public function edit(string $id, EngagementInput $input, int $expectedVersion): void
    {
        $context = $this->support->access->base();
        $values  = $this->validate($input);
        $this->support->write(function (Effects $effects) use ($context, $id, $input, $values, $expectedVersion): void { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $engagement = $this->changeable($context, $id);
            if ($engagement->getVersion() !== $expectedVersion) {
                throw new ConflictException(ConflictException::STALE, $id);
            }
            if ($engagement->getKind() !== $input->kind) {
                throw InvalidInputException::field('kind', 'activity_error.kind');
            }
            $attendees = $this->attendees($context, $input);
            $this->describe($engagement, $values, $context->now, false);
            if ($engagement->getKind() === ActivityKind::Meeting) {
                foreach ($this->engagements->attendeesOf($context->tenant, [$id])[$id] ?? [] as $row) {
                    $this->support->store->remove($row); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
                }
                // The removals go first: a kept attendee is written again as a new row.
                $this->support->store->flush();
                $this->addAttendees($context, $id, $attendees);
            }
            $type    = $engagement->getSubjectType();
            $subject = $engagement->getSubjectId();
            $this->support->activity($context, $type, $subject, ActivityKind::ActivityEdited, ['engagement' => $id, 'kind' => $engagement->getKind()->value]);
            $effects->event(new EngagementChanged($context->tenant, $context->actor, $context->now, $type, $subject, $id, $engagement->getKind()));
            $this->support->audit($effects, $context, 'crm.activity.edited', $type, $subject, ['activity' => $id, 'kind' => $engagement->getKind()->value]);
        });
    }

    /**
     * Deletes an activity.
     */
    public function delete(string $id): void
    {
        $context = $this->support->access->base();
        $this->support->write(function (Effects $effects) use ($context, $id): void { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $engagement = $this->changeable($context, $id);
            $store      = $this->support->store;
            foreach ($this->engagements->subjectsOf($context->tenant, [$id])[$id] ?? [] as $row) {
                $store->remove($row); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            }
            foreach ($this->engagements->attendeesOf($context->tenant, [$id])[$id] ?? [] as $row) {
                $store->remove($row); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            }
            $store->remove($engagement); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            $type    = $engagement->getSubjectType();
            $subject = $engagement->getSubjectId();
            $this->support->activity($context, $type, $subject, ActivityKind::ActivityDeleted, ['engagement' => $id, 'kind' => $engagement->getKind()->value]);
            $effects->event(new EngagementDeleted($context->tenant, $context->actor, $context->now, $type, $subject, $id, $engagement->getKind()));
            $this->support->audit($effects, $context, 'crm.activity.deleted', $type, $subject, ['activity' => $id, 'kind' => $engagement->getKind()->value]);
        });
    }

    /**
     * An activity the actor may change: `edit` on its primary record (locked), `manage` too for someone else's.
     */
    private function changeable(CrmContext $context, string $id): Engagement
    {
        $engagement = RecordId::isValid($id) ? $this->engagements->engagement($context->tenant, $id) : null;
        if (!$engagement instanceof Engagement) {
            throw new NotAvailableException();
        }
        $record = $this->support->load($context, $engagement->getSubjectType(), $engagement->getSubjectId(), CrmPermission::Edit, lock: true);
        if ($engagement->getAuthorId() !== $context->actor->value) {
            $this->support->access->require($context, CrmPermission::Manage, SubjectRef::of($record::type(), $record->getId(), $record->getOwnerId()));
        }

        return $engagement;
    }

    /**
     * The records the activity shows on: the primary one, then (once each) a contact's companies and the open deals of
     * the primary record.
     *
     * @return list<array{SubjectType, string, bool}>
     */
    private function subjects(CrmContext $context, CrmRecord $record): array
    {
        $type = $record::type();
        $id   = $record->getId();
        $out  = [[$type, $id, true]];
        $seen = [$type->value . ':' . $id => true];
        if ($record instanceof Contact) {
            foreach ($this->support->store->affiliations($context->tenant, SubjectType::Contact, $id) as $affiliation) {
                $key = SubjectType::Company->value . ':' . $affiliation->getCompanyId();
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $out[]      = [SubjectType::Company, $affiliation->getCompanyId(), false];
                }
            }
        }
        foreach ($this->pipeline?->dealsOf($context->tenant, $type, $id) ?? [] as $deal) {
            $key = SubjectType::Deal->value . ':' . $deal->getId();
            if ($deal->getStatus() === DealStatus::Open && !isset($seen[$key])) {
                $seen[$key] = true;
                $out[]      = [SubjectType::Deal, $deal->getId(), false];
            }
        }

        return $out;
    }

    /**
     * Checks the attendees of a meeting inside the write: members of the tenant (the directory knows them) and active
     * contacts of the tenant the actor may view; anything else is an input error on `attendees`.
     *
     * @return list<MeetingAttendee>
     */
    private function attendees(CrmContext $context, EngagementInput $input): array
    {
        if ($input->kind !== ActivityKind::Meeting) {
            return [];
        }
        $unique = [];
        foreach ($input->attendees as $attendee) {
            $unique[$attendee->key()] = $attendee;
        }
        if (count($unique) > self::MAX_ATTENDEES) {
            throw InvalidInputException::field('attendees', 'activity_error.attendees_limit', ['%max%' => (string) self::MAX_ATTENDEES]);
        }
        $contacts = [];
        foreach ($unique as $attendee) {
            if ($attendee->actor instanceof ActorRef) {
                $profile = ActorRef::isReserved($attendee->actor->value) ? null : $this->directory->actor($context->tenant, $attendee->actor);
                if (!$profile instanceof ActorProfile || $profile->ref->value !== $attendee->actor->value) {
                    throw InvalidInputException::field('attendees', 'activity_error.attendee_unknown');
                }
            } elseif ($attendee->contact instanceof RecordId) {
                $contacts[] = $attendee->contact->value;
            }
        }
        if ($contacts !== []) {
            $found = $this->support->store->records(SubjectType::Contact, $context->tenant, $contacts);
            $refs  = [];
            foreach ($contacts as $contact) {
                $record = $found[$contact] ?? null;
                if (!$record instanceof CrmRecord || $record->getStatus() !== RecordStatus::Active || $record->getId() !== $contact) {
                    throw InvalidInputException::field('attendees', 'activity_error.attendee_unknown');
                }
                $refs[] = SubjectRef::of(SubjectType::Contact, $contact, $record->getOwnerId());
            }
            $granted = $this->support->access->viewable($context, $refs);
            foreach ($refs as $ref) {
                if (!isset($granted[$ref->key()])) {
                    throw InvalidInputException::field('attendees', 'activity_error.attendee_unknown');
                }
            }
        }

        return array_values($unique);
    }

    /**
     * Writes the attendee rows of a meeting.
     *
     * @param list<MeetingAttendee> $attendees
     */
    private function addAttendees(CrmContext $context, string $id, array $attendees): void
    {
        foreach ($attendees as $attendee) {
            $this->support->store->add(new EngagementAttendee($this->support->id(), $context->tenant->value, $id, $attendee->actor?->value, $attendee->contact?->value)); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        }
    }

    /**
     * Sets the validated values on the activity.
     *
     * @param array{at: DateTimeImmutable, title: ?string, body: ?string, direction: ?ActivityDirection, outcome: ?string, duration: ?int, ends: ?DateTimeImmutable, zone: ?string, place: ?string, cancelled: bool} $values
     */
    private function describe(Engagement $engagement, array $values, DateTimeImmutable $now, bool $initial): void
    {
        $engagement->describe($values['at'], $values['title'], $values['body'], $values['direction'], $values['outcome'], $values['duration'], $values['ends'], $values['zone'], $values['place'], $values['cancelled'], $now, $initial);
    }

    /**
     * Validates the input of its kind; the values to store, or every input error at once (per field).
     *
     * @return array{at: DateTimeImmutable, title: ?string, body: ?string, direction: ?ActivityDirection, outcome: ?string, duration: ?int, ends: ?DateTimeImmutable, zone: ?string, place: ?string, cancelled: bool}
     */
    private function validate(EngagementInput $input): array
    {
        $errors = [];
        $kind   = $input->kind;
        $utc    = new DateTimeZone('UTC');
        $at     = $input->occurredAt->setTimezone($utc);
        $year   = (int) $at->format('Y');
        $values = ['at' => $at, 'title' => null, 'body' => null, 'direction' => null, 'outcome' => null, 'duration' => null, 'ends' => null, 'zone' => null, 'place' => null, 'cancelled' => false];
        if ($year < 1971 || $year > 2100) {
            $errors['occurred_at'] = 'activity_error.time';
        }
        $title = trim($input->title ?? '');
        if ($title === '') {
            if ($kind !== ActivityKind::Call) {
                $errors['title'] = 'error.required';
            }
        } elseif (RecordValidator::check($title, self::MAX_TITLE, 'text') !== null) {
            $errors['title'] = 'activity_error.title';
        } else {
            $values['title'] = $title;
        }
        $body = trim($input->body ?? '');
        if ($body !== '') {
            if ($kind === ActivityKind::Email) {
                try {
                    $values['body'] = (new EmailExcerpt($body))->value;
                } catch (InvalidArgumentException) {
                    $errors['body'] = 'activity_error.excerpt';
                }
            } elseif (RecordValidator::check($body, NoteService::MAX, 'textarea') !== null) {
                $errors['body'] = 'error.note_invalid';
            } else {
                $values['body'] = $body;
            }
        }
        if ($kind !== ActivityKind::Meeting) {
            if (!$input->direction instanceof ActivityDirection) {
                $errors['direction'] = 'error.required';
            }
            $values['direction'] = $input->direction;
        }
        if ($kind === ActivityKind::Call) {
            if ($input->outcome !== null && $input->outcome !== '') {
                if (!in_array($input->outcome, $this->callOutcomes, true)) {
                    $errors['outcome'] = 'activity_error.outcome';
                } else {
                    $values['outcome'] = $input->outcome;
                }
            }
            if ($input->durationMinutes !== null) {
                try {
                    $values['duration'] = (new ActivityDuration($input->durationMinutes))->minutes;
                } catch (InvalidArgumentException) {
                    $errors['duration'] = 'activity_error.duration';
                }
            }
        }
        if ($kind === ActivityKind::Meeting) {
            $zone = $input->timezone ?? '';
            if (!MeetingSlot::isZone($zone)) {
                $errors['timezone'] = 'activity_error.timezone';
            } elseif (!$input->endsAt instanceof DateTimeImmutable) {
                $errors['ends_at'] = 'error.required';
            } else {
                try {
                    $slot           = new MeetingSlot($input->occurredAt, $input->endsAt, $zone);
                    $values['at']   = $slot->start;
                    $values['ends'] = $slot->end;
                    $values['zone'] = $slot->zone;
                } catch (InvalidArgumentException) {
                    $errors['ends_at'] = 'activity_error.slot';
                }
            }
            $place = trim($input->place ?? '');
            if ($place !== '') {
                try {
                    $values['place'] = (new MeetingPlace($place))->value;
                } catch (InvalidArgumentException) {
                    $errors['place'] = 'activity_error.place';
                }
            }
            $values['cancelled'] = $input->cancelled;
        }
        if ($errors !== []) {
            throw new InvalidInputException($errors);
        }

        return $values;
    }
}
