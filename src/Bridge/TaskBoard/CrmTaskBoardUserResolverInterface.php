<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Bridge\TaskBoard;

use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * The host's link from a CRM actor to the TaskBoard user the bridge acts as (0.3.0), for next actions written from the
 * CRM's pages (an API call hands the bridge the TaskBoard principal of its own token instead). Null: the person has no
 * TaskBoard user, and the next action is refused. Register it under this interface's name. Stateless.
 */
interface CrmTaskBoardUserResolverInterface
{
    /**
     * The TaskBoard security user of the actor in the tenant, or null.
     */
    public function user(TenantRef $tenant, ActorRef $actor): ?object;
}
