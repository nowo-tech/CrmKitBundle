<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Import;

/**
 * A parsed import file: its columns and its rows (column => cell, text only).
 */
final readonly class ParsedFile
{
    /**
     * @param list<string> $columns
     * @param list<array<string, string>> $rows
     */
    public function __construct(public array $columns, public array $rows)
    {
    }
}
