<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Fixtures;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/**
 * DBAL middleware of the test kernels: every statement the driver prepares or runs directly goes to the
 * {@see QueryLog}.
 */
final class QueryCounter implements Middleware
{
    public function __construct(private readonly QueryLog $log)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        return new QueryCountingDriver($driver, $this->log);
    }
}
