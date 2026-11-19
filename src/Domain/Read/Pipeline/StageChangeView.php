<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Read\Pipeline;

use DateTimeImmutable;

/**
 * One move through a funnel (0.3.0): from (null: entered the funnel), to, who, through which channel, when, and the
 * seconds spent in the stage left.
 */
final readonly class StageChangeView
{
    /**
     * Wires the view.
     */
    public function __construct(
        public ?string $fromStageId,
        public string $toStageId,
        public ?string $actorId,
        public ?string $via,
        public DateTimeImmutable $occurredAt,
        public ?int $secondsInFrom,
    ) {
    }
}
