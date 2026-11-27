<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

use Nowo\CrmKitBundle\Domain\Enum\FeedKind;

use function in_array;

/**
 * The filter of a record's activity feed (0.11.0): the kinds to show (empty = every kind) and the person who wrote or
 * logged the entries (null = anybody).
 */
final readonly class TimelineFilter
{
    /** @var list<FeedKind> */
    public array $kinds;

    /**
     * @param list<FeedKind> $kinds
     */
    public function __construct(array $kinds = [], public ?ActorRef $person = null)
    {
        $unique = [];
        foreach ($kinds as $kind) {
            if (!in_array($kind, $unique, true)) {
                $unique[] = $kind;
            }
        }
        $this->kinds = $unique;
    }

    /**
     * Whether the kind is shown.
     */
    public function shows(FeedKind $kind): bool
    {
        return $this->kinds === [] || in_array($kind, $this->kinds, true);
    }

    /**
     * Whether an entry by this actor is shown.
     */
    public function by(?string $actor): bool
    {
        return !$this->person instanceof ActorRef || $this->person->value === $actor;
    }
}
