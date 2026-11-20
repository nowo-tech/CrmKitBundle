<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Exception;

use RuntimeException;
use Throwable;

use function preg_match;

/**
 * A task port refused to create a task (0.3.0). `$errorCode` is the port's own code when it has the shape
 * `ABC_123` (an API host passes `TOKEN_001`, `ACCESS_002`, … through), else null; `$transient` tells whether a retry
 * may succeed (the port was unavailable). Nothing of the CRM was written.
 */
final class CrmTaskRefusedException extends RuntimeException implements CrmException
{
    /** The port's code, or null when it has no `ABC_123` shape. */
    public readonly ?string $errorCode;

    /**
     * @param string|null $code the port's refusal code
     */
    public function __construct(?string $code, public readonly bool $transient = false, ?Throwable $previous = null)
    {
        $this->errorCode = $code !== null && preg_match('/\A[A-Z][A-Z0-9]*_[0-9]{3}\z/', $code) === 1 ? $code : null;
        parent::__construct('The task service refused the task.', 0, $previous);
    }
}
