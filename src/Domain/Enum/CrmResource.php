<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Enum;

/**
 * A counted resource of the plan limits (contract section 15.1).
 */
enum CrmResource: string
{
    case Companies = 'companies';
    case Contacts  = 'contacts';
}
