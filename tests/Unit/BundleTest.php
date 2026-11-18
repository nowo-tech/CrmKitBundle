<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Unit;

use Nowo\CrmKitBundle\NowoCrmKitBundle;
use PHPUnit\Framework\TestCase;

use function dirname;

/**
 * The scaffold bundle.
 */
final class BundleTest extends TestCase
{
    public function testTheBundleKeepsItsResourcesInSrc(): void
    {
        $bundle = new NowoCrmKitBundle();
        self::assertSame(dirname(__DIR__, 2) . '/src', $bundle->getPath());
        self::assertSame('nowo_crm_kit', $bundle->getContainerExtension()?->getAlias());
    }
}
