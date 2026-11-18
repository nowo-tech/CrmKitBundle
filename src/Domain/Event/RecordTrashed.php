<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Event;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * A record moved to the trash.
 */
final class RecordTrashed extends CrmEvent
{
    /**
     * @param SubjectType $type the kind of record
     * @param string $id the record
     */
    public function __construct(
        TenantRef $tenant,
        ?ActorRef $actor,
        DateTimeImmutable $occurredAt,
        public readonly SubjectType $type,
        public readonly string $id,
    ) {
        parent::__construct($tenant, $actor, $occurredAt);
    }
}
