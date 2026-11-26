<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Read;

/**
 * A page of a record's activity feed (0.11.0), newest first, and the cursor of the next page (null: none).
 */
final readonly class Feed
{
    /**
     * @param list<FeedItem> $items
     */
    public function __construct(public array $items, public ?string $next)
    {
    }
}
