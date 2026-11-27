<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

use InvalidArgumentException;

/**
 * How long a logged call lasted (0.11.0): whole minutes from 0 to 1440 (one day).
 */
final readonly class ActivityDuration
{
    /** Longest duration, in minutes. */
    public const MAX = 1440;

    /**
     * @throws InvalidArgumentException outside 0–1440
     */
    public function __construct(public int $minutes)
    {
        if ($minutes < 0 || $minutes > self::MAX) {
            throw new InvalidArgumentException('A duration is 0 to 1440 minutes.');
        }
    }
}
