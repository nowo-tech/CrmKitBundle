<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application\Pipeline;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Enum\DealStatus;

/**
 * A list of deals (0.3.0): funnel, stages, status (null: open, won and lost; `Trashed`: the trash), owner, company,
 * contact, text in the title (escaped `LIKE`), stale only, and the order: `updated` (newest change first),
 * `expected_close` (soonest first, none last) or `amount` (largest first, none last; only for readers of amounts).
 * 0.10.2: `title` (A to Z), a `direction` for every order (null = the order's own: updated and amount descending,
 * expected close and title ascending; rows without a value stay last either way), owner "Mine" / "No owner", and an
 * expected close day range.
 */
final readonly class DealQuery
{
    public const SORT_UPDATED = 'updated';

    public const SORT_EXPECTED_CLOSE = 'expected_close';

    public const SORT_AMOUNT = 'amount';

    public const SORT_TITLE = 'title';

    /** The orders and their own direction (0.10.2). */
    public const SORTS = [self::SORT_UPDATED => 'desc', self::SORT_EXPECTED_CLOSE => 'asc', self::SORT_AMOUNT => 'desc', self::SORT_TITLE => 'asc'];

    /** `asc` | `desc`: the order's direction. */
    public string $direction;

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
        ?string $direction = null,
        public bool $ownerMe = false,
        public bool $ownerNone = false,
        public ?DateTimeImmutable $expectedCloseFrom = null,
        public ?DateTimeImmutable $expectedCloseTo = null,
    ) {
        $this->direction = $direction === 'asc' || $direction === 'desc' ? $direction : (self::SORTS[$sort] ?? 'desc');
    }

    /**
     * The query for one actor (0.10.2): `ownerMe` becomes that actor's owner id.
     */
    public function forActor(string $actorId): self
    {
        if (!$this->ownerMe) {
            return $this;
        }

        return new self($this->funnelId, $this->stageIds, $this->status, $actorId, $this->companyId, $this->contactId, $this->text, $this->staleOnly, $this->sort, $this->direction, false, $this->ownerNone, $this->expectedCloseFrom, $this->expectedCloseTo);
    }
}
