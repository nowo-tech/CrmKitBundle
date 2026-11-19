<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Event;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * Two records were merged; the merged one is a tombstone pointing at the survivor.
 */
final class RecordsMerged extends CrmEvent
{
    /**
     * @param SubjectType $type the kind of record
     * @param string $survivorId the record that stays
     * @param string $mergedId the record merged into it
     */
    public function __construct(
        TenantRef $tenant,
        ?ActorRef $actor,
        DateTimeImmutable $occurredAt,
        public readonly SubjectType $type,
        public readonly string $survivorId,
        public readonly string $mergedId,
    ) {
        parent::__construct($tenant, $actor, $occurredAt);
    }
}
