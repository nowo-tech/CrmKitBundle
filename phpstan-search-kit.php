<?php

declare(strict_types=1);

// 0.3.0: SearchKit (nowo-tech/search-kit-bundle >= 1.9, PHP >= 8.3) is optional (.github/ci/search-kit.sh installs it
// on PHP >= 8.3). Without it the SearchKit groups and their tests cannot be analysed: they extend its classes. They are
// analysed wherever SearchKit is installed (the PHPStan job).
return is_file(__DIR__ . '/vendor/nowo-tech/search-kit-bundle/src/Provider/Entity/EntitySearchDefinition.php')
    ? []
    : ['parameters' => ['excludePaths' => ['analyseAndScan' => [__DIR__ . '/src/Bridge/SearchKit', __DIR__ . '/tests/Integration/Database/SearchKit']]]];
