<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application\Shared;

use Nowo\CrmKitBundle\Domain\Exception\CrmException;
use Nowo\TransactionDoctrine\WriteUnit\LockConflictExceptionInterface;
use RuntimeException;
use Throwable;

/**
 * Another writer stored the same unique row at the same moment and one more attempt did not settle it (contract
 * section 15; REQ-RUNTIME-003): a lock conflict, answered 409 + `Retry-After` with `CONFLICT_001`; nothing of this
 * write is stored. The error code names the write (`crm_write_conflict`).
 */
final class WriteConflictException extends RuntimeException implements LockConflictExceptionInterface, CrmException
{
    /**
     * @param string $errorCode the JSON error code of the conflict
     */
    public function __construct(public readonly string $errorCode, ?Throwable $previous = null)
    {
        parent::__construct('Concurrent change.', 0, $previous);
    }
}
