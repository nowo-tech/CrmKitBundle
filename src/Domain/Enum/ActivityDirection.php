<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Enum;

/**
 * Which way a logged call or e-mail went (0.11.0): `in` (the contact called or wrote), `out` (a member did).
 */
enum ActivityDirection: string
{
    case In  = 'in';
    case Out = 'out';
}
