<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Read\Pipeline;

use Nowo\CrmKitBundle\Domain\Enum\StageKind;

/**
 * A funnel stage as the pages and the API read it (0.3.0). The name is untrusted text.
 */
final readonly class StageView
{
    /**
     * Wires the view.
     */
    public function __construct(
        public string $id,
        public string $funnelId,
        public string $name,
        public int $position,
        public StageKind $kind,
        public int $probability,
        public ?int $rotDays,
        public bool $archived,
        public int $version,
    ) {
    }
}
