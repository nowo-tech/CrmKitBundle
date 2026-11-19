<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Model\Pipeline;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;

/**
 * One move of an item through a funnel (0.3.0, insert only): from → to, who, when, through which channel, and the
 * seconds the item spent in the stage it left (computed under the item's lock).
 */
class StageChange
{
    /**
     * Wires the change.
     */
    public function __construct(
        private string $id,
        private string $tenantId,
        private SubjectType $itemType,
        private string $itemId,
        private string $funnelId,
        private ?string $fromStageId,
        private string $toStageId,
        private ?string $actorId,
        private ?string $via,
        private DateTimeImmutable $occurredAt,
        private ?int $secondsInFrom,
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

    /** Deal, company or contact. */
    public function getItemType(): SubjectType
    {
        return $this->itemType;
    }

    /** The item. */
    public function getItemId(): string
    {
        return $this->itemId;
    }

    /** The funnel. */
    public function getFunnelId(): string
    {
        return $this->funnelId;
    }

    /** The stage left (null: the item entered the funnel). */
    public function getFromStageId(): ?string
    {
        return $this->fromStageId;
    }

    /** The stage entered. */
    public function getToStageId(): string
    {
        return $this->toStageId;
    }

    /** Who moved it. */
    public function getActorId(): ?string
    {
        return $this->actorId;
    }

    /** `web`, `api`, `mcp`, … (null: the pages). */
    public function getVia(): ?string
    {
        return $this->via;
    }

    /** When. */
    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    /** Seconds spent in the stage left, or null. */
    public function getSecondsInFrom(): ?int
    {
        return $this->secondsInFrom;
    }
}
