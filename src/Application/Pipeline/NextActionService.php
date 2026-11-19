<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application\Pipeline;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\CrmKitBundle\Application\Access\CrmCallerInterface;
use Nowo\CrmKitBundle\Application\CrmContext;
use Nowo\CrmKitBundle\Application\RecordService;
use Nowo\CrmKitBundle\Application\RecordValidator;
use Nowo\CrmKitBundle\Application\Shared\DuplicateRowException;
use Nowo\CrmKitBundle\Application\Shared\Effects;
use Nowo\CrmKitBundle\Domain\Enum\ActivityKind;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\NextActionStatus;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\CrmTaskRefusedException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Model\Pipeline\Deal;
use Nowo\CrmKitBundle\Domain\Model\Pipeline\NextAction;
use Nowo\CrmKitBundle\Domain\Port\CrmActorDirectoryInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmTaskPort;
use Nowo\CrmKitBundle\Domain\Read\Pipeline\NextActionView;
use Nowo\CrmKitBundle\Domain\Value\ActorProfile;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\CrmTaskRef;
use Nowo\CrmKitBundle\Domain\Value\CrmTaskRequest;
use Nowo\CrmKitBundle\Domain\Value\RecordId;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;

use function array_map;
use function in_array;
use function preg_match;
use function trim;

/**
 * Next actions on companies, contacts and deals (0.3.0). `set()` checks the subject (`edit`), asks the task port to
 * create the task **before** the CRM's write unit (outside any CRM transaction, REQ-RUNTIME-003 rule 9) with the
 * caller's dedupe key, then writes the row in one unit: the subject locked `FOR UPDATE`, the row unique per tenant and
 * dedupe key (a repeat adopts it), and a deal's `next_action_at` set to the earliest due open action. A port refusal
 * writes nothing; a lock conflict after a host port created its task is `CONFLICT_002` (committed), and a retry with
 * the same key adopts that task. Stateless.
 */
