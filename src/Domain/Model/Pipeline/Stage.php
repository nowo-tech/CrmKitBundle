<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Model\Pipeline;

use Nowo\CrmKitBundle\Domain\Enum\StageKind;

/**
 * A stage of a funnel (0.3.0): its position, kind (open, won, lost), default probability (0..100, a deal may
 * override it) and the optional age limit in days after which an item in it is stale ("rotting").
 */
class Stage
{
    private bool $archived = false;

    private int $version = 1;

    /**
     * Wires the stage.
     */
    public function __construct(
        private string $id,
        private string $tenantId,
        private string $funnelId,
        private string $name,
        private int $position,
        private StageKind $kind,
        private int $probability,
        private ?int $rotDays = null,
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

    /** The name (untrusted). */
    public function getName(): string
    {
        return $this->name;
    }

    /** The position in the funnel. */
    public function getPosition(): int
    {
        return $this->position;
    }

    /** Open, won or lost. */
    public function getKind(): StageKind
    {
        return $this->kind;
    }

    /** The default probability, 0..100. */
    public function getProbability(): int
    {
        return $this->probability;
    }

    /** Days after which an item in the stage is stale (null: never). */
    public function getRotDays(): ?int
    {
        return $this->rotDays;
    }

    /** Whether it is archived (no item may move into it). */
    public function isArchived(): bool
    {
        return $this->archived;
    }

    /** The optimistic version. */
    public function getVersion(): int
    {
        return $this->version;
    }

    /** Changes the stage. */
    public function change(string $name, int $position, StageKind $kind, int $probability, ?int $rotDays, bool $archived): void
    {
        $this->name        = $name;
        $this->position    = $position;
        $this->kind        = $kind;
        $this->probability = $probability;
        $this->rotDays     = $rotDays;
        $this->archived    = $archived;
    }

    /** Moves it to another position. */
    public function moveTo(int $position): void
    {
        $this->position = $position;
    }
}
