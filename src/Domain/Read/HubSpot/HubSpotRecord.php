<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Read\HubSpot;

use Nowo\CrmKitBundle\Domain\Value\HubSpotObjectId;

/**
 * One HubSpot company or contact as read (0.10.0): its id and the requested properties only (null or absent: HubSpot
 * has no value). Lives for one page; never stored.
 */
final readonly class HubSpotRecord
{
    /**
     * @param array<string, string|null> $properties property => value
     */
    public function __construct(public HubSpotObjectId $id, public array $properties)
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
