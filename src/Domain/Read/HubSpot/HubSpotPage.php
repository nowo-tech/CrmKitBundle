<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Read\HubSpot;

use Nowo\CrmKitBundle\Domain\Value\HubSpotCursor;

/**
 * One page of HubSpot companies or contacts (0.10.0) and the cursor of the next one (null: the last page).
 */
final readonly class HubSpotPage
{
    /**
     * @param list<HubSpotRecord> $records
     */
    public function __construct(public array $records, public ?HubSpotCursor $next)
    {
    }
}
