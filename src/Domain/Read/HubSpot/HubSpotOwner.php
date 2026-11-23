<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Read\HubSpot;

use Nowo\CrmKitBundle\Domain\Value\EmailAddress;
use Nowo\CrmKitBundle\Domain\Value\HubSpotObjectId;

/**
 * A HubSpot owner (0.10.0): its id and e-mail (null when HubSpot has no usable address); only the e-mail is used, to
 * find the CRM actor of the same address.
 */
final readonly class HubSpotOwner
{
    /**
     * Wires the owner.
     */
    public function __construct(public HubSpotObjectId $id, public ?EmailAddress $email)
    {
    }
}
