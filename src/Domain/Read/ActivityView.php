<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Read;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Enum\ActivityKind;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;

/**
 * A timeline entry.
 */
final readonly class ActivityView
{
    /**
     * @param string|null $subjectLabel the record when the entry is about another record than the page
     * @param array<string, bool|int|string|null> $params
     */
    public function __construct(
        public string $id,
        public SubjectType $subjectType,
        public string $subjectId,
        public ?string $subjectLabel,
        public ActivityKind $kind,
        public ?string $actorName,
        public array $params,
        public DateTimeImmutable $occurredAt,
    ) {
    }
}
