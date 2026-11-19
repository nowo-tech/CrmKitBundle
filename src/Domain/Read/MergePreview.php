<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Read;

/**
 * What a merge would do.
 */
final readonly class MergePreview
{
    /**
     * @param list<MergeField> $fields
     * @param array<string, int> $moves child rows that move, per kind
     */
    public function __construct(
        public RecordView $survivor,
        public RecordView $other,
        public array $fields,
        public array $moves,
    ) {
    }
}
