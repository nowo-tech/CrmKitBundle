<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Operation;

use Nowo\CrmKitBundle\Api\CrmApiCapability;
use Nowo\CrmKitBundle\Api\CrmApiCode;
use Nowo\CrmKitBundle\Api\CrmApiPrincipal;
use Nowo\CrmKitBundle\Api\CrmApiRefusal;
use Nowo\CrmKitBundle\Application\Access\CrmCallerInterface;
use Nowo\CrmKitBundle\Application\CrmAccess;
use Nowo\CrmKitBundle\Application\CrmContext;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;

/**
 * One API call after the reach, tenant and feature checks (0.3.0): the principal, its caller, the bound access gate
 * and the call's context, with the remaining checks of design §4.5 in their order: grant ({@see self::grant()},
 * `TOKEN_001`), then the person ({@see self::person()}, `ACCESS_002`). Lives for one call.
 */
final readonly class CrmApiCall
{
    /**
     * Wires the call.
     */
    public function __construct(
        public CrmApiPrincipal $principal,
        public CrmCallerInterface $caller,
        public CrmAccess $access,
        public CrmContext $context,
    ) {
    }

    /**
     * Requires the grant to cover the capability (403 `TOKEN_001`).
     */
    public function grant(CrmApiCapability $capability): void
    {
        if (!$capability->allows($this->principal->grant)) {
            throw CrmApiRefusal::of(CrmApiCode::TOKEN);
        }
    }

    /**
     * Requires the person's permission, tenant-wide or on one record (403 `ACCESS_002`).
     */
    public function person(CrmPermission $permission, ?SubjectRef $subject = null): void
    {
        if (!$this->caller->isGranted($permission, $this->context->tenant, $subject)) {
            throw CrmApiRefusal::denied();
        }
    }

    /**
     * Requires the grant, then the person's permission behind it.
     */
    public function require(CrmApiCapability $capability, ?SubjectRef $subject = null): void
    {
        $this->grant($capability);
        $this->person($capability->permission(), $subject);
    }

    /**
     * Whether both the grant and the person allow the capability (amounts shown, …).
     */
    public function can(CrmApiCapability $capability, ?SubjectRef $subject = null): bool
    {
        return $capability->allows($this->principal->grant) && $this->caller->isGranted($capability->permission(), $this->context->tenant, $subject);
    }
}
