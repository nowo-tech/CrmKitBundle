<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Read\Pipeline;

use Nowo\CrmKitBundle\Domain\Enum\FunnelKind;
use Nowo\CrmKitBundle\Domain\Enum\StageKind;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;

/**
 * A funnel with its stages in order (0.3.0). The name is untrusted text.
 */
final readonly class FunnelView
{
    /**
     * @param list<StageView> $stages by position (archived stages included, flagged)
     */
    public function __construct(
        public string $id,
        public FunnelKind $kind,
        public ?SubjectType $subjectType,
        public string $name,
        public int $position,
        public bool $archived,
        public int $version,
        public array $stages,
    ) {
    }

    /**
     * One stage of the funnel, or null.
     */
    public function stage(string $id): ?StageView
    {
        foreach ($this->stages as $stage) {
            if ($stage->id === $id) {
                return $stage;
            }
        }

        return null;
    }

    /**
     * The first open stage that is not archived (where new deals start), or null.
     */
    public function firstOpenStage(): ?StageView
    {
        foreach ($this->stages as $stage) {
            if ($stage->kind === StageKind::Open && !$stage->archived) {
                return $stage;
            }
        }

        return null;
    }
}
