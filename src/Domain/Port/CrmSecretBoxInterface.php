<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Port;

use Nowo\CrmKitBundle\Domain\Exception\SecretKeyUnavailableException;
use Nowo\CrmKitBundle\Domain\Exception\SecretUnsealException;
use Nowo\CrmKitBundle\Domain\Value\SealedSecret;
use Nowo\CrmKitBundle\Domain\Value\SecretContext;
use SensitiveParameter;

/**
 * Seals and opens the kit's secrets (0.10.0: the HubSpot token) with authenticated encryption bound to a
 * {@see SecretContext}: a value sealed for one tenant, row or purpose does not open for another. Required while
 * `import.hubspot.enabled`; the default is `SodiumCrmSecretBox` (`secrets.keys`), the DoctrineEncrypt bridge is the
 * house option (`secrets.box`). Implementations keep no plaintext and no key material in a property between calls
 * other than the configured keys.
 */
interface CrmSecretBoxInterface
{
    /**
     * Seals a plaintext for the context with the current key.
     *
     * @throws SecretKeyUnavailableException 0.10.1: the current key cannot be used (a configuration error)
     */
    public function seal(#[SensitiveParameter] string $plaintext, SecretContext $context): SealedSecret;

    /**
     * Opens a sealed value for the context.
     *
     * @throws SecretUnsealException wrong key, tampering, or another context (the value is bad: the connection is
     *                               marked invalid)
     * @throws SecretKeyUnavailableException 0.10.1: the key id is not configured or its key cannot be used; nothing
     *                                       is said about the value, so it is kept (an implementation of 0.10.0 may
     *                                       still throw SecretUnsealException for an unknown key id)
     */
    public function unseal(SealedSecret $sealed, SecretContext $context): string;

    /**
     * The id of the key new seals use (a sealed value under another id is re-sealed by `nowo:crm-kit:secrets:rotate`).
     */
    public function currentKeyId(): string;
}
