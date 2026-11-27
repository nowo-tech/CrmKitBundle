<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Read\HubSpot;

use Nowo\CrmKitBundle\Domain\Value\HubSpotCursor;

/**
 * One page of HubSpot engagements of one kind (0.13.0) and the cursor of the next one (null: the last page).
 */
final readonly class HubSpotEngagementPage
{
    /**
     * @param list<HubSpotEngagement> $engagements
     */
    public function __construct(public array $engagements, public ?HubSpotCursor $next)
    {
    }
}
