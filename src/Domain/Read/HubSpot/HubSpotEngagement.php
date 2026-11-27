<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Read\HubSpot;

use Nowo\CrmKitBundle\Domain\Value\HubSpotObjectId;

/**
 * One HubSpot note, call, meeting or task as read (0.12.0): its id, the requested properties only (null or absent:
 * HubSpot has no value) and the ids of the contacts and companies it is associated with (at most 100 of each, in
 * HubSpot's order, distinct). 0.12.1 (audit ZDE-v-1): also every contact id HubSpot gave inline, uncapped, for the
 * erased-person check. Lives for one page; never stored.
 */
final readonly class HubSpotEngagement
{
    /** @var list<HubSpotObjectId> every inline contact id (the capped `$contacts` when not given) */
    public array $allContacts;

    /**
     * @param array<string, string|null> $properties property => value
     * @param list<HubSpotObjectId> $contacts the contacts used as records (capped)
     * @param list<HubSpotObjectId> $companies
     * @param list<HubSpotObjectId>|null $allContacts every inline contact id; null: `$contacts`
     */
    public function __construct(public HubSpotObjectId $id, public array $properties, public array $contacts = [], public array $companies = [], ?array $allContacts = null)
    {
        $this->allContacts = $allContacts ?? $contacts;
    }

    /**
     * A property's value, null when HubSpot has none.
     */
    public function value(string $property): ?string
    {
        return $this->properties[$property] ?? null;
    }
}
