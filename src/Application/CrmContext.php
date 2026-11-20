<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Application\Access\SessionCaller;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\AuditOrigin;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * The tenant, the actor and "now" of one call, resolved by {@see CrmAccess}, and **(0.3.0)** the token behind it when
 * the call came through the API. Never kept between calls.
 */
final readonly class CrmContext
{
    /**
     * Wires the call's context.
     */
    public function __construct(public TenantRef $tenant, public ActorRef $actor, public DateTimeImmutable $now, public ?AuditOrigin $origin = null, public ?object $hostContext = null)
    {
    }

    /**
     * `web` for the CRM's pages, else the origin's channel (`api`, `mcp`, …).
     */
    public function channel(): string
    {
        return $this->origin instanceof AuditOrigin ? $this->origin->channel : SessionCaller::CHANNEL;
    }
}
