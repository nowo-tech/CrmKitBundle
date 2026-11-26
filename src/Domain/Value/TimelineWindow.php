<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

use function base64_decode;
use function base64_encode;
use function explode;
use function is_numeric;
use function rtrim;
use function strlen;
use function strtr;

/**
 * A page of a record's activity feed (0.11.0): at most `limit` entries (1–100) strictly before the keyset position
 * `before` (time, then id, newest first); null starts with the newest. The host source is asked for the same window.
 */
final readonly class TimelineWindow
{
    /** Most entries per page. */
    public const MAX_LIMIT = 100;

    /**
     * @param DateTimeImmutable|null $before the time of the last entry of the previous page
     * @param string|null $beforeId the id of that entry (ties on the same time)
     *
     * @throws InvalidArgumentException a limit outside 1–100
     */
    public function __construct(public int $limit = 30, public ?DateTimeImmutable $before = null, public ?string $beforeId = null)
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException('A feed page holds 1 to 100 entries.');
        }
    }

    /**
     * Whether an entry at `$at` with `$id` comes after the window's position (is on this page or a later one).
     */
    public function admits(DateTimeImmutable $at, string $id): bool
    {
        if (!$this->before instanceof DateTimeImmutable) {
            return true;
        }
        $t = [(int) $at->format('U'), (int) $at->format('u')];
        $b = [(int) $this->before->format('U'), (int) $this->before->format('u')];

        return $t < $b || ($t === $b && $this->beforeId !== null && $id < $this->beforeId);
    }

    /**
     * The opaque cursor of the page after `$at` / `$id` (URL safe).
     */
    public static function cursor(DateTimeImmutable $at, string $id): string
    {
        return rtrim(strtr(base64_encode($at->setTimezone(new DateTimeZone('UTC'))->format('U.u') . '|' . $id), '+/', '-_'), '=');
    }

    /**
     * The window after a cursor ({@see self::cursor()}); a malformed cursor starts with the newest entries.
     */
    public static function after(?string $cursor, int $limit = 30): self
    {
        if ($cursor === null || $cursor === '' || strlen($cursor) > 300) {
            return new self($limit);
        }
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($raw === false) {
            return new self($limit);
        }
        $parts = explode('|', $raw, 2);
        if (!isset($parts[1]) || !is_numeric($parts[0]) || $parts[1] === '' || strlen($parts[1]) > 128) {
            return new self($limit);
        }
        $at = DateTimeImmutable::createFromFormat('U.u', $parts[0], new DateTimeZone('UTC'));

        return $at instanceof DateTimeImmutable ? new self($limit, $at, $parts[1]) : new self($limit);
    }
}
