<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Enum;

/**
 * Life cycle of a company or contact (contract section 6.3).
 */
enum RecordStatus: string
{
    case Active  = 'active';
    case Trashed = 'trashed';
    case Merged  = 'merged';
    case Erased  = 'erased';
}
