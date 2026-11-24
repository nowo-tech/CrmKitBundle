<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Exception;

use RuntimeException;
use Throwable;

/**
 * The key a sealed secret names cannot be used (0.10.1, audit ZAM-D2): the key id is not configured (any more), or the
 * key behind it fails (a DoctrineEncrypt profile that is unknown or whose key file is missing). Unlike
 * {@see SecretUnsealException} this says nothing about the value itself, so the kit never marks a connection invalid
 * for it: the import refuses with `hubspot_key_unavailable` and the sealed value is kept until the key is back
 * (`nowo:crm-kit:secrets:rotate` leaves it unchanged too). The message names the key id only, never the secret, the
 * ciphertext or the key.
 */
final class SecretKeyUnavailableException extends RuntimeException implements CrmException
{
    /**
     * @param string $keyId the key id of the sealed value (or the key id the box seals with)
     * @param Throwable|null $previous the configuration failure, when there is one
     */
    public function __construct(public readonly string $keyId, ?Throwable $previous = null)
    {
        parent::__construct('The key "' . $keyId . '" of a sealed secret is not available.', 0, $previous);
    }

    /**
     * What may be logged (0.10.1, audit ZAW-I2): the key id and the class of the cause, never its message or trace (an
     * encryptor's trace can hold the value it was given).
     *
     * @return array{key_id: string, cause: class-string<Throwable>|null}
     */
    public function logContext(): array
    {
        $previous = $this->getPrevious();

        return ['key_id' => $this->keyId, 'cause' => $previous instanceof Throwable ? $previous::class : null];
    }
}
