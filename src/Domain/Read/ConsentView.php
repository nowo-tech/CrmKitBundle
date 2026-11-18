<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Read;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Enum\ConsentSource;

/**
 * A consent decision.
 */
final readonly class ConsentView
{
    /**
     * Wires the view.
     */
    public function __construct(
        public string $id,
        public string $purpose,
        public bool $granted,
        public ConsentSource $source,
        public ?string $evidence,
        public ?string $recordedBy,
        public DateTimeImmutable $recordedAt,
    ) {
    }
}