final readonly class NextActionService
{
    /** Longest title. */
    public const MAX_TITLE = 200;

    /** Next actions listed per subject. */
    public const LIST = 50;

    /**
     * Wires the collaborators.
     */
    public function __construct(
        private PipelineSupport $pipeline,
        private CrmTaskPort $tasks,
        private CrmActorDirectoryInterface $directory,
        private NextActionViews $views,
    ) {
    }

    /**
     * A copy bound to `$caller` for one call (an API principal); never assigned to a property.
     *
     * @internal
     */
    public function as(CrmCallerInterface $caller): self
    {
        return new self($this->pipeline->as($caller), $this->tasks, $this->directory, $this->views);
    }

    /**
     * Creates a next action (or returns the one already created for `$dedupeKey`, 64 hex); its id. `$assigneeId`
     * null assigns the actor; any other assignee must be an owner of the directory. `$hostContext` is handed to the
     * task port (an API token, re-applied by the host); `$backLink` is the record's local path.
     *
     * @throws AccessDeniedException|NotAvailableException `edit` on the subject
     * @throws ConflictException `crm_record_merged` / `crm_record_trashed` (companies, contacts)
     * @throws InvalidInputException title, due_at, assignee, dedupe key
     * @throws CrmTaskRefusedException the port refused (nothing written)
     * @throws ConcurrencyConflict `committed: true` when a lock conflict follows a host port's commit
     */
    public function set(SubjectType $type, string $subjectId, string $title, DateTimeImmutable $dueAt, ?string $assigneeId, string $dedupeKey, ?object $hostContext = null, ?string $backLink = null): string
    {
        $context = $this->pipeline->support->access->base();
        $title   = trim($title);
        $error   = $title === '' ? 'error.required' : RecordValidator::check($title, self::MAX_TITLE, 'text');
        if ($error !== null) {
            throw InvalidInputException::field('title', $error);
        }
        if (preg_match('/\A[0-9a-f]{64}\z/', $dedupeKey) !== 1) {
            throw InvalidInputException::field('dedupe_key', 'error.invalid');
        }
        $assignee = $this->assignee($context, $assigneeId);
        $dueAt    = $dueAt->setTimezone(new DateTimeZone('UTC'));
        // The subject first (cheap refusals), and a repeat of a key already stored answers without asking the port.
        $existing = $this->pipeline->support->read(function () use ($context, $type, $subjectId, $dedupeKey): ?string { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->subject($context, $type, $subjectId, false);

            return $this->pipeline->store->nextActionByDedupe($context->tenant, $dedupeKey)?->getId();
        });
        if ($existing !== null) {
            return $existing;
        }
        $ref     = $this->tasks->create(new CrmTaskRequest($context->tenant, $context->actor, SubjectRef::of($type, $subjectId), $title, $dueAt, new ActorRef($assignee), $dedupeKey, $context->channel(), $hostContext, $backLink));
        $builtIn = $ref->type === BuiltInCrmTaskPort::TYPE;
        $write   = fn (Effects $effects): string => $this->write($effects, $context, $type, $subjectId, $title, $dueAt, $assignee, $dedupeKey, $ref, $builtIn);

        try {
            try {
                return $this->pipeline->support->write($write);
            } catch (DuplicateRowException) {
                // Another request with the same key stored its row at the same moment: adopt it.
                return RecordService::onceMore(fn (): string => $this->pipeline->support->write($write));
            }
        } catch (ConcurrencyConflict $e) {
            // The host's task exists already: this request committed something, so the client must not replay blindly.
            throw $builtIn || $e->committed ? $e : new ConcurrencyConflict($e->attempts, $e->kind, null, $e, true);
        }
    }

    /**
     * Marks an open next action done.
     *
     * @throws AccessDeniedException|NotAvailableException
     */
    public function complete(string $id): void
    {
        $this->close($id, NextActionStatus::Done);
    }

    /**
     * Cancels an open next action.
     *
     * @throws AccessDeniedException|NotAvailableException
     */
    public function cancel(string $id): void
    {
        $this->close($id, NextActionStatus::Cancelled);
    }

    /**
     * The next actions of a subject the actor may view: open ones first (earliest due first), at most 50.
     *
     * @throws AccessDeniedException|NotAvailableException
     *
     * @return list<NextActionView>
     */
    public function list(SubjectType $type, string $subjectId, bool $openOnly = false): array
    {
        $context = $this->pipeline->support->access->base();

        return $this->pipeline->support->read(function () use ($context, $type, $subjectId, $openOnly): array { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->subject($context, $type, $subjectId, false, CrmPermission::View);

            return $this->views->of($context, $this->pipeline->store->nextActions($context->tenant, $type, $subjectId, $openOnly, self::LIST));
        });
    }

    private function write(Effects $effects, CrmContext $context, SubjectType $type, string $subjectId, string $title, DateTimeImmutable $dueAt, string $assignee, string $dedupeKey, CrmTaskRef $ref, bool $builtIn): string
    {
        $subject  = $this->subject($context, $type, $subjectId, true);
        $existing = $this->pipeline->store->nextActionByDedupe($context->tenant, $dedupeKey);
        if ($existing instanceof NextAction) {
            return $existing->getId();
        }
        $id = $this->pipeline->support->id();
        $this->pipeline->support->store->add(new NextAction($id, $context->tenant->value, $type, $subjectId, $title, $dueAt, $assignee, $ref->type, $builtIn ? $id : $ref->id, $dedupeKey, $context->actor->value, $context->now)); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        if ($subject instanceof Deal) {
            $earliest = $this->pipeline->store->earliestOpenDue($context->tenant, SubjectType::Deal, $subjectId);
            $subject->changeNextActionAt($earliest instanceof DateTimeImmutable && $earliest < $dueAt ? $earliest : $dueAt);
        }
        $this->pipeline->support->activity($context, $type, $subjectId, ActivityKind::NextActionAdded, ['action' => $id]);
        $this->pipeline->support->audit($effects, $context, 'crm.next_action.created', $type, $subjectId, ['action' => $id, 'task_type' => $ref->type]);

        return $id;
    }

    private function close(string $id, NextActionStatus $status): void
    {
        $context = $this->pipeline->support->access->base();
        $this->pipeline->support->write(function (Effects $effects) use ($context, $id, $status): void { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $action = RecordId::isValid($id) ? $this->pipeline->store->nextAction($context->tenant, $id) : null;
            if (!$action instanceof NextAction) {
                throw new NotAvailableException();
            }
            $subject = $this->subject($context, $action->getSubjectType(), $action->getSubjectId(), true);
            if ($action->getStatus() !== NextActionStatus::Open) {
                return;
            }
            $action->close($status, $context->now);
            $this->pipeline->support->store->flush();
            if ($subject instanceof Deal) {
                $subject->changeNextActionAt($this->pipeline->store->earliestOpenDue($context->tenant, SubjectType::Deal, $subject->getId()));
            }
            $this->pipeline->support->activity($context, $action->getSubjectType(), $action->getSubjectId(), ActivityKind::NextActionClosed, ['action' => $id, 'status' => $status->value]);
            $this->pipeline->support->audit($effects, $context, 'crm.next_action.' . $status->value, $action->getSubjectType(), $action->getSubjectId(), ['action' => $id]);
        });
    }

    /**
     * The subject with the permission on it (a company or contact must be active; a deal not in the trash).
     */
    private function subject(CrmContext $context, SubjectType $type, string $id, bool $lock, CrmPermission $permission = CrmPermission::Edit): object
    {
        return $type === SubjectType::Deal
            ? $this->pipeline->deal($context, $id, $permission, $lock)
            : $this->pipeline->support->load($context, $type, $id, $permission, lock: $lock);
    }

    /**
     * The assignee: the actor when none is given, else an owner of the directory.
     *
     * @throws InvalidInputException
     */
    private function assignee(CrmContext $context, ?string $assignee): string
    {
        if ($assignee === null || $assignee === '' || $assignee === $context->actor->value) {
            return $context->actor->value;
        }
        $owners = array_map(static fn (ActorProfile $p): string => $p->ref->value, $this->pipeline->support->access->owners($context, $this->directory));
        if (ActorRef::isReserved($assignee) || !in_array($assignee, $owners, true)) {
            throw InvalidInputException::field('assignee', 'error.owner_unknown');
        }

        return $assignee;
    }
}
