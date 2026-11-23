<?php

declare(strict_types=1);

// Minimal stand-ins of nowo-tech/doctrine-encrypt-bundle's encryptor API used by the CRM's DoctrineEncrypt secret box,
// loaded by tests/bootstrap.php only when DoctrineEncrypt is not installed. Same names and signatures.

namespace Nowo\DoctrineEncryptBundle\Encryptors;

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
