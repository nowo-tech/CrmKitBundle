<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Bridge\DoctrineEncrypt;

use InvalidArgumentException;
use LogicException;
use Nowo\CrmKitBundle\Domain\Exception\SecretKeyUnavailableException;
use Nowo\CrmKitBundle\Domain\Exception\SecretUnsealException;
use Nowo\CrmKitBundle\Domain\Port\CrmSecretBoxInterface;
use Nowo\CrmKitBundle\Domain\Value\SealedSecret;
use Nowo\CrmKitBundle\Domain\Value\SecretContext;
use Nowo\DoctrineEncryptBundle\Encryptors\EncryptorInterface;
use Nowo\DoctrineEncryptBundle\Encryptors\EncryptorRegistryInterface;
use Nowo\DoctrineEncryptBundle\Encryptors\MysqlAesEncryptor;
use SensitiveParameter;
use Throwable;

use function array_key_exists;
use function array_keys;
use function base64_decode;
use function base64_encode;
use function in_array;
use function is_string;
use function preg_match;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * {@see CrmSecretBoxInterface} on DoctrineEncryptBundle (0.10.0, Q-H4: the house option; Beacon uses it): the
 * encryptor of the configured profile (the default one when null) encrypts `<context aad>\n<plaintext>`, as
 * InstanceSettings' `enc:v2` sealer does, so a value opens only for the context it was sealed for. Only the
 * authenticated Halite and Defuse encryptors are accepted (audit ZAM-5; the kit's own definition is also checked at
 * container build, ZAM-D4); another encryptor class needs the explicit `$allowCustom` opt-in (a service the host
 * defines itself). A value that does not decrypt is {@see SecretUnsealException}.
 *
 * Key rotation (0.10.1, audit ZAM-D2): `$profile` seals under `$keyId`; `$previous` maps the key ids of values sealed
 * earlier to the DoctrineEncrypt profile that still opens them. Rotate by adding a DoctrineEncrypt profile with a new
 * key, sealing with it under a new key id, listing the old key id and profile in `$previous`, and running
 * `nowo:crm-kit:secrets:rotate` (CONFIGURATION, "Key rotation"). A key id that is not listed, an unknown profile or an
 * encryptor that fails for another reason than the value (a missing key file) is
 * {@see SecretKeyUnavailableException}: never taken for a bad value, so no connection is marked invalid for it.
 * Select it with `nowo_crm_kit.secrets.box: Nowo\CrmKitBundle\Bridge\DoctrineEncrypt\DoctrineEncryptCrmSecretBox`
 * (`nowo_crm_kit.secrets.doctrine_encrypt` sets the profiles).
 */
