<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Read;

use JsonSerializable;

/**
 * One page of a keyset list (0.3.0): the items and the cursor of the next page (null on the last page). No total.
 */
final readonly class CrmApiPage implements JsonSerializable
{
    /**
     * @param list<JsonSerializable> $items
     */
    public function __construct(public array $items, public ?string $nextCursor)
    {
    }

    /**
     * `{items, next_cursor}`.
     *
     * @return array{items: list<JsonSerializable>, next_cursor: ?string}
     */
    public function jsonSerialize(): array
    {
        return ['items' => $this->items, 'next_cursor' => $this->nextCursor];
    }
}
