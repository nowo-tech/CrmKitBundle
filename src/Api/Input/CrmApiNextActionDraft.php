<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Input;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Api\CrmApiRefusal;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\RecordId;

/**
 * A next action (0.3.0, `crm_next_action_set`) on a company, contact or deal: title (1–200, untrusted), due instant,
 * assignee (an owner of the directory; default the principal).
 */
final readonly class CrmApiNextActionDraft implements CrmApiInput
{
    /**
     * Wires the draft.
     */
    public function __construct(public SubjectType $type, public RecordId $subjectId, public string $title, public DateTimeImmutable $dueAt, public ?ActorRef $assignee = null)
    {
    }

    /**
     * From an input array (`title`, `due_at`, `assignee`); the subject comes from the path or the tool's arguments.
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(SubjectType $type, RecordId $subjectId, array $data): self
    {
        CrmApiFields::only($data, ['title', 'due_at', 'assignee']);

        return new self($type, $subjectId, CrmApiFields::requiredText($data, 'title', 400), CrmApiDealFields::instant($data, 'due_at') ?? throw CrmApiRefusal::missing('due_at'), CrmApiFields::actor($data, 'assignee'));
    }

    /**
     * {@inheritDoc}
     */
    public function canonical(): array
    {
        return ['type' => $this->type->value, 'subject_id' => $this->subjectId->value, 'title' => $this->title, 'due_at' => $this->dueAt->format('Y-m-d\TH:i:s.uP'), 'assignee' => $this->assignee?->value];
    }
}
