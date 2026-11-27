<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Read\HubSpot;

use Nowo\CrmKitBundle\Domain\Value\HubSpotObjectId;

/**
 * One HubSpot note, call, meeting or task as read (0.12.0): its id, the requested properties only (null or absent:
 * HubSpot has no value) and the ids of the contacts and companies it is associated with (at most 100 of each, in
 * HubSpot's order, distinct). Lives for one page; never stored.
 */
final readonly class HubSpotEngagement
{
    /**
     * @param array<string, string|null> $properties property => value
     * @param list<HubSpotObjectId> $contacts
     * @param list<HubSpotObjectId> $companies
     */
    public function __construct(public HubSpotObjectId $id, public array $properties, public array $contacts = [], public array $companies = [])
    {
    }

    /**
     * A property's value, null when HubSpot has none.
     */
    public function value(string $property): ?string
    {
        return $this->properties[$property] ?? null;
    }
}
