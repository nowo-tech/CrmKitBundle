<?php

declare(strict_types=1);

// Minimal stand-ins of nowo-tech/task-board-bundle's calendar event source API (2.24+), loaded by tests/bootstrap.php
// only when TaskBoard is not installed: the same names, signatures and checks as TaskBoard's own classes, for the
// CRM's meeting source (src/Bridge/TaskBoard/CrmMeetingCalendarSource).

namespace Nowo\TaskBoardBundle\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

use function is_array;
use function is_string;
use function mb_strlen;
use function parse_url;
use function preg_match;
use function preg_replace;
use function sprintf;
use function str_starts_with;
use function strlen;
use function trim;

/**
 * Since 2.14.0: the days a calendar page shows, both included (`Y-m-d`).
 */
final readonly class CalendarDateRange
{
    public function __construct(
        public string $from,
        public string $to,
    ) {
        foreach ([$from, $to] as $date) {
            $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$day instanceof DateTimeImmutable || $day->format('Y-m-d') !== $date) {
                throw new InvalidArgumentException(sprintf('A calendar range needs Y-m-d dates, "%s" given.', $date));
            }
        }
        if ($from > $to) {
            throw new InvalidArgumentException(sprintf('A calendar range starts on or before its end, %s > %s given.', $from, $to));
        }
    }

    public function contains(string $date): bool
    {
        return $date >= $this->from && $date <= $this->to;
    }
}

/**
 * Since 2.14.0: who looks at a board's calendar, as plain values (REQ-ARCH-001 rule 3): the user id (`user_class::getId()`
 * as a string), the board and its tenant key.
 */
final readonly class CalendarViewer
{
    public function __construct(
        public string $userId,
        public string $boardId,
        public ?string $tenantKey = null,
    ) {
    }
}

/**
 * Since 2.24.0: what a calendar page asks a {@see CalendarEventSourceInterface} for: the days it shows (`days`, both included)
 * and the same span as instants, `from` (midnight of the first day) included and `to` (midnight after the last day) excluded,
 * in the calendar's timezone (`calendar.events.timezone`, the PHP default when not set).
 */
final readonly class CalendarEventRange
{
    public DateTimeImmutable $from;

    public DateTimeImmutable $to;

    public function __construct(
        public CalendarDateRange $days,
        public string $timezone,
    ) {
        $zone       = new DateTimeZone($timezone);
        $this->from = new DateTimeImmutable($days->from . ' 00:00:00', $zone);
        $this->to   = (new DateTimeImmutable($days->to . ' 00:00:00', $zone))->modify('+1 day');
    }

    /** Whether the event is on one of the days: an all-day event by its days, a timed one by its instants. */
    public function overlaps(CalendarEvent $event): bool
    {
        if ($event->allDay) {
            return $event->firstDay() <= $this->days->to && $event->lastDay() >= $this->days->from;
        }

        // An event of no duration is on the day of its start.
        return $event->start < $this->to && ($event->end > $this->from || $event->start >= $this->from);
    }
}

/**
 * Since 2.24.0: one event of a {@see CalendarEventSourceInterface}, as the source describes it. The bundle prints the title as
 * given (escaped) and nothing else, so return only what this viewer may read.
 *
 * Times are timezone-safe: `start` and `end` are instants in any timezone; the calendar shows them in its own
 * (`calendar.events.timezone`). A timed event ends at `end` (excluded; `end` equal to `start` is a point in time). An
 * all-day event (`allDay: true`) is the days from the date of `start` to the date of `end`, both included, read in the
 * timezone of each value (never converted), so `2030-03-04` stays that day for every viewer.
 *
 * - `id`: the source's id of the event, 1 to 128 printable characters; unique within the source.
 * - `url`: where the title links to: `https://`, `http://` or a path of this site (`/...`, not `//...`); null: no link.
 * - `color`: `#rgb` or `#rrggbb`, the accent of the event; null: the category's or the default one.
 * - `category`: a CSS-friendly token (`nowo-task-cal__event--{category}`), 1 to 32 of `a-z`, `0-9`, `_`, `-`.
 * - `readOnly`: true (the default) keeps the event in place; false lets the viewer move it when the source implements
 *   {@see CalendarEventReschedulerInterface}.
 * - `version`: an opaque concurrency token sent back with a reschedule (optimistic concurrency); null: none.
 * - `tenantKey`: the tenant of the event. It must be the viewer's (`CalendarViewer::$tenantKey`, null without tenancy),
 *   byte for byte, or the bundle drops the event.
 */
