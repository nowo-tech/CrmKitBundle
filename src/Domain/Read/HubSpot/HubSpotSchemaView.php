<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Read\HubSpot;

use Nowo\CrmKitBundle\Domain\Read\TagView;

/**
 * What the mapping step of a HubSpot import offers (0.10.0): the properties per object, the chosen lists with their
 * current tag choice, the tenant's tags.
 */
final readonly class HubSpotSchemaView
{
    /**
     * @param array<string, list<HubSpotPropertyView>> $properties object value => rows
     * @param list<array{list: HubSpotList, tag: string}> $lists the chosen lists and their tag choice (tag id or `create`)
     * @param list<TagView> $tags the tenant's tags
     */
    public function __construct(public string $importId, public array $properties, public array $lists, public array $tags, public int $version)
    {
    }
}
