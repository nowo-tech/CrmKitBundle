<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Read\Pipeline;

use Nowo\CrmKitBundle\Domain\Enum\StageKind;

/**
 * The numbers of one stage (0.3.0): items in it, stale ones (now only), and, with amounts, the value and the weighted
 * value per currency (never summed across currencies); null `values` when amounts are hidden. The UI adds the
 * conversion to the next stage and the average time spent in it.
 */
final readonly class StageStats
{
    /**
     * @param list<array{currency: string, sum: string, weighted: string}>|null $values
     */
    public function __construct(
        public string $stageId,
        public string $name,
        public StageKind $kind,
        public int $position,
        public int $count,
        public ?int $stale,
        public ?array $values,
        public ?float $conversion = null,
        public ?int $averageSeconds = null,
    ) {
    }
}
