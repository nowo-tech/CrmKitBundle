<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Read;

/**
 * The party roles and tags of one record, for batch lookups: configured role keys in catalogue order, tag name keys
 * (lowercase, unique per tenant) in byte order.
 */
final readonly class RolesAndTags
{
    /**
     * @param list<string> $roles configured role keys, catalogue order
     * @param list<string> $tags tag name keys, byte order
     */
    public function __construct(public array $roles = [], public array $tags = [])
    {
    }
}