final readonly class CalendarEvent
{
    public const MAX_ID_LENGTH      = 128;
    public const MAX_TITLE_LENGTH   = 200;
    public const MAX_URL_LENGTH     = 2048;
    public const MAX_VERSION_LENGTH = 128;

    public string $title;

    public function __construct(
        public string $id,
        string $title,
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
        public bool $allDay = false,
        public ?string $url = null,
        public ?string $color = null,
        public ?string $category = null,
        public bool $readOnly = true,
        public ?string $version = null,
        public ?string $tenantKey = null,
    ) {
        if (!self::isPrintable($id, self::MAX_ID_LENGTH)) {
            throw new InvalidArgumentException(sprintf('A calendar event needs an id of 1 to %d printable characters.', self::MAX_ID_LENGTH));
        }
        $title = self::cleanTitle($title);
        if (!self::isPrintable($title, self::MAX_TITLE_LENGTH)) {
            throw new InvalidArgumentException(sprintf('A calendar event needs a title of 1 to %d printable characters.', self::MAX_TITLE_LENGTH));
        }
        $this->title = $title;
        if ($allDay ? $end->format('Y-m-d') < $start->format('Y-m-d') : $end < $start) {
            throw new InvalidArgumentException('A calendar event ends on or after its start.');
        }
        if ($url !== null && !$this->isSafeUrl($url)) {
            throw new InvalidArgumentException('A calendar event URL is an http(s) URL or a local path.');
        }
        if ($color !== null && preg_match('/^#(?:[0-9a-fA-F]{3}){1,2}$/D', $color) !== 1) {
            throw new InvalidArgumentException('A calendar event color is #rgb or #rrggbb.');
        }
        if ($category !== null && preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/D', $category) !== 1) {
            throw new InvalidArgumentException('A calendar event category is 1 to 32 of a-z, 0-9, "_" and "-".');
        }
        if ($version !== null && !self::isPrintable($version, self::MAX_VERSION_LENGTH)) {
            throw new InvalidArgumentException(sprintf('A calendar event version is 1 to %d printable characters.', self::MAX_VERSION_LENGTH));
        }
    }

    /** The first day of an all-day event (`Y-m-d`, in the timezone of `start`). */
    public function firstDay(): string
    {
        return $this->start->format('Y-m-d');
    }

    /** The last day of an all-day event (`Y-m-d`, in the timezone of `end`). */
    public function lastDay(): string
    {
        return $this->end->format('Y-m-d');
    }

    /**
     * Since 2.24.0 (audit UW-TB-6): 1 to `$max` characters of valid UTF-8 with no control character (C0 and C1, `\p{Cc}`),
     * no format character (`\p{Cf}`: bidirectional marks, overrides and isolates, the Arabic letter mark U+061C, zero-width
     * characters, the soft hyphen U+00AD, tag characters...), no line or paragraph separator (U+2028, U+2029) and no Hangul
     * filler (U+115F, U+1160, U+3164, U+FFA0; since 2.25.0, audit UW2-2). Used for ids, titles and versions.
     */
    public static function isPrintable(string $value, int $max): bool
    {
        return $value !== '' && mb_strlen($value) <= $max && preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}\x{115F}\x{1160}\x{3164}\x{FFA0}]/u', $value) === 0;
    }

    /**
     * Since 2.25.0 (audit VL-5): a title as the calendar prints it. Format characters (`\p{Cf}`: bidirectional marks,
     * zero-width characters, the soft hyphen, tag characters...), U+2028 / U+2029 and the Hangul fillers are removed;
     * tabs and line breaks become spaces; runs of spaces fold to one and the edges are trimmed. Any other control
     * character is kept, so isPrintable() still refuses it. Invalid UTF-8 is returned as given (and refused).
     */
    public static function cleanTitle(string $title): string
    {
        $clean = preg_replace('/[\p{Cf}\p{Zl}\p{Zp}\x{115F}\x{1160}\x{3164}\x{FFA0}]+/u', '', $title);
        if (!is_string($clean)) {
            return $title;
        }
        $clean = (string) preg_replace('/[\t\n\r]+/', ' ', $clean);

        return trim((string) preg_replace('/ {2,}/', ' ', $clean));
    }

    private function isSafeUrl(string $url): bool
    {
        if (strlen($url) > self::MAX_URL_LENGTH || preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1) {
            return false;
        }
        if (str_starts_with($url, '/')) {
            return !str_starts_with($url, '//');
        }
        $parts = parse_url($url);

        return is_array($parts) && isset($parts['scheme'], $parts['host']) && preg_match('/^https?$/iD', $parts['scheme']) === 1;
    }
}

/**
 * Since 2.24.0: timed events of another part of the application (meetings, calls, bookings...) shown on a board's
 * calendar next to its tasks. A host or another bundle implements it; with `autoconfigure` the service is tagged
 * `nowo_task_board.calendar_event_source` (or tag it by hand). Several sources may exist; each has its own key.
 *
 * Bundle-owned value objects only (REQ-ARCH-001 rule 3). The source owns its data and decides who sees what: it gets the
 * viewer (user id, board id and the board's tenant key) and returns only the events this viewer may read in that tenant.
 * The bundle never adds an event the source did not return for this viewer, and drops an event whose tenant key is not the
 * viewer's, byte for byte.
 *
 * Called once per calendar page and source (never per day or per task). Resolve everything per call: no viewer, tenant or
 * result in a property (FrankenPHP worker mode with the kernel kept between requests, REQ-RUNTIME-002).
 */
interface CalendarEventSourceInterface
{
    /**
     * A stable key of this source, unique in the application: 1 to 64 characters of `a-z`, `0-9`, `_`, `.` and `-`, starting
     * with a letter or a digit. It is sent back with a reschedule and used in the markup (`data-event-source`).
     */
    public function key(): string;

    /**
     * The events of the viewer that overlap the range: a timed event with `start < range.to` and `end > range.from`, an all-day
     * event with a day between `range.days.from` and `range.days.to`. Anything else is dropped.
     *
     * @return iterable<CalendarEvent>
     */
    public function events(CalendarViewer $viewer, CalendarEventRange $range): iterable;
}
