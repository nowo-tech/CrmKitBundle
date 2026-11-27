<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Port;

use Nowo\CrmKitBundle\Domain\Port\CrmHostActivitySourceInterface;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Nowo\CrmKitBundle\Domain\Value\TimelineWindow;

/**
 * The default host activity source (0.11.0): no items.
 */
final readonly class NoHostActivitySource implements CrmHostActivitySourceInterface
{
    /**
     * No items.
     */
    public function items(TenantRef $tenant, ActorRef $viewer, SubjectRef $subject, TimelineWindow $window): array
    {
        return [];
    }
}
