<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Event;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * A link to a host object was removed.
 */
final class HostLinkRemoved extends CrmEvent
{
    /**
     * @param SubjectType $type the kind of record
     * @param string $id the record
     * @param string $hostType the host object type
     * @param string $hostId the host object id
     */
    public function __construct(
        TenantRef $tenant,
        ?ActorRef $actor,
        DateTimeImmutable $occurredAt,
        public readonly SubjectType $type,
        public readonly string $id,
        public readonly string $hostType,
        public readonly string $hostId,
    ) {
        parent::__construct($tenant, $actor, $occurredAt);
    }
}
