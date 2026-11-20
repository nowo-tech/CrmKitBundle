<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application;

use Nowo\CrmKitBundle\Application\Access\CrmCallerInterface;
use Nowo\CrmKitBundle\Application\Shared\Effects;
use Nowo\CrmKitBundle\Domain\Enum\ActivityKind;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Event\NoteAdded;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Model\Note;
use Nowo\CrmKitBundle\Domain\Service\NoteServiceInterface;
use Nowo\CrmKitBundle\Domain\Value\RecordId;

use function trim;

/**
 * Notes (contract section 9): plain text up to 10 000 characters; the author edits and deletes own notes with
 * `edit`, others' need `manage`. Stateless.
 */
final readonly class NoteService implements NoteServiceInterface
{
    /** Longest note. */
    public const MAX = 10000;

    /**
     * Wires the collaborators.
     */
    public function __construct(private WriteSupport $support)
    {
    }

    /**
     * A copy bound to `$caller` for one call (0.3.0: an API principal); never assigned to a property.
     *
     * @internal
     */
    public function as(CrmCallerInterface $caller): self
    {
        return new self($this->support->as($caller));
    }

    /**
     * Adds a note.
     */
    public function add(SubjectType $type, string $subjectId, string $body, bool $pinned = false): string
    {
        $context = $this->support->access->base();
        $body    = $this->body($body);

        return $this->support->write(function (Effects $effects) use ($context, $type, $subjectId, $body, $pinned): string { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $this->support->load($context, $type, $subjectId, CrmPermission::Edit, lock: true);
            $id = $this->support->id();
            $this->support->store->add(new Note($id, $context->tenant->value, $type, $subjectId, $body, $pinned, $context->actor->value, $context->now)); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            $this->support->activity($context, $type, $subjectId, ActivityKind::NoteAdded, ['note' => $id]);
            $effects->event(new NoteAdded($context->tenant, $context->actor, $context->now, $type, $subjectId, $id));
            $this->support->audit($effects, $context, 'crm.note.added', $type, $subjectId, ['note' => $id]);

            return $id;
        });
    }

    /**
     * Edits a note.
     */
    public function edit(string $id, string $body, bool $pinned, int $expectedVersion): void
    {
        $context = $this->support->access->base();
        $body    = $this->body($body);
        $this->support->write(function (Effects $effects) use ($context, $id, $body, $pinned, $expectedVersion): void { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $note = $this->note($context, $id);
            if ($note->getVersion() !== $expectedVersion) {
                throw new ConflictException(ConflictException::STALE, $id);
            }
            $note->edit($body, $pinned, $context->now);
            $this->support->audit($effects, $context, 'crm.note.edited', $note->getSubjectType(), $note->getSubjectId(), ['note' => $id]);
            $this->support->activity($context, $note->getSubjectType(), $note->getSubjectId(), ActivityKind::NoteEdited, ['note' => $id]);
        });
    }

    /**
     * Deletes a note.
     */
    public function delete(string $id): void
    {
        $context = $this->support->access->base();
        $this->support->write(function (Effects $effects) use ($context, $id): void { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $note = $this->note($context, $id);
            $this->support->store->remove($note); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            $this->support->activity($context, $note->getSubjectType(), $note->getSubjectId(), ActivityKind::NoteDeleted, ['note' => $id]);
            $this->support->audit($effects, $context, 'crm.note.deleted', $note->getSubjectType(), $note->getSubjectId());
        });
    }

    /**
     * A note the actor may change: `edit` on the record, and `manage` for someone else's note.
     */
    private function note(CrmContext $context, string $id): Note
    {
        $note = RecordId::isValid($id) ? $this->support->store->note($context->tenant, $id) : null;
        if (!$note instanceof Note) {
            throw new NotAvailableException();
        }
        $this->support->load($context, $note->getSubjectType(), $note->getSubjectId(), CrmPermission::Edit, lock: true);
        if ($note->getAuthorId() !== $context->actor->value) {
            $this->support->access->require($context, CrmPermission::Manage);
        }

        return $note;
    }

    private function body(string $body): string
    {
        $body = trim($body);
        if ($body === '' || RecordValidator::check($body, self::MAX, 'textarea') !== null) {
            throw InvalidInputException::field('body', $body === '' ? 'error.required' : 'error.note_invalid');
        }

        return $body;
    }
}
