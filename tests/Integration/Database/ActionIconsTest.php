<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use Nowo\CrmKitBundle\Application\Pipeline\DealInput;
use Nowo\CrmKitBundle\Application\Pipeline\DealService;
use Nowo\CrmKitBundle\Application\Pipeline\FunnelService;
use Nowo\CrmKitBundle\Domain\Enum\FunnelKind;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Tests\Fixtures\StrictUxIcons;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;

use function file_get_contents;
use function in_array;
use function preg_match;
use function preg_quote;
use function trim;

/**
 * Beacon request 2026-11-23 (0.8.1): the pipeline actions named icons no set has (`bi:custom` for the stage up / down
 * and next action done / cancel, `bi:chart` for the statistics), so under `icon_set: ux_icon` the funnel, board, deal
 * and record pages answered 500. With a strict `ux_icon()` (a missing icon throws, as symfony/ux-icons does with
 * Iconify off and `ignore_not_found: false`) every page with a kit action renders, and each action draws its icon.
 */
final class ActionIconsTest extends CrmTestCase
{
    private const NO_TAB_ICONS = ['companies' => '', 'contacts' => '', 'deals' => '', 'tags' => '', 'fields' => '', 'settings' => '', 'funnels' => ''];

    public function testEveryPageWithAKitActionRendersUnderAStrictUxIcon(): void
    {
        $this->bootWithUiKit(
            ['pipeline' => ['enabled' => true], 'ui' => ['tab_icons' => self::NO_TAB_ICONS]],
            // A non-empty action_icons map makes UiKit draw the icons of menu items too (Merge sits in the more menu).
            ['icon_set' => 'ux_icon', 'action_icons' => ['create' => 'bi:plus-lg']],
            static function (ServicesConfigurator $services): void {
                $services->set(StrictUxIcons::class)->tag('twig.extension');
            },
        );
        $this->admin();
        $acme = $this->company('Acme');
        $ada  = $this->contact('Ada', 'L');
        $this->records()->affiliate($ada, $acme, null, true);
        $funnel = $this->service(FunnelService::class)->create(FunnelKind::Deal, null, 'Sales');
        $this->service(FunnelService::class)->create(FunnelKind::Party, SubjectType::Company, 'Accounts');
        $deal = $this->service(DealService::class)->create(new DealInput('Engines', $funnel, companyId: $acme));

        foreach (['/crm/deals/' . $deal, '/crm/companies/' . $acme] as $path) {
            $page = $this->client->request('GET', $path);
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), $path);
            $this->client->submit($page->filter('[data-testid="crm-next-action-form"] form')->form(), ['crm_next_action[title]' => 'Call', 'crm_next_action[due_at]' => '2026-11-20T09:00']);
            self::assertSame(303, $this->client->getResponse()->getStatusCode());
        }

        $pages = [
            '/crm/companies', '/crm/contacts', '/crm/companies/' . $acme, '/crm/contacts/' . $ada, '/crm/companies/new',
            '/crm/deals', '/crm/deals/new', '/crm/deals/' . $deal, '/crm/deals/' . $deal . '/edit',
            '/crm/funnels/' . $funnel . '/board', '/crm/funnels/' . $funnel . '/stats',
            '/crm/admin/funnels', '/crm/admin/funnels/' . $funnel, '/crm/admin/tags', '/crm/admin/fields', '/crm/admin/settings',
        ];
        foreach ($pages as $path) {
            $this->client->request('GET', $path);
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), $path);
        }

        $deal = $this->client->request('GET', '/crm/deals/' . $deal);
        foreach (['mark_done' => 'check-lg', 'cancel' => 'x-lg'] as $kind => $icon) {
            $button = $deal->filter('[data-testid="crm-next-action"] .nowo-ui-action--' . $kind);
            self::assertCount(1, $button, $kind);
            self::assertStringContainsString($this->drawing($icon), $button->html());
        }
        $company = $this->client->request('GET', '/crm/companies/' . $acme);
        self::assertCount(1, $company->filter('[data-testid="crm-next-action"] .nowo-ui-action--mark_done'));
        $primary = $company->filter('.nowo-ui-action--custom')->reduce(static fn ($a): bool => in_array(trim((string) $a->attr('aria-label')), ['Make primary contact', 'Clear primary contact'], true));
        self::assertGreaterThan(0, $primary->count(), 'the primary contact button');
        self::assertMatchesRegularExpression('/' . preg_quote($this->drawing('person-check'), '/') . '|' . preg_quote($this->drawing('person-dash'), '/') . '/', $primary->html());
        self::assertStringContainsString($this->drawing('union'), $company->html(), 'merge');
        $funnelPage = $this->client->request('GET', '/crm/admin/funnels/' . $funnel);
        $stage      = $funnelPage->filter('[data-testid="crm-stage"]')->first();
        self::assertCount(2, $stage->filter('.nowo-ui-action--move'));
        self::assertStringContainsString($this->drawing('arrow-up'), $stage->html());
        self::assertStringContainsString($this->drawing('arrow-down'), $stage->html());
        $board = $this->client->request('GET', '/crm/funnels/' . $funnel . '/board');
        self::assertStringContainsString($this->drawing('bar-chart'), $board->html(), 'statistics');
        $stats = $this->client->request('GET', '/crm/funnels/' . $funnel . '/stats');
        self::assertStringContainsString($this->drawing('kanban'), $stats->html(), 'board');
    }

    public function testAMissingIconStillFailsLoudlyInTheTests(): void
    {
        $this->expectExceptionMessage('Icon "bi:custom" not found.');
        StrictUxIcons::icon('bi:custom');
    }

    /**
     * The first path of an icon's drawing (the crawler rewrites `<path/>` as `<path></path>`).
     */
    private function drawing(string $name): string
    {
        self::assertSame(1, preg_match('/ d="([^"]+)"/', (string) file_get_contents(__DIR__ . '/../../Fixtures/icons/bi/' . $name . '.svg'), $m));

        return $m[1];
    }
}
