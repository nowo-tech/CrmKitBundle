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

    public function testRowsPerPageFollowTheChoicesElseTheUiKitValuesAndThePageSize(): void
    {
        $off = new UiOptions();
        self::assertFalse($off->perPageSelect());
        self::assertFalse($off->recordDialog());
        self::assertFalse($off->pipelineDialogs(), '0.9.0: inline by default');
        self::assertTrue((new UiOptions(pipelineForms: 'dialog'))->pipelineDialogs());
        self::assertSame([10, 15, 25, 50, 100], $off->allowedPerPage(15));
        self::assertSame([10, 25, 50, 100], $off->allowedPerPage(25));
        self::assertSame(15, $off->perPage('15', 15));
        self::assertSame(50, $off->perPage(50, 25));
        foreach (['7', '1000', '', '1e1', ' 10', null, [10], 10.0] as $bad) {
            self::assertSame(25, $off->perPage($bad, 25));
        }

        $on = new UiOptions(listPerPage: [50, 10, 25], recordCreate: 'dialog');
        self::assertTrue($on->perPageSelect());
        self::assertTrue($on->recordDialog());
        self::assertSame([10, 25, 50], $on->allowedPerPage(25));
        self::assertSame(10, $on->perPage('10', 25));
        self::assertSame(25, $on->perPage('100', 25), 'not a configured choice');
        self::assertFalse($on->createRemote());
        self::assertTrue((new UiOptions(adminCreate: 'dialog'))->createRemote());
        self::assertFalse((new UiOptions(adminCreate: 'dialog'))->createDialog(), 'dialog is on demand since 0.8.0');
        self::assertTrue((new UiOptions(adminCreate: 'dialog_inline'))->createDialog());
    }
}
