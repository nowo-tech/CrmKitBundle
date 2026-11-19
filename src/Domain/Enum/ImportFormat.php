<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Enum;

/**
 * File format of an import.
 */
enum ImportFormat: string
{
    case Csv   = 'csv';
    case Vcard = 'vcard';
}
