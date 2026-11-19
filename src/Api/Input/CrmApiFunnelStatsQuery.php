<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Input;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\RecordId;
use Nowo\CrmKitBundle\Domain\Value\RoleKey;

/**
 * The statistics of a funnel (0.3.0, `crm_funnel_stats`): now, or counts at a past instant; owner, role and tag
 * filters.
 */
final readonly class CrmApiFunnelStatsQuery
{
    /**
     * Wires the query.
     */
    public function __construct(
        public RecordId $funnelId,
        public ?DateTimeImmutable $at = null,
        public ?ActorRef $ownerId = null,
        public ?RoleKey $role = null,
        public ?RecordId $tagId = null,
    ) {
    }
}
