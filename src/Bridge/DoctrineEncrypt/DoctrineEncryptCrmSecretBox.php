<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Bridge\DoctrineEncrypt;

use LogicException;
use Nowo\CrmKitBundle\Domain\Exception\SecretUnsealException;
use Nowo\CrmKitBundle\Domain\Port\CrmSecretBoxInterface;
use Nowo\CrmKitBundle\Domain\Value\SealedSecret;
use Nowo\CrmKitBundle\Domain\Value\SecretContext;
use Nowo\DoctrineEncryptBundle\Encryptors\EncryptorInterface;
use Nowo\DoctrineEncryptBundle\Encryptors\EncryptorRegistryInterface;
use Nowo\DoctrineEncryptBundle\Encryptors\MysqlAesEncryptor;
use SensitiveParameter;
use Throwable;

use function base64_decode;
use function base64_encode;
use function in_array;
use function is_string;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * {@see CrmSecretBoxInterface} on DoctrineEncryptBundle (0.10.0, Q-H4: the house option; Beacon uses it): the
 * encryptor of the configured profile (the default one when null) encrypts `<context aad>\n<plaintext>`, as
 * InstanceSettings' `enc:v2` sealer does, so a value opens only for the context it was sealed for. Only the
 * authenticated Halite and Defuse encryptors are accepted (audit ZAM-5); another encryptor class needs the explicit
 * `$allowCustom` opt-in (a service the host defines itself). A value that does not decrypt is
 * {@see SecretUnsealException}; a missing key or any other failure of the encryptor is thrown as it is (a
 * configuration error never marks a connection invalid). DoctrineEncrypt manages its own keys, so the key id is always
 * {@see self::KEY_ID}: after rotating the DoctrineEncrypt key (the old one still readable), run
 * `nowo:crm-kit:secrets:rotate --all` (CONFIGURATION, "Key rotation"). Select it with
 * `nowo_crm_kit.secrets.box: Nowo\CrmKitBundle\Bridge\DoctrineEncrypt\DoctrineEncryptCrmSecretBox`.
 */
final readonly class DoctrineEncryptCrmSecretBox implements CrmSecretBoxInterface
{
    /** Key id of the values this box seals. */
    public const KEY_ID = 'doctrine_encrypt';

    /** Separator between the context and the plaintext inside the encrypted payload. */
    private const SEPARATOR = "\n";

    /** The authenticated encryptors of DoctrineEncryptBundle. */
    public const AUTHENTICATED = ['Nowo\\DoctrineEncryptBundle\\Encryptors\\HaliteEncryptor', 'Nowo\\DoctrineEncryptBundle\\Encryptors\\DefuseEncryptor'];

    /** What a value that does not decrypt throws (Halite, Defuse, DoctrineEncrypt, libsodium). */
    public const DECRYPTION_FAILURES = ['ParagonIE\\Halite\\Alerts\\HaliteAlert', 'Defuse\\Crypto\\Exception\\WrongKeyOrModifiedCiphertextException', 'Defuse\\Crypto\\Exception\\BadFormatException', 'Nowo\\DoctrineEncryptBundle\\Exception\\DecryptionFailedException', 'SodiumException'];

    /**
     * @param string|null $profile DoctrineEncrypt profile (null: the default one)
     * @param bool $allowCustom accept an encryptor that is neither Halite nor Defuse (it must be authenticated)
     */
    public function __construct(private EncryptorRegistryInterface $registry, private ?string $profile = null, private bool $allowCustom = false)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function seal(#[SensitiveParameter] string $plaintext, SecretContext $context): SealedSecret
    {
        return new SealedSecret(self::KEY_ID, base64_encode($this->encryptor()->encrypt($context->aad() . self::SEPARATOR . $plaintext)));
    }

    /**
     * {@inheritDoc}
     */
    public function unseal(SealedSecret $sealed, SecretContext $context): string
    {
        $raw = base64_decode($sealed->ciphertext, true);
        if ($sealed->keyId !== self::KEY_ID || !is_string($raw)) {
            throw new SecretUnsealException();
        }

        $encryptor = $this->encryptor();

        try {
            $payload = $encryptor->decrypt($raw);
        } catch (Throwable $e) {
            foreach (self::DECRYPTION_FAILURES as $failure) {
                if ($e instanceof $failure) {
                    throw new SecretUnsealException();
                }
            }

            throw $e;
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
        return self::KEY_ID;
    }

    /**
     * The profile's encryptor: Halite or Defuse (authenticated), or another class with the explicit opt-in; never the
     * unauthenticated MysqlAes one (a sealed value would be malleable).
     */
    private function encryptor(): EncryptorInterface
    {
        $encryptor = $this->profile === null ? $this->registry->getDefault() : $this->registry->get($this->profile);
        if ($encryptor instanceof MysqlAesEncryptor || (!$this->allowCustom && !in_array($encryptor::class, self::AUTHENTICATED, true))) {
            throw new LogicException('nowo_crm_kit: the DoctrineEncrypt secret box needs an authenticated profile (Halite or Defuse), not ' . $encryptor::class . '.');
        }

        return $encryptor;
    }
}
