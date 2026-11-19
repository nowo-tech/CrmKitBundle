<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Event;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * A record's tags changed.
 */
final class TagsChanged extends CrmEvent
{
    /**
     * @param SubjectType $type the kind of record
     * @param string $id the record
     * @param list<string> $added tag ids added
     * @param list<string> $removed tag ids removed
     */
    public function __construct(
        TenantRef $tenant,
        ?ActorRef $actor,
        DateTimeImmutable $occurredAt,
        public readonly SubjectType $type,
        public readonly string $id,
        public readonly array $added,
        public readonly array $removed,
    ) {
        parent::__construct($tenant, $actor, $occurredAt);
    }
}
