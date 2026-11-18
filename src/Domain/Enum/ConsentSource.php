<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Enum;

/**
 * Where a consent record came from (contract section 16.1).
 */
enum ConsentSource: string
{
    case Form   = 'form';
    case Import = 'import';
    case Manual = 'manual';
    case Portal = 'portal';
    case Api    = 'api';
}
