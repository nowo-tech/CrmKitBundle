<?php

declare(strict_types=1);
use Nowo\DoctrineEncryptBundle\Encryptors\EncryptorRegistryInterface;
use Nowo\OutboundUrlGuardBundle\Guard\OutboundUrlGuard;
use Nowo\TaskBoardBundle\Api\Operation\TaskApiTaskOperations;

require_once __DIR__ . '/../vendor/autoload.php';

if (!class_exists(TaskApiTaskOperations::class)) {
    require_once __DIR__ . '/Stubs/TaskBoard/TaskBoard.php';
}

if (!interface_exists(EncryptorRegistryInterface::class)) {
    require_once __DIR__ . '/Stubs/DoctrineEncrypt/DoctrineEncrypt.php';
}

if (!class_exists(OutboundUrlGuard::class)) {
    require_once __DIR__ . '/Stubs/OutboundUrlGuard/OutboundUrlGuard.php';
}
