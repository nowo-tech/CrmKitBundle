<?php

declare(strict_types=1);
use Nowo\SearchKitBundle\Contract\SearchProviderInterface;
use Nowo\TaskBoardBundle\Api\Operation\TaskApiTaskOperations;

require_once __DIR__ . '/../vendor/autoload.php';

if (!interface_exists(SearchProviderInterface::class)) {
    require_once __DIR__ . '/Stubs/SearchKit/SearchKit.php';
}

if (!class_exists(TaskApiTaskOperations::class)) {
    require_once __DIR__ . '/Stubs/TaskBoard/TaskBoard.php';
}
