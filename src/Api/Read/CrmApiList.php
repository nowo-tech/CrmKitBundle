<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Read;

use JsonSerializable;

/**
 * A short, complete list (0.3.0): tags, funnels, links.
 */
final readonly class CrmApiList implements JsonSerializable
{
    /**
     * @param list<JsonSerializable> $items
     */
    public function __construct(public array $items)
    {
    }

    /**
     * `{items}`.
     *
     * @return array{items: list<JsonSerializable>}
     */
    public function jsonSerialize(): array
    {
        return ['items' => $this->items];
    }
}
