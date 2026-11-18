<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Enum;

/**
 * What an import did with one row.
 */
enum RowOutcome: string
{
    case Pending   = 'pending';
    case Created   = 'created';
    case Updated   = 'updated';
    case Duplicate = 'duplicate';
    case Invalid   = 'invalid';
    case Limit     = 'limit';
}
