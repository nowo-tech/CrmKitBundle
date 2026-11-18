<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Exception;

use Throwable;

/**
 * Marker of every exception the CRM throws on purpose.
 */
interface CrmException extends Throwable
{
}
