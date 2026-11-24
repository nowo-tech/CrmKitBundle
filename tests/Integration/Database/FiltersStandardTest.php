<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\CrmKitBundle\Application\Pipeline\DealInput;
use Nowo\CrmKitBundle\Application\Pipeline\DealQuery;
use Nowo\CrmKitBundle\Application\Pipeline\DealService;
use Nowo\CrmKitBundle\Application\Pipeline\FunnelService;
use Nowo\CrmKitBundle\Application\Pipeline\Port\PipelineStoreInterface;
use Nowo\CrmKitBundle\Application\Port\CrmStoreInterface;
use Nowo\CrmKitBundle\Domain\Enum\FunnelKind;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Service\ListQuery;
use Nowo\CrmKitBundle\Domain\Service\RecordInput;
use Nowo\CrmKitBundle\Domain\Value\DateImmutable;
use Nowo\CrmKitBundle\Domain\Value\Money;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Symfony\Component\DomCrawler\Crawler;

use function array_replace_recursive;
use function array_slice;
use function sprintf;
use function str_repeat;
use function trim;

/**
 * 0.10.2 (Beacon request 2026-11-24, filter standard): the list filter bars of Companies, Contacts and Deals — the
 * search one column wide, "More filters" for the rarer filters, "Rows per page" compact before Filter / Clear, owner
 * "Mine" / "No owner", created / changed and expected close day ranges, the company of a contact, sortable headers on
 * Deals and Created, one create button, the empty state with Import (HubSpot in its hint when on), and the funnel
 * statistics on the standard bar.
 */
final class FiltersStandardTest extends CrmTestCase
{
    /**
     * @param array<string, mixed> $config
     */
    protected function boot(array $config = []): void
    {
        /** @var array<string, mixed> $merged */
        $merged = array_replace_recursive(['pipeline' => ['enabled' => true], 'ui' => ['list_per_page' => [10, 25, 50]]], $config);
        parent::boot($merged);
    }

    public function testCompaniesBarFollowsTheStandard(): void
    {
        $this->boot(['ui' => ['per_page_row_class' => 'issue-filters__pager']]);
        $this->admin();
        $this->company('Acme');
        $page = $this->client->request('GET', '/crm/companies');
        $form = $page->filter('form[data-nowo-ui-filters]');
        // the search is one grid column (no lg width) and named for assistive tech
        $q = $form->filter('#q')->closest('.nowo-ui-filters__field');
        self::assertNotNull($q);
        self::assertStringNotContainsString('nowo-ui-filters__field--lg', (string) $q->attr('class'));
        self::assertSame('Search', $form->filter('#q')->attr('aria-label'));
        // per page: compact, first in the actions, with the host class and "25 per page" choices
        $pager = $form->filter('.nowo-ui-filters__actions > .nowo-ui-filters__pager');
        self::assertCount(1, $pager);
        self::assertStringContainsString('issue-filters__pager', (string) $pager->attr('class'));
        self::assertSame(['10 per page', '25 per page', '50 per page'], $pager->filter('option')->each(static fn (Crawler $o): string => $o->text()));
        self::assertSame('Results per page', $pager->filter('label')->text());
        self::assertStringContainsString('visually-hidden', (string) $pager->filter('label')->attr('class'));
        // More filters: role and the date ranges folded, closed while none is set
        $more = $form->filter('details.nowo-ui-filters-more');
        self::assertCount(1, $more);
        self::assertNull($more->attr('open'));
        foreach (['created_from', 'created_to', 'updated_from', 'updated_to'] as $date) {
            self::assertSame('date', $more->filter('#' . $date)->attr('type'), $date);
        }
        self::assertCount(1, $more->filter('#role'));
        self::assertCount(1, $page->filter('form[data-nowo-ui-filters] > .nowo-ui-filters__field #owner'), 'owner stays on the bar');
        self::assertSame(['', 'me', 'none'], array_slice($form->filter('#owner option')->each(static fn (Crawler $o): string => (string) $o->attr('value')), 0, 3));
        // header: create primary first, Import secondary next
        $actions = $page->filter('[data-nowo-ui-region="page-header"] a, .nowo-ui-page-header a')->each(static fn (Crawler $a): string => (string) $a->attr('data-testid'));
        self::assertSame('crm-record-new', $actions[0] ?? null);
        self::assertContains('crm-import-open', $actions);
        // sortable Created column
        self::assertCount(1, $page->filter('th a[href*="sort=created"]'));

        // a folded filter set opens the disclosure with its count
        $open = $this->client->request('GET', '/crm/companies?created_from=2000-01-01');
        self::assertNotNull($open->filter('details.nowo-ui-filters-more')->attr('open'));
        self::assertSame('1', $open->filter('[data-nowo-ui-filters-more-count] [aria-hidden="true"]')->text());
    }

