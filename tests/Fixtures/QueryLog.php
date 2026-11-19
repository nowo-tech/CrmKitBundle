<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Fixtures;

/**
 * The statements {@see QueryCounter} saw (one shared log: DoctrineBundle gives every connection its own middleware
 * instance), so a test proves how many queries a call makes.
 */
final class QueryLog
{
    /** @var list<string> */
    public array $sql = [];

    public function record(string $sql): void
    {
        $this->sql[] = $sql;
    }

    /**
     * Statements recorded since the last call, and forgets them.
     *
     * @return list<string>
     */
    public function take(): array
    {
        [$sql, $this->sql] = [$this->sql, []];

        return $sql;
    }
}
