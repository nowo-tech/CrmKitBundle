<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Doctrine\Api;

use RuntimeException;

/**
 * The idempotency store answered something impossible (a refused insert without a row to read).
 *
 * @internal
 */
final class CrmApiStoreFailure extends RuntimeException
{
    /**
     * A fixed message.
     */
    public function __construct()
    {
        parent::__construct('The idempotency store failed.');
    }
}