    public function testOwnerMineAndNoneAndDayRanges(): void
    {
        $this->boot();
        $this->admin();
        $records = $this->records();
        $records->create(new RecordInput(SubjectType::Company, ['name' => 'Mine Co'], 'ana'));
        $records->create(new RecordInput(SubjectType::Company, ['name' => 'Leo Co'], 'leo'));
        $records->create(new RecordInput(SubjectType::Company, ['name' => 'Nobody Co']));
        $names = fn (string $query): array => $this->client->request('GET', '/crm/companies' . $query)->filter('[data-testid="crm-list-company"] tbody tr td:first-child')->each(static fn (Crawler $c): string => trim($c->text()));
        self::assertSame(['Mine Co'], $names('?owner=me'));
        self::assertSame(['Nobody Co'], $names('?owner=none'));
        self::assertSame(['Leo Co'], $names('?owner=leo'));
        self::assertCount(3, $names(''));

        $today = $this->clock()->now()->format('Y-m-d');
        self::assertCount(3, $names('?created_from=' . $today . '&created_to=' . $today));
        self::assertCount(0, $names('?created_from=2999-01-01'));
        self::assertCount(0, $names('?updated_to=2000-01-01'));
        self::assertCount(3, $names('?updated_from=' . $today));
        self::assertCount(3, $names('?created_from=2026-02-30&updated_to=not-a-date'), 'invalid days are ignored');
    }

    public function testAnUnresolvedMineMatchesNothingInTheStores(): void
    {
        $this->boot();
        $this->admin();
        $acme   = $this->company('Acme');
        $funnel = $this->service(FunnelService::class)->create(FunnelKind::Deal, null, 'Sales');
        $this->service(DealService::class)->create(new DealInput('Engines', $funnel, companyId: $acme));
        $tenant = new TenantRef('acme');
        // a store called without ListQuery::forActor() / DealQuery::forActor(): nothing, rather than everything
        self::assertSame([[], 0], $this->service(CrmStoreInterface::class)->list($tenant, new ListQuery(SubjectType::Company, ownerMe: true)));
        self::assertSame(1, $this->service(CrmStoreInterface::class)->list($tenant, new ListQuery(SubjectType::Company))[1]);
        self::assertSame([], $this->service(PipelineStoreInterface::class)->deals($tenant, new DealQuery(ownerMe: true), null, null, 10, [], new DateTimeImmutable()));
        self::assertCount(1, $this->service(PipelineStoreInterface::class)->deals($tenant, new DealQuery(), null, null, 10, [], new DateTimeImmutable()));
    }

    public function testContactsFilterByCompanyAndPrimaryCheckbox(): void
    {
        $this->boot();
        $this->admin();
        $acme = $this->company('Acme');
        $this->contact('Ada', 'Lovelace');
        $page = $this->client->request('GET', '/crm/contacts');
        $more = $page->filter('details.nowo-ui-filters-more');
        self::assertSame('checkbox', $more->filter('#primary_contact')->attr('type'));
        self::assertSame('Primary contacts only', trim($more->filter('label[for="primary_contact"]')->text()));
        self::assertCount(1, $more->filter('#company option[value="' . $acme . '"]'));
        $list = $this->client->request('GET', '/crm/contacts?company=' . $acme);
        self::assertCount(0, $list->filter('[data-testid="crm-list-contact"] tbody tr'));
        self::assertCount(1, $this->client->request('GET', '/crm/contacts?company=bad%20id')->filter('[data-testid="crm-list-contact"] tbody tr'), 'an invalid id is ignored');
    }

