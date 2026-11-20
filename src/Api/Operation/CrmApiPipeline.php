<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Operation;

use Nowo\CrmKitBundle\Application\Pipeline\DealReader;
use Nowo\CrmKitBundle\Application\Pipeline\DealService;
use Nowo\CrmKitBundle\Application\Pipeline\FunnelReader;
use Nowo\CrmKitBundle\Application\Pipeline\FunnelStatistics;
use Nowo\CrmKitBundle\Application\Pipeline\NextActionService;
use Nowo\CrmKitBundle\Application\Pipeline\PartyStageService;

/**
 * The funnel services the operations use (0.3.0), present only with `pipeline.enabled`. Stateless.
 *
 * @internal
 */
final readonly class CrmApiPipeline
{
    /**
     * Wires the services.
     */
    public function __construct(
        public FunnelReader $funnels,
        public DealReader $deals,
        public DealService $dealService,
        public FunnelStatistics $statistics,
        public NextActionService $nextActions,
        public PartyStageService $partyStages,
    ) {
    }
}
