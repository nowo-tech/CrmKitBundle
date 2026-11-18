<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * The tenant, the actor and "now" of one call, resolved by {@see CrmAccess}. Never kept between calls.
 */
final readonly class CrmContext
{
    /**
     * Wires the call's context.
     */
    public function __construct(public TenantRef $tenant, public ActorRef $actor, public DateTimeImmutable $now)
    {
    }
}
