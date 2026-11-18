<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Exception;

use RuntimeException;

/**
 * No tenant, the CRM is off for the tenant, nobody acts, or the record does not exist in this tenant: 404 (never 403
 * across tenants).
 */
class NotAvailableException extends RuntimeException implements CrmException
{
    /**
     * A constant message (it never says why).
     */
    public function __construct(string $message = 'Not available.')
    {
        parent::__construct($message);
    }
}
