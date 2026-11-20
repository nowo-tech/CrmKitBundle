<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Input;

/**
 * Order of a search (0.3.0): by name (companies first, then contacts) or by last change, newest first.
 */
enum CrmApiSort: string
{
    case Name    = 'name';
    case Updated = 'updated';
}
