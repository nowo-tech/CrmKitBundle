<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Api\Operation;

use RuntimeException;

/**
 * Thrown inside a write unit when its key was already used with the same input (0.3.0): the unit rolls back and the
 * operation answers again from the stored ids through the read path.
 *
 * @internal
 */
final class CrmApiReplay extends RuntimeException
{
    /**
     * @param array<string, string> $ids
     */
    public function __construct(public readonly int $status, public readonly array $ids)
    {
        parent::__construct('Idempotent replay.');
    }
}
