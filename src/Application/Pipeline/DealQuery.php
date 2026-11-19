<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application\Pipeline;

use Nowo\CrmKitBundle\Domain\Enum\DealStatus;

/**
 * A list of deals (0.3.0): funnel, stages, status (null: open, won and lost; `Trashed`: the trash), owner, company,
 * contact, text in the title (escaped `LIKE`), stale only, and the order: `updated` (newest change first),
 * `expected_close` (soonest first, none last) or `amount` (largest first, none last; only for readers of amounts).
 */
final readonly class DealQuery
{
    public const SORT_UPDATED = 'updated';

    public const SORT_EXPECTED_CLOSE = 'expected_close';

    public const SORT_AMOUNT = 'amount';

    /**
     * @param list<string>|null $stageIds
     */
    public function __construct(
        public ?string $funnelId = null,
        public ?array $stageIds = null,
        public ?DealStatus $status = null,
        public ?string $ownerId = null,
        public ?string $companyId = null,
        public ?string $contactId = null,
        public ?string $text = null,
        public bool $staleOnly = false,
        public string $sort = self::SORT_UPDATED,
    ) {
    }
}
