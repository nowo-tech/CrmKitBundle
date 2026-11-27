<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Model\Pipeline;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Enum\NextActionStatus;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;

/**
 * A next action on a company, contact or deal (0.3.0): a title, a due time, an assignee and the task that carries it
 * (the built-in list, or a host task through `CrmTaskPort`). Unique per tenant and dedupe key, so a repeated request
 * adopts the row instead of writing a second one.
 */
class NextAction
{
    private NextActionStatus $status = NextActionStatus::Open;

    private ?DateTimeImmutable $doneAt = null;

    private int $version = 1;

    /**
     * Wires the action.
     */
    public function __construct(
        private string $id,
        private string $tenantId,
        private SubjectType $subjectType,
        private string $subjectId,
        private string $title,
        private DateTimeImmutable $dueAt,
        private ?string $assigneeId,
        private ?string $taskType,
        private ?string $taskId,
        private string $dedupeKey,
        private ?string $createdById,
        private DateTimeImmutable $createdAt,
    ) {
    }

    /** The row id. */
    public function getId(): string
    {
        return $this->id;
    }

    /** The tenant. */
    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    /** Company, contact or deal. */
    public function getSubjectType(): SubjectType
    {
        return $this->subjectType;
    }

    /** The subject. */
    public function getSubjectId(): string
    {
        return $this->subjectId;
    }

    /** The title (untrusted). */
    public function getTitle(): string
    {
        return $this->title;
    }

    /** When it is due. */
    public function getDueAt(): DateTimeImmutable
    {
        return $this->dueAt;
    }

    /** Who should do it. */
    public function getAssigneeId(): ?string
    {
        return $this->assigneeId;
    }

    /** Open, done or cancelled. */
    public function getStatus(): NextActionStatus
    {
        return $this->status;
    }

    /** The carrying task's type (`crm` for the built-in list). */
    public function getTaskType(): ?string
    {
        return $this->taskType;
    }

    /** The carrying task's id. */
    public function getTaskId(): ?string
    {
        return $this->taskId;
    }

    /** The dedupe key (64 hex). */
    public function getDedupeKey(): string
    {
        return $this->dedupeKey;
    }

    /** Who created it. */
    public function getCreatedById(): ?string
    {
        return $this->createdById;
    }

    /** When it was created. */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** When it was done or cancelled. */
    public function getDoneAt(): ?DateTimeImmutable
    {
        return $this->doneAt;
    }

    /** The optimistic version. */
    public function getVersion(): int
    {
        return $this->version;
    }

    /**
     * Changes the title, the due time and the assignee (0.13.0: a HubSpot re-run of the task it was imported from).
     */
    public function revise(string $title, DateTimeImmutable $dueAt, ?string $assigneeId): void
    {
        $this->title      = $title;
        $this->dueAt      = $dueAt;
        $this->assigneeId = $assigneeId;
    }

    /** Closes it (done or cancelled). */
    public function close(NextActionStatus $status, DateTimeImmutable $now): void
    {
        $this->status = $status;
        $this->doneAt = $now;
    }
}
