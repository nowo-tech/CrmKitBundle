<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

/**
 * A host panel on the 360° page (contract section 8.2). The title is a translation key of `$domain`, or plain text
 * when `$domain` is null.
 */
final readonly class Panel
{
    /** Main column. */
    public const MAIN = 'main';

    /** Side column. */
    public const SIDE = 'side';

    /**
     * @param list<PanelItem> $items rows
     * @param int|null $total all matching rows (null: the number of items)
     */
    public function __construct(
        public string $title,
        public array $items = [],
        public ?string $domain = null,
        public ?int $total = null,
        public ?string $moreUrl = null,
        public ?string $emptyText = null,
        public string $position = self::MAIN,
    ) {
    }
}
