<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Enum;

/**
 * State of an import (contract section 15.4).
 */
enum ImportStatus: string
{
    case Preview   = 'preview';
    case Running   = 'running';
    case Done      = 'done';
    case Failed    = 'failed';
    case Cancelled = 'cancelled';
}