    public function testOneCreateButtonAndTheEmptyState(): void
    {
        $this->boot(['ui' => ['row_actions' => 'menu']]);
        $this->admin();
        $empty = $this->client->request('GET', '/crm/companies');
        self::assertCount(0, $empty->filter('[data-testid="crm-record-new"]'), 'no header create on an empty list');
        $box = $empty->filter('[data-nowo-ui-region="empty"]');
        self::assertStringContainsString('import a CSV or vCard file', $box->text());
        self::assertSame(['crm-record-new-empty', 'crm-import-open-empty'], $box->filter('.nowo-ui-empty__action a')->each(static fn (Crawler $a): string => (string) $a->attr('data-testid')));

        $filtered = $this->client->request('GET', '/crm/companies?q=nothing');
        self::assertCount(1, $filtered->filter('[data-testid="crm-record-new"]'), 'a filter hides the rows: the header keeps create');
        self::assertSame('Nothing matches these filters.', trim($filtered->filter('[data-nowo-ui-region="empty"] .nowo-ui-empty__message')->text()));
        self::assertCount(1, $filtered->filter('[data-testid="crm-list-clear-filters"]'));
        self::assertCount(0, $filtered->filter('[data-testid="crm-import-open-empty"]'));
    }

    public function testTheEmptyStateNamesHubSpotWhenItIsOn(): void
    {
        $this->boot(['import' => ['hubspot' => ['enabled' => true]], 'secrets' => ['keys' => ['k1' => str_repeat("\x42", 32)], 'current_key' => 'k1']]);
        $this->admin();
        self::assertStringContainsString('or HubSpot', $this->client->request('GET', '/crm/contacts')->filter('[data-nowo-ui-region="empty"]')->text());
    }

    public function testDealsBarSortableHeadersAndFilters(): void
    {
        $this->boot();
        $this->admin();
        $this->ctx()->grant('acme', 'ana', 'amounts_view');
        $acme   = $this->company('Acme');
        $other  = $this->company('Other');
        $funnel = $this->service(FunnelService::class)->create(FunnelKind::Deal, null, 'Sales');
        $deals  = $this->service(DealService::class);
        $deals->create(new DealInput('Bravo', $funnel, companyId: $acme, ownerId: 'ana', amount: new Money('5', 'EUR'), expectedClose: new DateImmutable('2026-12-01')));
        $deals->create(new DealInput('Alpha', $funnel, companyId: $other, ownerId: 'leo', amount: new Money('50', 'EUR'), expectedClose: new DateImmutable('2027-01-15')));
        $deals->create(new DealInput('Charlie', $funnel, companyId: $other));
        $titles = fn (string $query): array => $this->client->request('GET', '/crm/deals' . $query)->filter('[data-testid="crm-deals"] tbody tr td:first-child')->each(static fn (Crawler $c): string => trim($c->text()));

        $page = $this->client->request('GET', '/crm/deals');
        $form = $page->filter('form[data-nowo-ui-filters]');
        self::assertSame('Search deals', $form->filter('#q')->attr('aria-label'));
        self::assertSame('Search deals by title', $form->filter('#q')->attr('placeholder'));
        self::assertSame('Any funnel', $form->filter('#funnel option')->first()->text());
        self::assertCount(0, $form->filter('#sort'), 'no sort select');
        foreach (['stage', 'company', 'contact', 'stale', 'expected_close_from', 'expected_close_to'] as $more) {
            self::assertCount(1, $form->filter('details.nowo-ui-filters-more #' . $more), $more);
        }
        self::assertCount(1, $form->filter('.nowo-ui-filters__pager #per_page'));
        foreach (['title', 'amount', 'expected_close', 'updated'] as $sort) {
            self::assertCount(1, $page->filter('th a[href*="sort=' . $sort . '"]'), $sort);
        }

        self::assertSame(['Alpha', 'Bravo', 'Charlie'], $titles('?sort=title'));
        self::assertSame(['Charlie', 'Bravo', 'Alpha'], $titles('?sort=title&direction=desc'));
        self::assertSame(['Alpha', 'Bravo', 'Charlie'], $titles('?sort=amount'), 'largest first, none last');
        self::assertSame(['Bravo', 'Alpha', 'Charlie'], $titles('?sort=amount&direction=asc'), 'none still last');
        self::assertSame(['Bravo', 'Alpha', 'Charlie'], $titles('?sort=expected_close'));
        self::assertSame(['Alpha', 'Bravo', 'Charlie'], $titles('?sort=expected_close&direction=desc'));
        self::assertSame(['Bravo'], $titles('?owner=me'));
        self::assertSame(['Charlie'], $titles('?owner=none'));
        self::assertSame(['Bravo'], $titles('?company=' . $acme));
        self::assertSame(['Bravo'], $titles('?expected_close_to=2026-12-31'));
        self::assertSame(['Alpha'], $titles('?expected_close_from=2027-01-01&expected_close_to=2027-01-15'));
        // keyset pages keep the order and direction
        self::assertSame(['Alpha'], $titles('?sort=title&per_page=10&q=alp'));
        $first = $this->client->request('GET', '/crm/deals?sort=title&direction=desc&per_page=10');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('10', $first->filter('#per_page option[selected]')->attr('value'));
    }

