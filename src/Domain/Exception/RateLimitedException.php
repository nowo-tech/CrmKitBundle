<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Exception;

use RuntimeException;

/**
 * Too many calls of a person in the current window (0.11.0, the mention endpoints): 429 with `Retry-After`.
 */
class RateLimitedException extends RuntimeException implements CrmException
{
    /**
     * @param int $retryAfter seconds until the window ends
     */
    public function __construct(public readonly int $retryAfter = 60)
    {
        parent::__construct('Too many requests.');
    }
}
