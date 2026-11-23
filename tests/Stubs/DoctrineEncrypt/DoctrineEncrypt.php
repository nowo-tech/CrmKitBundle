<?php

declare(strict_types=1);

// Minimal stand-ins of nowo-tech/doctrine-encrypt-bundle's encryptor API used by the CRM's DoctrineEncrypt secret box,
// loaded by tests/bootstrap.php only when DoctrineEncrypt is not installed. Same names and signatures.

namespace Nowo\DoctrineEncryptBundle\Exception;

use RuntimeException;

final class DecryptionFailedException extends RuntimeException
{
}

namespace Nowo\DoctrineEncryptBundle\Encryptors;

use Nowo\DoctrineEncryptBundle\Exception\DecryptionFailedException;
use RuntimeException;

interface EncryptorInterface
{
    public function encrypt(string $data): string;

    public function decrypt(string $data): string;
}

interface EncryptorRegistryInterface
{
    public function get(string $name): EncryptorInterface;

    public function getDefault(): EncryptorInterface;
}

/** Authenticated stand-in: `E:` + data; anything else fails like Halite (DecryptionFailedException). */
final class HaliteEncryptor implements EncryptorInterface
{
    public function encrypt(string $data): string
    {
        return 'E:' . $data;
    }

    public function decrypt(string $data): string
    {
        if (!str_starts_with($data, 'E:')) {
            throw new DecryptionFailedException('bad');
        }

        return substr($data, 2);
    }
}

/** A Defuse stand-in whose key is missing (a configuration error). */
final class DefuseEncryptor implements EncryptorInterface
{
    public function encrypt(string $data): string
    {
        return 'D:' . $data;
    }

    public function decrypt(string $data): string
    {
        throw new RuntimeException('The encryption key environment variable is not set.');
    }
}

final class MysqlAesEncryptor implements EncryptorInterface
{
    public function encrypt(string $data): string
    {
        return $data;
    }

    public function decrypt(string $data): string
    {
        return $data;
    }
}
