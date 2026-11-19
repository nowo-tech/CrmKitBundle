<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Port;

/**
 * New row ids (UUIDv7 by default).
 */
interface IdGeneratorInterface
{
    /**
     * A new lowercase UUID.
     */
    public function new(): string;
}
