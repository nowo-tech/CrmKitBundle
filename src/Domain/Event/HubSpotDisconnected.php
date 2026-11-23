<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Event;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\HubSpotAccountId;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * A tenant's HubSpot connection was removed with its sealed token (0.10.0).
 */
final class HubSpotDisconnected extends CrmEvent
{
    /**
     * Wires the event.
     */
    public function __construct(TenantRef $tenant, ?ActorRef $actor, DateTimeImmutable $occurredAt, public readonly HubSpotAccountId $accountId)
    {
        parent::__construct($tenant, $actor, $occurredAt);
    }
}
