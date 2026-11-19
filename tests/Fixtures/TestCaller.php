<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Fixtures;

use Nowo\CrmKitBundle\Application\Access\CrmCallerInterface;
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\AuditOrigin;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * A caller of the tests that acts as the session's but carries an API token (channel `api`): the services bound to it
 * audit the token and mark their timeline and stage history "via api".
 */
final readonly class TestCaller implements CrmCallerInterface
{
    public function __construct(private CrmCallerInterface $inner, private AuditOrigin $origin)
    {
    }

    public function tenant(): ?TenantRef
    {
        return $this->inner->tenant();
    }

    public function actor(): ?ActorRef
    {
        return $this->inner->actor();
    }

    public function isGranted(CrmPermission $permission, TenantRef $tenant, ?SubjectRef $subject = null): bool
    {
        return $this->inner->isGranted($permission, $tenant, $subject);
    }

    public function visibleOwners(TenantRef $tenant, ActorRef $actor): ?array
    {
        return $this->inner->visibleOwners($tenant, $actor);
    }

    public function channel(): string
    {
        return $this->origin->channel;
    }

    public function origin(): AuditOrigin
    {
        return $this->origin;
    }

    public function selfOwners(TenantRef $tenant): ?array
    {
        return null;
    }
}
