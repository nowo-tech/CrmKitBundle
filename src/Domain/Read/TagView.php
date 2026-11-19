<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Read;

/**
 * A tag as lists and forms show it.
 */
final readonly class TagView
{
    /**
     * @param string $colour UiKit palette token
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $colour,
        public int $version,
    ) {
    }
}
