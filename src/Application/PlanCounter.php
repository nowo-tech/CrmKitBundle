<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application;

use Nowo\CrmKitBundle\Application\Port\CrmStoreInterface;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\ConflictException;
use Nowo\CrmKitBundle\Domain\Port\CrmPlanLimitsInterface;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * Plan-limit counters (contract section 15.1): one row per tenant and resource, moved only by atomic UPDATEs in the
 * same transaction as the record, so two creates at the limit cannot both pass. Stateless.
 *
 * @internal
 */
final readonly class PlanCounter
{
    /**
     * Wires the collaborators.
     */
    public function __construct(private CrmStoreInterface $store, private CrmPlanLimitsInterface $limits)
    {
    }

    /**
     * Takes one unit for a new (or restored) record, or refuses with 409 `crm_limit_reached`.
     *
     * @throws ConflictException
     */
    public function take(TenantRef $tenant, SubjectType $type): void
    {
        if (!$this->tryTake($tenant, $type)) {
            throw new ConflictException(ConflictException::LIMIT);
        }
    }

    /**
     * Takes one unit; false at the limit.
     */
    public function tryTake(TenantRef $tenant, SubjectType $type): bool
    {
        $resource = $type->resource();

        return $this->store->takeCounter($tenant, $resource->value, $this->limits->limit($tenant, $resource), fn (): int => $this->store->countActive($tenant, $type));
    }

    /**
     * Gives one unit back (trash, merge, purge, erasure).
     */
    public function release(TenantRef $tenant, SubjectType $type): void
    {
        $this->store->releaseCounter($tenant, $type->resource()->value);
    }

    /**
     * Recounts the active records of a tenant (inside the caller's unit).
     */
    public function rebuild(TenantRef $tenant, SubjectType $type): int
    {
        // Lock the counter row first, then count: a create racing the rebuild waits for it (REQ-RUNTIME-003).
        $this->store->lockCounter($tenant, $type->resource()->value); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service
        $count = $this->store->countActive($tenant, $type);
        $this->store->setCounter($tenant, $type->resource()->value, $count); // @igor-ignore - persistence or I/O call on a stateless collaborator; nothing is kept on the service

        return $count;
    }
}
