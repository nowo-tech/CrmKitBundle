<?php

declare(strict_types=1);
use Nowo\DoctrineEncryptBundle\Encryptors\EncryptorRegistryInterface;
use Nowo\OutboundUrlGuardBundle\Guard\OutboundUrlGuard;
use Nowo\TaskBoardBundle\Api\Operation\TaskApiTaskOperations;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

require_once __DIR__ . '/../vendor/autoload.php';

// Loads the DI configurator functions (service(), param(), …) defined in the same file as ContainerConfigurator, so
// static analysis finds them whatever the order it reads the tests in.
class_exists(ContainerConfigurator::class);

if (!class_exists(TaskApiTaskOperations::class)) {
    require_once __DIR__ . '/Stubs/TaskBoard/TaskBoard.php';
}

if (!interface_exists(EncryptorRegistryInterface::class)) {
    require_once __DIR__ . '/Stubs/DoctrineEncrypt/DoctrineEncrypt.php';
}

if (!class_exists('ParagonIE\\Halite\\Alerts\\HaliteAlert')) {
    require_once __DIR__ . '/Stubs/Crypto/Halite.php';
}

if (!class_exists('Defuse\\Crypto\\Exception\\CryptoException')) {
    require_once __DIR__ . '/Stubs/Crypto/Defuse.php';
}

if (!class_exists(OutboundUrlGuard::class)) {
    require_once __DIR__ . '/Stubs/OutboundUrlGuard/OutboundUrlGuard.php';
}
