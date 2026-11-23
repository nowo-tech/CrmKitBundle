<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Port;

use Nowo\CrmKitBundle\Domain\Value\HostTypeLabel;

/**
 * Optional capability of a host object resolver (0.7.0): the human name of its type in the link form ("Project"
 * instead of `project`). A {@see CrmHostObjectResolverInterface} may also implement it; CrmKit checks with
 * `instanceof` on every call (nothing is cached). Without it the form shows `type()`.
 */
interface CrmHostObjectTypeLabelInterface
{
    /**
     * The label of the type: a translation key and domain, or a plain text.
     */
    public function typeLabel(): HostTypeLabel;
}
