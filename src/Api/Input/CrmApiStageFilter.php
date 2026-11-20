<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Input;

use Nowo\CrmKitBundle\Domain\Value\RecordId;

/**
 * A lifecycle stage of a party funnel (0.3.0): records currently in that stage.
 */
final readonly class CrmApiStageFilter
{
    /**
     * Wires the funnel and the stage.
     */
    public function __construct(public RecordId $funnelId, public RecordId $stageId)
    {
    }

    /**
     * The canonical form.
     *
     * @return array{funnel: string, stage: string}
     */
    public function canonical(): array
    {
        return ['funnel' => $this->funnelId->value, 'stage' => $this->stageId->value];
    }
}
