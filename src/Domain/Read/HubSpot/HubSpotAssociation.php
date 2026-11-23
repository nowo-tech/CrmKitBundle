<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Read\HubSpot;

use Nowo\CrmKitBundle\Domain\Value\HubSpotObjectId;

/**
 * A contact ↔ company association as read (0.10.0); `primary` is HubSpot's primary company label (type 1).
 */
final readonly class HubSpotAssociation
{
    /**
     * Wires the association.
     */
    public function __construct(public HubSpotObjectId $contact, public HubSpotObjectId $company, public bool $primary)
    {
    }
}
