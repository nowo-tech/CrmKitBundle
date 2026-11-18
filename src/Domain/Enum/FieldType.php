<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Enum;

/**
 * Type of a custom field (contract section 6.4).
 */
enum FieldType: string
{
    case Text     = 'text';
    case Textarea = 'textarea';
    case Number   = 'number';
    case Date     = 'date';
    case Boolean  = 'boolean';
    case Choice   = 'choice';
    case Url      = 'url';
    case Email    = 'email';
}
