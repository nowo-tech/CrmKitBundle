<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Event;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * An import finished.
 */
final class ImportCompleted extends CrmEvent
{
    /**
     * @param SubjectType $type what it imported
     * @param string $importId the import
     * @param array<string, int> $counts counts per outcome
     */
    public function __construct(
        TenantRef $tenant,
        ?ActorRef $actor,
        DateTimeImmutable $occurredAt,
        public readonly SubjectType $type,
        public readonly string $importId,
        public readonly array $counts,
    ) {
        parent::__construct($tenant, $actor, $occurredAt);
    }
}
