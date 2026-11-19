<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Model;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Enum\ActivityKind;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;

/**
 * A timeline entry (insert only; contract section 6.6). Params hold ids, field names and counts, never note text or
 * contact data.
 */
class Activity
{
    /**
     * @param array<string, bool|int|string|null> $params
     */
    public function __construct(
        private string $id,
        private string $tenantId,
        private SubjectType $subjectType,
        private string $subjectId,
        private ActivityKind $kind,
        private ?string $actorId,
        private array $params,
        private DateTimeImmutable $occurredAt,
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

    /** The kind of record. */
    public function getSubjectType(): SubjectType
    {
        return $this->subjectType;
    }

    /** The record. */
    public function getSubjectId(): string
    {
        return $this->subjectId;
    }

    /** What happened. */
    public function getKind(): ActivityKind
    {
        return $this->kind;
    }

    /** Who did it. */
    public function getActorId(): ?string
    {
        return $this->actorId;
    }

    /**
     * The parameters.
     *
     * @return array<string, bool|int|string|null>
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /** When. */
    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    /** Points it at another record (merge). */
    public function moveTo(string $subjectId): void
    {
        $this->subjectId = $subjectId;
    }
}
