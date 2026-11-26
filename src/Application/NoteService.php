<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application;

use Nowo\CrmKitBundle\Application\Access\CrmCallerInterface;
use Nowo\CrmKitBundle\Application\Engagement\Mentions;
use Nowo\CrmKitBundle\Application\Shared\Effects;
use Nowo\CrmKitBundle\Domain\Enum\ActivityKind;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Event\NoteAdded;
use Nowo\CrmKitBundle\Domain\Event\NoteMentioned;
use Nowo\CrmKitBundle\Domain\Event\NotePinned;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Model\CrmRecord;
use Nowo\CrmKitBundle\Domain\Model\Note;
use Nowo\CrmKitBundle\Domain\Service\NoteServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\PinnedNotesInterface;
use Nowo\CrmKitBundle\Domain\Value\RecordId;

use function array_slice;
use function count;
use function trim;

/**
 * Notes (contract section 9): plain text up to 10 000 characters; the author edits and deletes own notes with
 * `edit`, others' need `manage`. **(0.11.0, `activities.enabled`)** Pinning with `edit` ({@see self::pin()}), the
 * pin limit `activities.max_pinned` (the oldest pinned notes beyond it are unpinned in the same write, under the
 * record's lock) and @mentions: the members newly mentioned by a save are announced after the commit
 * ({@see NoteMentioned}). Stateless.
 */
final readonly class NoteService implements NoteServiceInterface, PinnedNotesInterface
{
    /** Longest note. */
    public const MAX = 10000;

    /**
     * Wires the collaborators.
     */
    public function __construct(private WriteSupport $support, private ?Mentions $mentions = null, private int $maxPinned = 0)
    {
    }

    /**
     * A copy bound to `$caller` for one call (0.3.0: an API principal); never assigned to a property.
     *
     * @internal
     */
    public function as(CrmCallerInterface $caller): self
    {
        return new self($this->support->as($caller), $this->mentions, $this->maxPinned);
    }

    /**
     * Adds a note.
     */
    public function add(SubjectType $type, string $subjectId, string $body, bool $pinned = false): string
    {
        $context = $this->support->access->base();
        $body    = $this->body($body);

        return $this->support->write(function (Effects $effects) use ($context, $type, $subjectId, $body, $pinned): string { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $record = $this->support->load($context, $type, $subjectId, CrmPermission::Edit, lock: true);
            $id     = $this->support->id();
            $this->support->store->add(new Note($id, $context->tenant->value, $type, $subjectId, $body, $pinned, $context->actor->value, $context->now)); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            if ($pinned) {
                $this->limitPins($context, $type, $subjectId, $id);
            }
            $this->support->activity($context, $type, $subjectId, ActivityKind::NoteAdded, ['note' => $id]);
            $effects->event(new NoteAdded($context->tenant, $context->actor, $context->now, $type, $subjectId, $id));
            $this->mention($effects, $context, $record, $id, null, $body);
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
            [$note, $record] = $this->note($context, $id);
            if ($note->getVersion() !== $expectedVersion) {
                throw new ConflictException(ConflictException::STALE, $id);
            }
            $before = $note->getBody();
            $note->edit($body, $pinned, $context->now);
            if ($pinned) {
                $this->limitPins($context, $note->getSubjectType(), $note->getSubjectId(), $id);
            }
            $this->mention($effects, $context, $record, $id, $before, $body);
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
            [$note] = $this->note($context, $id);
            $this->support->store->remove($note); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
            $this->support->activity($context, $note->getSubjectType(), $note->getSubjectId(), ActivityKind::NoteDeleted, ['note' => $id]);
            $this->support->audit($effects, $context, 'crm.note.deleted', $note->getSubjectType(), $note->getSubjectId());
        });
    }

    /**
     * Pins or unpins a note (0.11.0): `edit` on the record (anyone who may edit it, not only the author).
     */
    public function pin(string $id, bool $pinned, int $expectedVersion): void
    {
        $context = $this->support->access->base();
        $this->support->write(function (Effects $effects) use ($context, $id, $pinned, $expectedVersion): void { // @igor-ignore - the runner calls this closure at once and keeps nothing
            $note = $this->find($context, $id);
            $this->support->load($context, $note->getSubjectType(), $note->getSubjectId(), CrmPermission::Edit, lock: true);
            if ($note->getVersion() !== $expectedVersion) {
                throw new ConflictException(ConflictException::STALE, $id);
            }
            $unpinned = [];
            if ($note->isPinned() !== $pinned) {
                $note->pin($pinned, $context->now);
            }
            if ($pinned) {
                $unpinned = $this->limitPins($context, $note->getSubjectType(), $note->getSubjectId(), $id);
            }
            $effects->event(new NotePinned($context->tenant, $context->actor, $context->now, $note->getSubjectType(), $note->getSubjectId(), $id, $pinned, $unpinned));
            $this->support->audit($effects, $context, $pinned ? 'crm.note.pinned' : 'crm.note.unpinned', $note->getSubjectType(), $note->getSubjectId(), ['note' => $id, 'unpinned' => count($unpinned)]);
        });
    }

    /**
     * Keeps at most `maxPinned` pinned notes on the record (0 = no limit): `$keep` stays pinned, then the newest other
     * pinned notes; the rest are unpinned. The record is locked by the caller, so two pins never both stay. Returns the
     * unpinned note ids.
     *
     * @return list<string>
     */
    private function limitPins(CrmContext $context, SubjectType $type, string $subjectId, string $keep): array
    {
        if ($this->maxPinned < 1) {
            return [];
        }
        $others = [];
        foreach ($this->support->store->notes($context->tenant, $type, $subjectId) as $note) {
            if ($note->isPinned() && $note->getId() !== $keep) {
                $others[] = $note;
            }
        }
        $unpinned = [];
        foreach (array_slice($others, $this->maxPinned - 1) as $note) {
            $note->pin(false, $context->now);
            $unpinned[] = $note->getId();
        }

        return $unpinned;
    }

    /**
     * Announces the members newly mentioned by this save (0.11.0, after the commit).
     */
    private function mention(Effects $effects, CrmContext $context, CrmRecord $record, string $noteId, ?string $before, string $body): void
    {
        $mentioned = $this->mentions?->recipients($context, $record, $before, $body) ?? [];
        if ($mentioned !== []) {
            $effects->event(new NoteMentioned($context->tenant, $context->actor, $context->now, $record::type(), $record->getId(), $noteId, $mentioned));
        }
    }

    /**
     * A note the actor may change, with its record: `edit` on the record, and `manage` for someone else's note.
     *
     * @return array{Note, CrmRecord}
     */
    private function note(CrmContext $context, string $id): array
    {
        $note   = $this->find($context, $id);
        $record = $this->support->load($context, $note->getSubjectType(), $note->getSubjectId(), CrmPermission::Edit, lock: true);
        if ($note->getAuthorId() !== $context->actor->value) {
            $this->support->access->require($context, CrmPermission::Manage);
        }

        return [$note, $record];
    }

    /**
     * A note of the tenant.
     */
    private function find(CrmContext $context, string $id): Note
    {
        $note = RecordId::isValid($id) ? $this->support->store->note($context->tenant, $id) : null;
        if (!$note instanceof Note) {
            throw new NotAvailableException();
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
