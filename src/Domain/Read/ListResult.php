<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Read;

/**
 * One page of a list.
 */
final readonly class ListResult
{
    /**
     * @param list<RecordView> $rows
     * @param int $total all matching rows
     */
    public function __construct(
        public array $rows,
        public int $total,
    ) {
    }
}
