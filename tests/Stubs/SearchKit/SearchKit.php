<?php

declare(strict_types=1);

// Minimal stand-ins of nowo-tech/search-kit-bundle's provider contract (1.7, PHP >= 8.3), loaded by tests/bootstrap.php
// only when SearchKit is not installed, so the bridge is tested on PHP 8.2 as well. Same names and signatures.

namespace Nowo\SearchKitBundle\Contract {
    use Nowo\SearchKitBundle\Model\SearchGroup;
    use Nowo\SearchKitBundle\Model\SearchQuery;

    interface SearchProviderInterface
    {
        public const TAG = 'nowo_search_kit.provider';

        public function search(SearchQuery $query): ?SearchGroup;
    }
}

namespace Nowo\SearchKitBundle\Model {
    use InvalidArgumentException;

    final readonly class SearchQuery
    {
        public function __construct(
            public string $text,
            public ?object $user,
            public int $limit,
            public ?string $scope = null,
            public bool $belowMinLength = false,
            public ?string $userIdentifier = null,
        ) {
        }
    }

    final readonly class SearchBadge
    {
        public function __construct(public string $label, public string $tone = 'neutral')
        {
        }
    }

    final readonly class SearchItem
    {
        public function __construct(
            public string $title,
            public string $url,
            public string $subtitle = '',
            public ?string $icon = null,
            public ?SearchBadge $badge = null,
            public ?string $kind = null,
        ) {
            if (!str_starts_with($url, '/') || str_starts_with($url, '//')) {
                throw new InvalidArgumentException('A search item URL must be a path.');
            }
        }
    }

    final readonly class SearchGroup
    {
        /**
         * @param list<SearchItem> $items
         */
        public function __construct(
            public string $key,
            public string $label,
            public array $items,
            public ?int $total = null,
            public ?string $moreUrl = null,
            public ?string $moreLabel = null,
        ) {
        }
    }
}
