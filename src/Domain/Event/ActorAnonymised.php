<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Event;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * A host person's references in one tenant (owner, author, created_by / updated_by, timeline and merge actor) were
 * replaced by the anonymised placeholder (0.2.1). Dispatched once per tenant after the last batch of that tenant
 * committed; it carries the count only, never the person's id (that id is what was removed).
 */
final class ActorAnonymised extends CrmEvent
{
    /**
     * @param int $references references replaced in the tenant
     */
    public function __construct(TenantRef $tenant, DateTimeImmutable $occurredAt, public readonly int $references)
    {
        parent::__construct($tenant, null, $occurredAt);
    }
}