final readonly class DoctrineEncryptCrmSecretBox implements CrmSecretBoxInterface
{
    /** Key id of the values this box seals unless another is configured (every value sealed by 0.10.0). */
    public const KEY_ID = 'doctrine_encrypt';

    /** Separator between the context and the plaintext inside the encrypted payload. */
    private const SEPARATOR = "\n";

    /** The authenticated encryptors of DoctrineEncryptBundle. */
    public const AUTHENTICATED = ['Nowo\\DoctrineEncryptBundle\\Encryptors\\HaliteEncryptor', 'Nowo\\DoctrineEncryptBundle\\Encryptors\\DefuseEncryptor'];

    /**
     * What a value that does not decrypt throws once the key loaded (Halite, Defuse, DoctrineEncrypt, libsodium). Since
     * 0.10.1 (audit ZAW-L1) only the value failures: Halite's key errors (`InvalidKey`, `CannotPerformOperation`) and
     * every failure while the key loads are "key not available" (see {@see self::PROBE}). Defuse's
     * `BadFormatException` and the encoders' `RangeException` stay here: once the key loaded, they mean a malformed
     * ciphertext.
     */
    public const DECRYPTION_FAILURES = ['ParagonIE\\Halite\\Alerts\\InvalidMessage', 'ParagonIE\\Halite\\Alerts\\InvalidSignature', 'ParagonIE\\Halite\\Alerts\\InvalidDigestLength', 'ParagonIE\\Halite\\Alerts\\InvalidType', 'RangeException', 'Defuse\\Crypto\\Exception\\WrongKeyOrModifiedCiphertextException', 'Defuse\\Crypto\\Exception\\BadFormatException', 'Nowo\\DoctrineEncryptBundle\\Exception\\DecryptionFailedException', 'SodiumException'];

    /**
     * A constant, non-secret value encrypted before each decryption (0.10.1, audit ZAW-L1): the encryptor loads its key
     * there, in its own try, so a key that is missing, malformed or of the wrong length is told apart from a value that
     * does not decrypt. (A wrong but well-formed key still loads: it cannot be told apart from tampering, so the
     * key id must change whenever the profile does.).
     */
    private const PROBE = 'nowo-crm-kit|key-probe';

    /**
     * @param string|null $profile DoctrineEncrypt profile that seals (null: the default one)
     * @param bool $allowCustom accept an encryptor that is neither Halite nor Defuse (it must be authenticated)
     * @param string $keyId the key id stored with new seals (0.10.1; change it with the profile on each rotation)
     * @param array<string, string|null> $previous key id => DoctrineEncrypt profile (null: the default one) of values
     *                                             sealed before a rotation, which still open (0.10.1)
     *
     * @throws LogicException a key id that is not 1-16 characters of a-z, 0-9, "_" or "-", or `$keyId` listed in
     *                        `$previous`
     */
    public function __construct(private EncryptorRegistryInterface $registry, private ?string $profile = null, private bool $allowCustom = false, private string $keyId = self::KEY_ID, private array $previous = [])
    {
        foreach ([$keyId, ...array_keys($previous)] as $id) {
            if (preg_match(SealedSecret::KEY_ID_PATTERN, (string) $id) !== 1) {
                throw new LogicException('nowo_crm_kit: a DoctrineEncrypt secret box key id is 1-16 characters of a-z, 0-9, "_" or "-".');
            }
        }
        if (array_key_exists($keyId, $previous)) {
            throw new LogicException('nowo_crm_kit: the DoctrineEncrypt secret box key id "' . $keyId . '" that seals cannot also be a previous one.');
        }
    }

    /**
     * {@inheritDoc}
     *
     * @throws SecretKeyUnavailableException the profile is unknown or its encryptor fails (a missing key)
     * @throws LogicException the profile's encryptor is not authenticated (Halite or Defuse without the opt-in)
     */
    public function seal(#[SensitiveParameter] string $plaintext, SecretContext $context): SealedSecret
    {
        $encryptor = $this->encryptor($this->profile, $this->keyId);

        try {
            $sealed = $encryptor->encrypt($context->aad() . self::SEPARATOR . $plaintext);
        } catch (Throwable $e) {
            throw new SecretKeyUnavailableException($this->keyId, $e);
        }

        return new SealedSecret($this->keyId, base64_encode($sealed));
    }

    /**
     * {@inheritDoc}
     *
     * @throws SecretUnsealException the value does not open (wrong key, tampering, another context)
     * @throws SecretKeyUnavailableException the key id is not configured, or its profile is unknown or fails
     * @throws LogicException the profile's encryptor is not authenticated (Halite or Defuse without the opt-in)
     */
    public function unseal(SealedSecret $sealed, SecretContext $context): string
    {
        if ($sealed->keyId !== $this->keyId && !array_key_exists($sealed->keyId, $this->previous)) {
            throw new SecretKeyUnavailableException($sealed->keyId);
        }
        $raw = base64_decode($sealed->ciphertext, true);
        if (!is_string($raw)) {
            throw new SecretUnsealException();
        }
        $encryptor = $this->encryptor($sealed->keyId === $this->keyId ? $this->profile : $this->previous[$sealed->keyId], $sealed->keyId);

        try {
            // The key loads here: any failure is the key's, never the value's.
            $encryptor->encrypt(self::PROBE);
        } catch (Throwable $e) {
            throw new SecretKeyUnavailableException($sealed->keyId, $e);
        }

        try {
            $payload = $encryptor->decrypt($raw);
        } catch (Throwable $e) {
            foreach (self::DECRYPTION_FAILURES as $failure) {
                if ($e instanceof $failure) {
                    throw new SecretUnsealException();
                }
            }

            throw new SecretKeyUnavailableException($sealed->keyId, $e);
        }
        $prefix = $context->aad() . self::SEPARATOR;
        if (!str_starts_with($payload, $prefix) || strlen($payload) === strlen($prefix)) {
            throw new SecretUnsealException();
        }

        return substr($payload, strlen($prefix));
    }

    /**
     * {@inheritDoc}
     */
    public function currentKeyId(): string
    {
        return $this->keyId;
    }

    /**
     * A profile's encryptor: Halite or Defuse (authenticated), or another class with the explicit opt-in; never the
     * unauthenticated MysqlAes one (a sealed value would be malleable).
     *
     * @throws SecretKeyUnavailableException the profile is unknown
     */
    private function encryptor(?string $profile, string $keyId): EncryptorInterface
    {
        try {
            $encryptor = $profile === null ? $this->registry->getDefault() : $this->registry->get($profile);
        } catch (InvalidArgumentException $e) {
            throw new SecretKeyUnavailableException($keyId, $e);
        }
        if ($encryptor instanceof MysqlAesEncryptor || (!$this->allowCustom && !in_array($encryptor::class, self::AUTHENTICATED, true))) {
            throw new LogicException('nowo_crm_kit: the DoctrineEncrypt secret box needs an authenticated profile (Halite or Defuse), not ' . $encryptor::class . '.');
        }

        return $encryptor;
    }
}
