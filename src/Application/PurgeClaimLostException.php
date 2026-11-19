<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application;

use RuntimeException;

/**
 * Another purge run took the single-run claim over (this run was slower than the stale limit): this run stops at
 * its next batch, its committed batches stay, and the other run's claim is left alone.
 *
 * @internal
 */
final class PurgeClaimLostException extends RuntimeException
{
    /**
     * A constant message.
     */
    public function __construct()
    {
        parent::__construct('The purge claim was taken over by another run.');
    }
}
