<?php

declare(strict_types=1);

namespace App\Crm;

use Nowo\CrmKitBundle\Domain\Port\CrmActorDirectoryInterface;
use Nowo\CrmKitBundle\Domain\Value\ActorProfile;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * Demo people: the three fixed users of the in-memory provider; Mia and Ada own records. Stateless.
 */
final readonly class DemoActors implements CrmActorDirectoryInterface
{
    private const PEOPLE = ['ana' => 'Ana Viewer', 'mia' => 'Mia Sales', 'admin' => 'Ada Admin'];

    public function actor(TenantRef $tenant, ActorRef $actor): ?ActorProfile
    {
        $name = self::PEOPLE[$actor->value] ?? null;

        return $name === null ? null : new ActorProfile($actor, $name, $actor->value . '@demo.test');
    }

    public function owners(TenantRef $tenant): array
    {
        return [new ActorProfile(new ActorRef('mia'), self::PEOPLE['mia']), new ActorProfile(new ActorRef('admin'), self::PEOPLE['admin'])];
    }
}
