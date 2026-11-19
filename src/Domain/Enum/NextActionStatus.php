<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Enum;

/**
 * The status of a next action (0.3.0).
 */
enum NextActionStatus: string
{
    case Open      = 'open';
    case Done      = 'done';
    case Cancelled = 'cancelled';
}
