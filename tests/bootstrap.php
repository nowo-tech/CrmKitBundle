<?php

declare(strict_types=1);
use Nowo\SearchKitBundle\Contract\SearchProviderInterface;

require_once __DIR__ . '/../vendor/autoload.php';

if (!interface_exists(SearchProviderInterface::class)) {
    require_once __DIR__ . '/Stubs/SearchKit/SearchKit.php';
}
