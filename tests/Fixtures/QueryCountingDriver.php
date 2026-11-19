<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Fixtures;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use SensitiveParameter;

/**
 * Driver of {@see QueryCounter}.
 */
final class QueryCountingDriver extends AbstractDriverMiddleware
{
    public function __construct(Driver $driver, private readonly QueryLog $counter)
    {
        parent::__construct($driver);
    }

    public function connect(#[SensitiveParameter] array $params): Connection
    {
        return new QueryCountingConnection(parent::connect($params), $this->counter);
    }
}
