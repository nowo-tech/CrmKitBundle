<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Read\HubSpot;

use Nowo\CrmKitBundle\Domain\Value\HubSpotCursor;
use Nowo\CrmKitBundle\Domain\Value\HubSpotObjectId;

/**
 * One page of object ids (0.10.0): the members of a HubSpot list.
 */
final readonly class HubSpotIdPage
{
    /**
     * @param list<HubSpotObjectId> $ids
     */
    public function __construct(public array $ids, public ?HubSpotCursor $next)
    {
    }
}
