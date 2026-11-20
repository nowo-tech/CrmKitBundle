<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Model\Pipeline;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;

/**
 * The current lifecycle stage of a company or contact in a party funnel (0.3.0): one row per funnel and record.
 */
class PartyStage
{
    private int $version = 1;

    /**
     * Wires the row.
     */
    public function __construct(
        private string $id,
        private string $tenantId,
        private string $funnelId,
        private SubjectType $subjectType,
        private string $subjectId,
        private string $stageId,
        private DateTimeImmutable $enteredAt,
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

    /** The funnel. */
    public function getFunnelId(): string
    {
        return $this->funnelId;
    }

    /** Company or contact. */
    public function getSubjectType(): SubjectType
    {
        return $this->subjectType;
    }

    /** The record. */
    public function getSubjectId(): string
    {
        return $this->subjectId;
    }

    /** The current stage. */
    public function getStageId(): string
    {
        return $this->stageId;
    }

    /** When it entered the current stage. */
    public function getEnteredAt(): DateTimeImmutable
    {
        return $this->enteredAt;
    }

    /** The optimistic version. */
    public function getVersion(): int
    {
        return $this->version;
    }

    /** Moves it to a stage. */
    public function moveTo(string $stageId, DateTimeImmutable $now): void
    {
        $this->stageId   = $stageId;
        $this->enteredAt = $now;
    }
}
