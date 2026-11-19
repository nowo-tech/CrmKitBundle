<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database\SearchKit;

use PHPUnit\Framework\TestCase;

/**
 * Stands in for SearchKit's EntitySearchProviderTestCase when SearchKit (PHP >= 8.3) is not installed (the PHP 8.2
 * jobs), so the contract tests load and are skipped instead of breaking the suite.
 */
abstract class SearchKitAbsentTestCase extends TestCase
{
    public function testSearchKitIsInstalled(): void
    {
        self::markTestSkipped('nowo-tech/search-kit-bundle >= 1.9 is not installed (PHP >= 8.3).');
    }
}
