<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Event;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\HubSpotAccountId;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * A HubSpot connection was saved (0.10.0): `replaced` when it replaced the tenant's previous token.
 */
final class HubSpotConnected extends CrmEvent
{
    /**
     * Wires the event.
     */
    public function __construct(TenantRef $tenant, ?ActorRef $actor, DateTimeImmutable $occurredAt, public readonly HubSpotAccountId $accountId, public readonly bool $replaced)
    {
        parent::__construct($tenant, $actor, $occurredAt);
    }
}
