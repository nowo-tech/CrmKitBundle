<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Event;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * Base of the CRM's events (contract section 14): dispatched after the commit, ids and VOs only.
 */
abstract class CrmEvent
{
    /**
     * Wires the common data.
     */
    public function __construct(
        public readonly TenantRef $tenant,
        public readonly ?ActorRef $actor,
        public readonly DateTimeImmutable $occurredAt,
    ) {
    }
}
