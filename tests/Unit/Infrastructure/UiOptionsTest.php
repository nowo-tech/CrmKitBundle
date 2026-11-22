<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Unit\Infrastructure;

use Nowo\CrmKitBundle\Infrastructure\Symfony\Controller\UiOptions;
use PHPUnit\Framework\TestCase;

use function array_keys;

/**
 * The area tab icons (0.7.0): a built-in icon per area, a host override per area.
 */
final class UiOptionsTest extends TestCase
{
    private const AREAS = ['companies', 'contacts', 'deals', 'tags', 'fields', 'settings', 'funnels'];

    public function testEveryAreaHasADefaultIcon(): void
    {
        $options = new UiOptions();
        self::assertSame(self::AREAS, array_keys(UiOptions::DEFAULT_TAB_ICONS));
        foreach (self::AREAS as $area) {
            $icon = $options->tabIcon($area);
            self::assertNotNull($icon, $area);
            self::assertMatchesRegularExpression(UiOptions::ICON_PATTERN, $icon);
            self::assertStringStartsWith('tabler:', $icon, 'one scheme: Tabler names, as the SearchKit groups');
        }
        self::assertNull($options->tabIcon('reports'), 'no icon for an area the CRM does not have');
    }

    public function testOneOverrideChangesOnlyItsEntry(): void
    {
        $options = new UiOptions(tabIcons: ['deals' => 'tabler:currency-euro']);
        foreach (self::AREAS as $area) {
            self::assertSame($area === 'deals' ? 'tabler:currency-euro' : UiOptions::DEFAULT_TAB_ICONS[$area], $options->tabIcon($area), $area);
        }
    }

    public function testABlankOrNullOverrideRemovesTheIcon(): void
    {
        $options = new UiOptions(tabIcons: ['funnels' => '', 'tags' => null]);
        self::assertNull($options->tabIcon('funnels'));
        self::assertNull($options->tabIcon('tags'));
        self::assertSame('tabler:building', $options->tabIcon('companies'));
    }
}
