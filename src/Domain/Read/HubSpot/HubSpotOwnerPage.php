<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Read\HubSpot;

use Nowo\CrmKitBundle\Domain\Value\HubSpotCursor;

/**
 * One page of HubSpot owners (0.10.0).
 */
final readonly class HubSpotOwnerPage
{
    /**
     * @param list<HubSpotOwner> $owners
     */
    public function __construct(public array $owners, public ?HubSpotCursor $next)
    {
    }
}
