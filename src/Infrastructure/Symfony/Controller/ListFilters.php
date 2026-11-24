<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Controller;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\CrmKitBundle\Domain\Service\ListQuery;

use function array_diff_key;
use function array_filter;
use function array_flip;
use function in_array;
use function is_numeric;
use function is_string;
use function preg_match;

/**
 * Reading the 0.10.2 filter values of a list query (Beacon filter standard): owner "me" / "none", day ranges, and
 * whether the request set a filter at all (the empty state then says "no match" instead of "no records yet", and the
 * header keeps its create action). Stateless.
 *
 * @internal
 */
final class ListFilters
{
    /** Owner filter value: the acting person's records. */
    public const OWNER_ME = 'me';

    /** Owner filter value: records without an owner. */
    public const OWNER_NONE = 'none';

    /** Query keys that are not filters (pages, sort, the keyset cursor). */
    private const NOT_FILTERS = ['page', 'per_page', 'sort', 'direction', 'dir', 'after'];

    /**
     * A day of a range filter (`YYYY-MM-DD`, a real date), or null.
     */
    public static function day(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return null;
        }
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));

        return $day instanceof DateTimeImmutable && $day->format('Y-m-d') === $value ? $day : null;
    }

    /**
     * A page number of the query (audit ZBF-L1): 1 to `ListQuery::MAX_PAGE`; anything else (not numeric, below 1, a
     * huge or exponent value that would overflow an offset) is clamped, never cast blindly.
     */
    public static function page(mixed $value): int
    {
        if (!is_numeric($value)) {
            return 1;
        }
        $page = (float) $value;
        if ($page >= ListQuery::MAX_PAGE) {
            return ListQuery::MAX_PAGE;
        }

        return $page < 1 ? 1 : (int) $page;
    }

    /**
     * Whether the query sets any filter (a non-empty value under a key other than page, per page, sort and cursor;
     * `status=active`, the default, does not count).
     *
     * @param array<array-key, mixed> $query
     */
    public static function any(array $query): bool
    {
        $filters = array_diff_key($query, array_flip(self::NOT_FILTERS));
        if (($filters['status'] ?? null) === 'active') {
            unset($filters['status']);
        }

        return array_filter($filters, static fn (mixed $v): bool => !in_array($v, [null, '', []], true)) !== [];
    }
}
