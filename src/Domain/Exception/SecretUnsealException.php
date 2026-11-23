<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Exception;

use RuntimeException;

/**
 * A sealed secret did not open (0.10.0): unknown or wrong key, tampering, or another context. The message never holds
 * the secret, the ciphertext or the key.
 */
final class SecretUnsealException extends RuntimeException implements CrmException
{
    /**
     * A constant message.
     */
    public function __construct()
    {
        parent::__construct('The secret does not open.');
    }
}
