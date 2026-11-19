<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Read;

/**
 * One field of a merge preview.
 */
final readonly class MergeField
{
    /**
     * @param string $field core field or custom.<key>
     * @param bool $takeOther the default choice
     */
    public function __construct(
        public string $field,
        public ?string $survivor,
        public ?string $other,
        public bool $takeOther,
    ) {
    }
}
