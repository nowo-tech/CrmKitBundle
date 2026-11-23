<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Fixtures;

use RuntimeException;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

use function file_get_contents;
use function is_file;
use function preg_match;
use function sprintf;

/**
 * A strict `ux_icon()` for the tests (0.8.1): like symfony/ux-icons with Iconify off and `ignore_not_found: false`, a
 * name it cannot draw throws, so the page answers 500. It draws only the Bootstrap Icons in tests/Fixtures/icons/bi
 * (the drawings of UiKit's canonical map and of the kit's own icon names), which proves every icon the kit asks for
 * exists in the set.
 */
final class StrictUxIcons extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [new TwigFunction('ux_icon', self::icon(...), ['is_safe' => ['html']])];
    }

    /**
     * The drawing of `bi:<name>`.
     *
     * @param array<string, mixed> $attributes
     */
    public static function icon(string $name, array $attributes = []): string
    {
        $file = __DIR__ . '/../Fixtures/icons/bi/' . (preg_match('/\Abi:([a-z0-9-]+)\z/', $name, $m) === 1 ? $m[1] : '-') . '.svg';
        if (!is_file($file)) {
            throw new RuntimeException(sprintf('Icon "%s" not found.', $name));
        }

        return (string) file_get_contents($file);
    }
}
