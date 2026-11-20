<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Read\Pipeline;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Enum\NextActionStatus;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;

/**
 * A next action (0.3.0). The task is shown only when the task port shows it to this actor (else `taskHidden`, with
 * no type and no id). The title is untrusted text.
 */
final readonly class NextActionView
{
    /**
     * Wires the view.
     */
    public function __construct(
        public string $id,
        public SubjectType $subjectType,
        public string $subjectId,
        public string $title,
        public DateTimeImmutable $dueAt,
        public ?string $assigneeId,
        public NextActionStatus $status,
        public ?string $taskType,
        public ?string $taskId,
        public bool $taskHidden,
        public ?string $taskUrl,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
