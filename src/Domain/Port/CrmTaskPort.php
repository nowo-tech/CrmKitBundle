<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Port;

use Nowo\CrmKitBundle\Domain\Exception\CrmTaskRefusedException;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\CrmTaskRef;
use Nowo\CrmKitBundle\Domain\Value\CrmTaskRequest;
use Nowo\CrmKitBundle\Domain\Value\CrmTaskState;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * Where the next actions of the CRM live as tasks (0.3.0): the built-in list by default, a TaskBoard board through the
 * bridge, or the host's own service (`pipeline.task_port`). Called outside any CRM transaction, before the CRM's write
 * unit (REQ-RUNTIME-003 rule 9). Stateless: nothing may be kept between calls (worker mode). An adapter must never
 * widen what the caller may do: with `CrmTaskRequest::$hostContext` set, it re-applies the same token.
 */
interface CrmTaskPort
{
    /**
     * Creates the task, or returns the one already created for `$request->dedupeKey` (MUST be idempotent on it).
     *
     * @throws CrmTaskRefusedException when the task cannot be created (its code, and whether a retry may succeed)
     */
    public function create(CrmTaskRequest $request): CrmTaskRef;

    /**
     * The states of tasks this actor may see; refs it may not see are absent (shown as hidden).
     *
     * @param list<CrmTaskRef> $refs
     *
     * @return array<string, CrmTaskState> keyed by {@see CrmTaskRef::key()}
     */
    public function states(TenantRef $tenant, ActorRef $actor, ?object $hostContext, array $refs): array;
}
