<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Enum;

/**
 * What an import does with a row whose contact e-mail already exists.
 */
enum DuplicatePolicy: string
{
    case Skip   = 'skip';
    case Update = 'update';
}
