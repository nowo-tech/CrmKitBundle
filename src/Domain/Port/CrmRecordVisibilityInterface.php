<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Port;

use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * Which records an actor may see in lists, totals, search, duplicates, exports and the related-record blocks
 * (contract section 13, 0.2.0): the same rule as the decision on one record, applied in SQL. Asked per call.
 */
interface CrmRecordVisibilityInterface
{
    /**
     * Null: every record of the tenant. A list: only records without an owner and records owned by one of these host
     * actor ids (compared byte for byte).
     *
     * @return list<string>|null
     */
    public function visibleOwners(TenantRef $tenant, ActorRef $actor): ?array;
}
