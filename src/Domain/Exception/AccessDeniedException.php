<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Exception;

use RuntimeException;

/**
 * The actor lacks the permission inside an enabled tenant: 403.
 */
final class AccessDeniedException extends RuntimeException implements CrmException
{
    /**
     * A constant message.
     */
    public function __construct()
    {
        parent::__construct('Access denied.');
    }
}
