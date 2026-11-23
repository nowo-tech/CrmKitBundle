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
use function is_string;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * {@see CrmSecretBoxInterface} on DoctrineEncryptBundle (0.10.0, Q-H4: the house option; Beacon uses it): the
 * encryptor of the configured profile (the default one when null) encrypts `<context aad>\n<plaintext>`, as
 * InstanceSettings' `enc:v2` sealer does, so a value opens only for the context it was sealed for. The unauthenticated
 * MysqlAes profile is refused. DoctrineEncrypt manages its own keys: the key id is always {@see self::KEY_ID}.
 * Select it with `nowo_crm_kit.secrets.box: Nowo\CrmKitBundle\Bridge\DoctrineEncrypt\DoctrineEncryptCrmSecretBox`.
 */
final readonly class DoctrineEncryptCrmSecretBox implements CrmSecretBoxInterface
{
    /** Key id of the values this box seals. */
    public const KEY_ID = 'doctrine_encrypt';

    /** Separator between the context and the plaintext inside the encrypted payload. */
    private const SEPARATOR = "\n";

    /**
     * @param string|null $profile DoctrineEncrypt profile (null: the default one)
     */
    public function __construct(private EncryptorRegistryInterface $registry, private ?string $profile = null)
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

        try {
            $payload = $this->encryptor()->decrypt($raw);
        } catch (Throwable) {
            throw new SecretUnsealException();
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
     * The profile's encryptor; never the unauthenticated MysqlAes one (a sealed value would be malleable).
     */
    private function encryptor(): EncryptorInterface
    {
        $encryptor = $this->profile === null ? $this->registry->getDefault() : $this->registry->get($this->profile);
        if ($encryptor instanceof MysqlAesEncryptor) {
            throw new LogicException('nowo_crm_kit: the DoctrineEncrypt secret box needs an authenticated profile (Halite or Defuse), not MysqlAes.');
        }

        return $encryptor;
    }
}
