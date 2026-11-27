<?php

declare(strict_types=1);
use Nowo\DoctrineEncryptBundle\Encryptors\EncryptorRegistryInterface;
use Nowo\NotifyKitBundle\Contract\NotifierInterface;
use Nowo\OutboundUrlGuardBundle\Guard\OutboundUrlGuard;
use Nowo\TaskBoardBundle\Api\Operation\TaskApiTaskOperations;
use Nowo\TaskBoardBundle\Calendar\CalendarEventSourceInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

require_once __DIR__ . '/../vendor/autoload.php';

// Loads the DI configurator functions (service(), param(), …) defined in the same file as ContainerConfigurator, so
// static analysis finds them whatever the order it reads the tests in.
class_exists(ContainerConfigurator::class);

if (!class_exists(TaskApiTaskOperations::class)) {
    require_once __DIR__ . '/Stubs/TaskBoard/TaskBoard.php';
}

// 0.11.0: TaskBoard's calendar event source API (2.24+) and NotifyKit's contract, for the activities' bridges.
if (!interface_exists(CalendarEventSourceInterface::class)) {
    require_once __DIR__ . '/Stubs/TaskBoard/Calendar.php';
}

if (!interface_exists(NotifierInterface::class)) {
    require_once __DIR__ . '/Stubs/NotifyKit/NotifyKit.php';
}

if (!class_exists('Nowo\\MilkdownEditorBundle\\Form\\MilkdownEditorType')) {
    require_once __DIR__ . '/Stubs/MilkdownEditor/MilkdownEditorType.php';
}

// 0.13.0: MailKit's stored templates API (2.7+), for the e-mail templates' bridge.
if (!interface_exists('Nowo\\MailKitBundle\\Template\\Service\\TemplateLibraryInterface')) {
    require_once __DIR__ . '/Stubs/MailKit/MailKit.php';
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
