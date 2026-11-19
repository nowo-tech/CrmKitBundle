<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Fixtures;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * Connection of {@see QueryCounter}: a statement counts when it is prepared or queried directly.
 */
final class QueryCountingConnection extends AbstractConnectionMiddleware
{
    public function __construct(Connection $connection, private readonly QueryLog $counter)
    {
        parent::__construct($connection);
    }

    public function prepare(string $sql): Statement
    {
        $this->counter->record($sql);

        return parent::prepare($sql);
    }

    public function query(string $sql): Result
    {
        $this->counter->record($sql);

        return parent::query($sql);
    }
}
