<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application\Pipeline;

use Nowo\CrmKitBundle\Domain\Port\CrmTaskPort;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\CrmTaskRef;
use Nowo\CrmKitBundle\Domain\Value\CrmTaskRequest;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * The default {@see CrmTaskPort} (0.3.0): the next action is its own task (the minimal built-in list), so nothing is
 * created elsewhere: the task type is `crm` and the CRM stores the next action's own id as the task id. The CRM reads
 * these states from its own rows; `states()` knows no other task. Stateless.
 */
final readonly class BuiltInCrmTaskPort implements CrmTaskPort
{
    /** Task type of the built-in list. */
    public const TYPE = 'crm';

    /**
     * The built-in task of the request (keyed by its dedupe key until the CRM stores the row).
     */
    public function create(CrmTaskRequest $request): CrmTaskRef
    {
        return new CrmTaskRef(self::TYPE, $request->dedupeKey);
    }

    /**
     * None: the CRM reads its built-in tasks itself.
     */
    public function states(TenantRef $tenant, ActorRef $actor, ?object $hostContext, array $refs): array
    {
        return [];
    }
}
