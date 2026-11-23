<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Fixtures;

use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Enum\CrmResource;
use Nowo\CrmKitBundle\Domain\Port\CrmAccessCheckerInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmActorDirectoryInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmFeatureGateInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmPlanLimitsInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmTenantResolverInterface;
use Nowo\CrmKitBundle\Domain\Value\ActorProfile;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * The host ports of the tests, answered from {@see TestContext}.
 */
final readonly class TestPorts implements CrmTenantResolverInterface, CrmAccessCheckerInterface, CrmFeatureGateInterface, CrmPlanLimitsInterface, CrmActorDirectoryInterface
{
    public function __construct(private TestContext $context)
    {
    }

    public function current(): ?TenantRef
    {
        return $this->context->tenant === null ? null : new TenantRef($this->context->tenant);
    }

    public function isGranted(CrmPermission $permission, TenantRef $tenant, ?SubjectRef $subject = null): bool
    {
        return $this->context->actor !== null && $this->context->holds($tenant->value, $this->context->actor, $permission, $subject);
    }

    public function currentActor(): ?ActorRef
    {
        return $this->context->actor === null ? null : new ActorRef($this->context->actor);
    }

    public function isEnabled(TenantRef $tenant): bool
    {
        return $this->context->enabled[$tenant->value] ?? true;
    }

    public function limit(TenantRef $tenant, CrmResource $resource): ?int
    {
        return $this->context->limits[$resource->value] ?? null;
    }

    public function actor(TenantRef $tenant, ActorRef $actor): ?ActorProfile
    {
        $name = $this->context->owners[$tenant->value][$actor->value] ?? null;

        return $name === null ? null : new ActorProfile($actor, $name, $actor->value . '@example.test');
    }

    public function owners(TenantRef $tenant): array
    {
        $owners = [];
        foreach ($this->context->owners[$tenant->value] ?? [] as $id => $name) {
            $owners[] = new ActorProfile(new ActorRef((string) $id), $name, $this->context->emails[$tenant->value][(string) $id] ?? null);
        }

        return $owners;
    }
}
