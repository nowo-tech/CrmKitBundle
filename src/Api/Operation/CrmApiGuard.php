<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Operation;

use Nowo\CrmKitBundle\Api\CrmApiOperation;
use Nowo\CrmKitBundle\Api\CrmApiPrincipal;
use Nowo\CrmKitBundle\Api\CrmApiRefusal;
use Nowo\CrmKitBundle\Api\Port\CrmApiCallerFactoryInterface;
use Nowo\CrmKitBundle\Application\CrmAccess;
use Nowo\CrmKitBundle\Domain\Exception\NotAvailableException;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * The first checks of every API call (0.3.0, design §4.5): the operation is available here, the principal's reach
 * includes the tenant, the tenant is known and the CRM is on for it; any of them failing is 404 `ACCESS_001`, the same
 * answer as a missing record. Then {@see CrmApiCall} checks the grant (`TOKEN_001`) and the person (`ACCESS_002`).
 * Stateless.
 */
final readonly class CrmApiGuard
{
    /**
     * Wires the collaborators.
     */
    public function __construct(private CrmAccess $access, private CrmApiCallerFactoryInterface $callers, private CrmApiOperations $operations)
    {
    }

    /**
     * The call of `$principal` in `$tenant` for `$operation`; `$hooks` run inside its write units.
     *
     * @throws CrmApiRefusal `ACCESS_001`
     */
    public function call(CrmApiPrincipal $principal, TenantRef $tenant, CrmApiOperation $operation, ?CrmApiUnitHooks $hooks = null): CrmApiCall
    {
        $this->operations->require($operation);
        if (!$principal->reach->reaches($tenant)) {
            throw CrmApiRefusal::notFound();
        }
        $caller = $this->callers->for($principal, $tenant, $hooks);
        $access = $this->access->bind($caller);
        try {
            $context = $access->base();
        } catch (NotAvailableException $e) {
            throw CrmApiRefusal::notFound($e);
        }

        return new CrmApiCall($principal, $caller, $access, $context);
    }

    /**
     * The same call with write-unit hooks (a write, once its idempotency key is known).
     */
    public function withHooks(CrmApiCall $call, CrmApiUnitHooks $hooks): CrmApiCall
    {
        $caller = $this->callers->for($call->principal, $call->context->tenant, $hooks);

        return new CrmApiCall($call->principal, $caller, $this->access->bind($caller), $call->context);
    }
}
