<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Read\Pipeline;

use DateTimeImmutable;

/**
 * The lifecycle stage of a company or contact in one party funnel (0.3.0).
 */
final readonly class PartyStageView
{
    /**
     * Wires the view.
     */
    public function __construct(public string $funnelId, public string $stageId, public DateTimeImmutable $enteredAt, public int $version)
    {
    }
}
