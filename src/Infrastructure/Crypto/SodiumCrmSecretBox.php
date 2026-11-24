<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Crypto;

use LogicException;
use Nowo\CrmKitBundle\Domain\Exception\SecretKeyUnavailableException;
use Nowo\CrmKitBundle\Domain\Exception\SecretUnsealException;
use Nowo\CrmKitBundle\Domain\Port\CrmSecretBoxInterface;
use Nowo\CrmKitBundle\Domain\Value\SealedSecret;
use Nowo\CrmKitBundle\Domain\Value\SecretContext;
use SensitiveParameter;

use function array_key_exists;
use function base64_decode;
use function base64_encode;
use function is_string;
use function preg_match;
use function random_bytes;
use function sodium_crypto_aead_xchacha20poly1305_ietf_decrypt;
use function sodium_crypto_aead_xchacha20poly1305_ietf_encrypt;
use function sodium_memzero;
use function strlen;
use function substr;

use const SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES;
use const SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

/**
 * The default {@see CrmSecretBoxInterface} (0.10.0, design section 8): XChaCha20-Poly1305 (libsodium, `ext-sodium`)
 * with a random 24-byte nonce per seal and the context's {@see SecretContext::aad()} as additional data, so a value
 * copied to another tenant, row or purpose does not open. Keys come from `secrets.keys` (32 bytes each, from the
 * environment); `current_key` seals, every listed key opens (rotation); a key id that is not listed is
 * {@see SecretKeyUnavailableException} (0.10.1), never a bad value. The stored value is base64 of nonce ‖
 * ciphertext. Copies of the plaintext are wiped after use; the keys are the container's, never logged.
 */
final readonly class SodiumCrmSecretBox implements CrmSecretBoxInterface
{
    /**
     * @param array<string, string> $keys key id => 32 raw bytes
     * @param string $current the key id that seals
     *
     * @throws LogicException a key that is not 32 bytes, a bad key id, or a current key that is not listed
     */
    public function __construct(#[SensitiveParameter] private array $keys, private string $current)
    {
        foreach ($keys as $id => $key) {
            if (preg_match(SealedSecret::KEY_ID_PATTERN, (string) $id) !== 1 || !is_string($key) || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
                throw new LogicException('nowo_crm_kit.secrets.keys maps key ids (1-16 characters of a-z, 0-9, "_" or "-") to 32-byte keys (base64 in the environment: %env(base64:...)%).');
            }
        }
        if (!array_key_exists($current, $keys)) {
            throw new LogicException('nowo_crm_kit.secrets.current_key must be one of nowo_crm_kit.secrets.keys.');
        }
    }

    /**
     * {@inheritDoc}
     */
    public function seal(#[SensitiveParameter] string $plaintext, SecretContext $context): SealedSecret
    {
        $nonce  = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $context->aad(), $nonce, $this->keys[$this->current]);
        sodium_memzero($plaintext);

        return new SealedSecret($this->current, base64_encode($nonce . $cipher));
    }

    /**
     * {@inheritDoc}
     *
     * @throws SecretUnsealException the value does not open (wrong key, tampering, another context)
     * @throws SecretKeyUnavailableException the key id is not in `secrets.keys` (0.10.1: a key dropped before
     *                                       `nowo:crm-kit:secrets:rotate` re-sealed its values keeps them, audit ZAM-D2)
     */
    public function unseal(SealedSecret $sealed, SecretContext $context): string
    {
        $key = $this->keys[$sealed->keyId] ?? null;
        if ($key === null) {
            throw new SecretKeyUnavailableException($sealed->keyId);
        }
        $raw = base64_decode($sealed->ciphertext, true);
        if (!is_string($raw) || strlen($raw) <= SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            throw new SecretUnsealException();
        }

        // The key length was checked when the box was built, so a wrong key, a tampered value or another context
        // only makes the decryption fail (false).
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($raw, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES), $context->aad(), substr($raw, 0, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES), $key);

        return is_string($plain) ? $plain : throw new SecretUnsealException();
    }

    /**
     * {@inheritDoc}
     */
    public function currentKeyId(): string
    {
        return $this->current;
    }

    /**
     * No key in dumps.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['current' => $this->current, 'keys' => '[redacted]'];
    }
}
