<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

use Nowo\CrmKitBundle\Domain\Enum\NextActionStatus;

/**
 * What the task port tells about one task the actor may see (0.3.0): its status and, when the host has one, a local
 * URL to open it.
 */
final readonly class CrmTaskState
{
    /**
     * @param string|null $url a local path (`/…`), or null
     */
    public function __construct(public CrmTaskRef $ref, public NextActionStatus $status, public ?string $url = null)
    {
    }
}
