<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\CrmKitBundle\Domain\Port\CrmHostActivitySourceInterface;

use function mb_check_encoding;
use function mb_strlen;
use function preg_match;
use function str_starts_with;

/**
 * An entry of the host on a record's activity feed (0.11.0, {@see CrmHostActivitySourceInterface}):
 * a task, a time entry, a linked e-mail, a ticket... The kit prints the title escaped, links the URL only when it is a
 * local path or https, and drops an item of another record than the one asked for.
 */
final readonly class HostActivityItem
{
    /** Longest id and title. */
    public const MAX_ID = 128;

    public const MAX_TITLE = 200;

    /**
     * @param SubjectRef $subject the record the item belongs to (must be the one asked for)
     * @param string $id the host's id of the item, unique within the host source (1–128 printable characters)
     * @param string $title what the feed shows (1–200 printable characters, already translated by the host)
     * @param string|null $url a local path (`/…`, not `//…`) or an https URL; null = no link
     * @param ActorRef|null $actor the member it is by (the person filter)
     * @param string|null $icon an icon name `[prefix:]name` (e.g. `tabler:checkbox`)
     *
     * @throws InvalidArgumentException a malformed id, title, URL or icon
     */
    public function __construct(
        public SubjectRef $subject,
        public string $id,
        public string $title,
        public DateTimeImmutable $at,
        public ?string $url = null,
        public ?ActorRef $actor = null,
        public ?string $icon = null,
    ) {
        if (!$this->printable($id, self::MAX_ID) || !$this->printable($title, self::MAX_TITLE)) {
            throw new InvalidArgumentException('A host activity item needs an id (1-128) and a title (1-200) of printable characters.');
        }
        if ($url !== null && !self::safeUrl($url)) {
            throw new InvalidArgumentException('A host activity item URL is a local path or an https URL.');
        }
        if ($icon !== null && preg_match('/\A(?:[a-z0-9]+(?:-[a-z0-9]+)*:)?[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $icon) !== 1) {
            throw new InvalidArgumentException('A host activity item icon is "[prefix:]name".');
        }
    }

    /**
     * Whether a URL is a local path (not `//`, no backslash) or an https URL with a host, without spaces or controls.
     */
    public static function safeUrl(string $url): bool
    {
        if ($url === '' || mb_strlen($url) > 2048 || preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1) {
            return false;
        }
        if (str_starts_with($url, '/')) {
            return !str_starts_with($url, '//');
        }

        return MeetingPlace::isHttpsUrl($url);
    }

    /**
     * 1 to `$max` characters of UTF-8 without control, format or separator characters.
     */
    private function printable(string $value, int $max): bool
    {
        return $value !== '' && mb_check_encoding($value, 'UTF-8') && mb_strlen($value) <= $max && preg_match('/[\p{Cc}\p{Cf}\x{2028}\x{2029}]/u', $value) === 0;
    }
}
