<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

/**
 * One answer of the link picker (0.7.0): a host object the actor may link the record to, with the label its resolver
 * gives it.
 */
final readonly class HostObjectMatch
{
    /**
     * @param HostObjectRef $ref the object, its id exactly as the resolver confirmed it
     * @param string $label what to show
     */
    public function __construct(public HostObjectRef $ref, public string $label)
    {
    }
}
