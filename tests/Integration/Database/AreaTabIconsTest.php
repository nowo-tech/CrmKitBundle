<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use Symfony\Component\DomCrawler\Crawler;

use function array_keys;
use function trim;

/**
 * Beacon request (0.7.0): the area tabs carry a default icon per area that the host can override one by one
 * (`ui.tab_icons`); the icon is decoration (aria-hidden), the label stays the accessible name in every tab style, and
 * the access checks still decide which tabs show.
 */
final class AreaTabIconsTest extends CrmTestCase
{
    private const TABLER = ['icon_set' => 'tabler-icons'];

    public function testEveryTabCarriesItsDefaultIcon(): void
    {
        $this->bootWithUiKit(['pipeline' => ['enabled' => true]], self::TABLER);
        $this->admin();
        $icons = $this->icons($this->tabs('/crm/companies'));
        self::assertSame([
            'Companies'     => 'ti ti-building',
            'Contacts'      => 'ti ti-users',
            'Deals'         => 'ti ti-briefcase',
            'Tags'          => 'ti ti-tags',
            'Custom fields' => 'ti ti-forms',
            'Settings'      => 'ti ti-settings',
            'Funnels'       => 'ti ti-filter',
        ], $icons);
    }

    public function testOneOverrideChangesOnlyItsEntry(): void
    {
        $this->bootWithUiKit(['pipeline' => ['enabled' => true], 'ui' => ['tab_icons' => ['deals' => 'tabler:currency-euro']]], self::TABLER);
        $this->admin();
        $icons = $this->icons($this->tabs('/crm/companies'));
        self::assertSame('ti ti-currency-euro', $icons['Deals']);
        self::assertSame('ti ti-building', $icons['Companies']);
        self::assertSame('ti ti-filter', $icons['Funnels']);
        self::assertCount(7, $icons);
    }

    public function testEachTabKeepsItsLabelAsTheAccessibleName(): void
    {
        // The markup is the same in every tab style: data-tab-style (text | icon-text | icon) on the host's <html>
        // only switches UiKit's CSS, which in "icon" mode clips the label (still in the accessibility tree) and never
        // hides it with display: none.
        $this->bootWithUiKit([], self::TABLER);
        $this->admin();
        $tabs = $this->tabs('/crm/admin/tags');
        self::assertGreaterThan(0, $tabs->count());
        $tabs->each(static function (Crawler $tab): void {
            $label = trim($tab->filter('.nowo-ui-tabs__label')->text());
            self::assertNotSame('', $label, 'a visible label element');
            self::assertSame($label, $tab->attr('data-rail-label'), 'the icon-only tooltip');
            self::assertSame('true', $tab->filter('.nowo-ui-tabs__icon')->attr('aria-hidden'), 'the icon is decoration');
            self::assertNull($tab->attr('aria-label'), 'the name comes from the label text, not a second copy');
        });
    }

    public function testABlankOverrideOrAnIconSetWithoutTablerFallsBackToTheLabel(): void
    {
        $this->bootWithUiKit(['ui' => ['tab_icons' => ['companies' => '']]], self::TABLER);
        $this->admin();
        $companies = $this->tabs('/crm/contacts')->reduce(static fn (Crawler $tab): bool => trim($tab->text()) === 'Companies');
        self::assertCount(1, $companies);
        self::assertCount(0, $companies->filter('.nowo-ui-tabs__icon'), 'no icon element: the label always shows, even in icon-only mode');
        self::assertNull($companies->attr('data-rail-label'));

        // UiKit's default icon set (bootstrap-icons) cannot draw a Tabler name: the label only, never an empty glyph.
        $this->boot();
        $this->admin();
        $tabs = $this->tabs('/crm/contacts');
        self::assertGreaterThan(0, $tabs->count());
        self::assertCount(0, $tabs->filter('.nowo-ui-tabs__icon'));
        self::assertSame('Companies', trim($tabs->first()->text()));
    }

    public function testIconsDoNotChangeWhichTabsShow(): void
    {
        $this->bootWithUiKit(['pipeline' => ['enabled' => true]], self::TABLER);
        $this->actAs('ana', 'view', 'edit');
        self::assertSame(['Companies', 'Contacts', 'Deals'], array_keys($this->icons($this->tabs('/crm/companies'))), 'manage still gates Tags, Custom fields, Settings and Funnels');
    }

    private function tabs(string $path): Crawler
    {
        return $this->client->request('GET', $path)->filter('nav[aria-label="CRM"] a');
    }

    /**
     * @return array<string, string> tab label => icon class
     */
    private function icons(Crawler $tabs): array
    {
        $icons = [];
        $tabs->each(static function (Crawler $tab) use (&$icons): void {
            $icons[trim($tab->filter('.nowo-ui-tabs__label')->text())] = (string) $tab->filter('.nowo-ui-tabs__icon i')->attr('class');
        });

        return $icons;
    }
}
