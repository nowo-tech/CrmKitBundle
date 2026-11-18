<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Port;

use Nowo\CrmKitBundle\Domain\Value\AuditEntry;

/**
 * Where audit lines go (contract section 14); called after the commit.
 */
interface CrmAuditSinkInterface
{
    /**
     * Records one entry.
     */
    public function record(AuditEntry $entry): void;
}
