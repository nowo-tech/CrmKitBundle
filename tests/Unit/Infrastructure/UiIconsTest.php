<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Unit\Infrastructure;

use Nowo\CrmKitBundle\Infrastructure\Symfony\Controller\UiIcons;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_combine;
use function dirname;
use function file_get_contents;
use function preg_match;
use function preg_match_all;

/**
 * 0.8.1 (Beacon request 2026-11-23): every icon name of the kit's actions resolves — a bare Bootstrap Icons name has
 * its drawing in tests/Fixtures/icons/bi, a canonical name is a key of UiKit's icon map.
 */
final class UiIconsTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function bootstrapNames(): iterable
    {
        foreach (UiIcons::BOOTSTRAP as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('bootstrapNames')]
    public function testABootstrapNameHasItsDrawing(string $name): void
    {
        self::assertFileExists(dirname(__DIR__, 2) . '/Fixtures/icons/bi/' . $name . '.svg');
    }

    public function testACanonicalNameIsInUiKitsMap(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 3) . '/vendor/nowo-tech/ui-kit-bundle/src/Resources/views/components/_icon.html.twig');
        self::assertSame(1, preg_match('/ux_map = \{(.*?)\} %\}/s', $template, $map));
        preg_match_all('/^\s*([a-z_]+): \'bi:([a-z0-9-]+)\'/m', $map[1], $entries);
        $icons = array_combine($entries[1], $entries[2]);
        foreach (UiIcons::CANONICAL as $name) {
            self::assertArrayHasKey($name, $icons, $name);
            self::assertFileExists(dirname(__DIR__, 2) . '/Fixtures/icons/bi/' . $icons[$name] . '.svg');
        }
    }
}