    public function testDealsKeysetPagesFollowTheDirection(): void
    {
        $this->boot(['ui' => ['list_per_page' => [10, 25], 'page_size' => 10]]);
        $this->admin();
        $acme   = $this->company('Acme');
        $funnel = $this->service(FunnelService::class)->create(FunnelKind::Deal, null, 'Sales');
        $deals  = $this->service(DealService::class);
        for ($i = 1; $i <= 12; ++$i) {
            $deals->create(new DealInput(sprintf('Deal %02d', $i), $funnel, companyId: $acme));
        }
        $page = $this->client->request('GET', '/crm/deals?sort=title&direction=desc');
        $rows = $page->filter('[data-testid="crm-deals"] tbody tr td:first-child')->each(static fn (Crawler $c): string => trim($c->text()));
        self::assertSame('Deal 12', $rows[0]);
        self::assertCount(10, $rows);
        $next = $this->client->request('GET', (string) $page->filter('[data-testid="crm-deals-next"]')->attr('href'));
        self::assertSame(['Deal 02', 'Deal 01'], $next->filter('[data-testid="crm-deals"] tbody tr td:first-child')->each(static fn (Crawler $c): string => trim($c->text())));
    }

    public function testFunnelStatsUseTheStandardBar(): void
    {
        $this->boot();
        $this->admin();
        $funnel = $this->service(FunnelService::class)->create(FunnelKind::Deal, null, 'Sales');
        $page   = $this->client->request('GET', '/crm/funnels/' . $funnel . '/stats');
        $form   = $page->filter('form[data-testid="crm-stats-filters"]');
        self::assertStringContainsString('nowo-ui-filter-bar', (string) $form->attr('class'));
        self::assertSame('date', $form->filter('#at')->attr('type'));
        self::assertSame('Filter', $form->filter('button[type="submit"]')->attr('aria-label'));
        self::assertSame('Clear filters', $form->filter('a.nowo-ui-filter-bar__clear')->attr('aria-label'));
        $this->client->request('GET', '/crm/funnels/' . $funnel . '/stats?at=2026-01-31');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('2026-01-31 23:59', (string) $this->client->getResponse()->getContent());
        // today: the snapshot is now (the end of today is still ahead), not a "future" error; a later day is refused
        $today = $this->clock()->now()->setTimezone(new DateTimeZone('UTC'));
        $this->client->request('GET', '/crm/funnels/' . $funnel . '/stats?at=' . $today->format('Y-m-d'));
        self::assertStringContainsString($today->format('Y-m-d H:i'), (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('This value is not valid.', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', '/crm/funnels/' . $funnel . '/stats?at=' . $today->modify('+1 day')->format('Y-m-d'));
        self::assertStringContainsString('This value is not valid.', (string) $this->client->getResponse()->getContent());
    }

    public function testPerPageInThePaginationAndSearchWidth(): void
    {
        $this->boot(['ui' => ['per_page_position' => 'pagination', 'search_width' => 'lg', 'filters_more' => ['companies' => []]]]);
        $this->admin();
        for ($i = 1; $i <= 11; ++$i) {
            $this->company(sprintf('Acme %02d', $i));
        }
        $page = $this->client->request('GET', '/crm/companies');
        self::assertCount(0, $page->filter('form[data-nowo-ui-filters] #per_page'), 'no per_page in the filters');
        self::assertCount(0, $page->filter('details.nowo-ui-filters-more'), 'no fold for companies');
        self::assertStringContainsString('nowo-ui-filters__field--lg', (string) $page->filter('#q')->closest('.nowo-ui-filters__field')?->attr('class'));
        self::assertCount(1, $page->filter('[data-nowo-ui-region="pagination"] select'), 'the compact pager carries the select');
    }
}
