<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Read\Pipeline;

use DateTimeImmutable;

/**
 * The statistics of a funnel (0.3.0) now or at a past instant, per stage, with the reader's visibility; amounts only
 * with `amounts_view` (`valuesHidden` otherwise). `winRate` is the share of the items that reached the first stage and
 * a won stage; `closed` counts won and lost deals closed in the period, with the lost reasons.
 */
final readonly class FunnelStats
{
    /**
     * @param list<StageStats> $stages by position
     * @param array{won: int, lost: int, reasons: array<string, int>}|null $closed
     */
    public function __construct(
        public string $funnelId,
        public DateTimeImmutable $at,
        public bool $past,
        public array $stages,
        public bool $valuesHidden,
        public ?float $winRate = null,
        public ?array $closed = null,
    ) {
    }
}
