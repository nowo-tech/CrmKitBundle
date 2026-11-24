<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Unit\Infrastructure;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Application\Pipeline\DealQuery;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Exception\InvalidInputException;
use Nowo\CrmKitBundle\Domain\Service\ListQuery;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Controller\ListFilters;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Controller\UiOptions;
use Nowo\CrmKitBundle\Infrastructure\Symfony\DependencyInjection\ConfigurationTree;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

use function json_encode;

/**
 * 0.10.2 filter standard: the query values (owner "Mine" / "No owner", day ranges, deal order and direction), the
 * request reading helpers and the `ui.filters_more` / `per_page_position` / `search_width` / `per_page_row_class`
 * options.
 */
final class FiltersStandardUnitTest extends TestCase
{
    public function testListQueryOwnerFlagsAndActor(): void
    {
        $day   = new DateTimeImmutable('2026-01-02');
        $query = new ListQuery(SubjectType::Company, 'x', ownerId: 'leo', ownerMe: true, createdFrom: $day, updatedTo: $day);
        $mine  = $query->forActor('ana');
        self::assertSame('ana', $mine->ownerId);
        self::assertFalse($mine->ownerMe);
        self::assertSame($day, $mine->createdFrom);
        self::assertSame($day, $mine->updatedTo);
        self::assertSame('x', $mine->text);
        $plain = new ListQuery(SubjectType::Company, ownerId: 'leo');
        self::assertSame($plain, $plain->forActor('ana'));

        $this->expectException(InvalidInputException::class);
        new ListQuery(SubjectType::Company, ownerMe: true, ownerNone: true);
    }

    public function testDealQueryDirectionsAndActor(): void
    {
        self::assertSame('desc', (new DealQuery())->direction);
        self::assertSame('asc', (new DealQuery(sort: DealQuery::SORT_TITLE))->direction);
        self::assertSame('asc', (new DealQuery(sort: DealQuery::SORT_EXPECTED_CLOSE))->direction);
        self::assertSame('desc', (new DealQuery(sort: DealQuery::SORT_AMOUNT))->direction);
        self::assertSame('asc', (new DealQuery(sort: DealQuery::SORT_AMOUNT, direction: 'asc'))->direction);
        self::assertSame('desc', (new DealQuery(sort: 'unknown', direction: 'sideways'))->direction);
        $close = new DateTimeImmutable('2026-03-01');
        $query = new DealQuery(funnelId: 'f', sort: DealQuery::SORT_TITLE, direction: 'desc', ownerMe: true, expectedCloseFrom: $close);
        $mine  = $query->forActor('ana');
        self::assertSame(['ana', false, 'desc', 'f'], [$mine->ownerId, $mine->ownerMe, $mine->direction, $mine->funnelId]);
        self::assertSame($close, $mine->expectedCloseFrom);
        $plain = new DealQuery();
        self::assertSame($plain, $plain->forActor('ana'));
    }

    public function testListFiltersReadDaysAndFilters(): void
    {
        self::assertSame('2026-02-28', ListFilters::day('2026-02-28')?->format('Y-m-d'));
        foreach (['2026-02-30', '2026-2-3', '', null, ['2026-01-01'], '2026-01-01T00:00'] as $bad) {
            self::assertNull(ListFilters::day($bad));
        }
        self::assertFalse(ListFilters::any([]));
        self::assertFalse(ListFilters::any(['page' => '2', 'per_page' => '10', 'sort' => 'name', 'direction' => 'asc', 'after' => 'x', 'status' => 'active', 'q' => '']));
        self::assertTrue(ListFilters::any(['q' => 'a']));
        self::assertTrue(ListFilters::any(['status' => 'trashed']));
        self::assertTrue(ListFilters::any(['role' => ['x']]));
    }

    public function testUiOptionsFilterPlacement(): void
    {
        $default = new UiOptions();
        self::assertSame(UiOptions::DEFAULT_FILTERS_MORE['contacts'], $default->filtersMoreOf('contacts'));
        self::assertSame([], $default->filtersMoreOf('unknown'));
        self::assertFalse($default->perPageInFilters());
        self::assertFalse($default->perPageInPagination());
        $filters = new UiOptions(listPerPage: [10, 25]);
        self::assertTrue($filters->perPageInFilters());
        self::assertFalse($filters->perPageInPagination());
        $pager = new UiOptions(listPerPage: [10, 25], perPagePosition: 'pagination');
        self::assertFalse($pager->perPageInFilters());
        self::assertTrue($pager->perPageInPagination());

        self::assertTrue(UiOptions::filtersMoreValid(UiOptions::DEFAULT_FILTERS_MORE));
        self::assertTrue(UiOptions::filtersMoreValid(['companies' => []]));
        self::assertFalse(UiOptions::filtersMoreValid(['companies' => ['stage']]), 'a deals field');
        self::assertFalse(UiOptions::filtersMoreValid(['companies' => ['role', 'role']]), 'twice');
        self::assertFalse(UiOptions::filtersMoreValid(['tags' => []]), 'an unknown list');
        self::assertFalse(UiOptions::filtersMoreValid([0 => []]));
        self::assertFalse(UiOptions::filtersMoreValid(['companies' => 'role']));
    }

    public function testConfigurationDefaultsAndValidation(): void
    {
        $ui = $this->process([])['ui'];
        self::assertSame(UiOptions::DEFAULT_FILTERS_MORE, $ui['filters_more']);
        self::assertSame('filters', $ui['per_page_position']);
        self::assertNull($ui['search_width']);
        self::assertNull($ui['per_page_row_class']);
        $ui = $this->process(['ui' => ['filters_more' => ['deals' => ['funnel']], 'per_page_position' => 'pagination', 'search_width' => 'lg', 'per_page_row_class' => 'issue-filters__pager extra']])['ui'];
        /** @var array<string, list<string>> $more */
        $more = $ui['filters_more'];
        self::assertSame(['funnel'], $more['deals']);
        self::assertSame(UiOptions::DEFAULT_FILTERS_MORE['companies'], $more['companies']);
        self::assertSame('issue-filters__pager extra', $ui['per_page_row_class']);

        foreach ([['filters_more' => ['contacts' => ['nope']]], ['per_page_row_class' => 'a  b'], ['per_page_row_class' => 'x"y'], ['per_page_position' => 'header'], ['search_width' => 'huge']] as $bad) {
            try {
                $this->process(['ui' => $bad]);
                self::fail('accepted ' . json_encode($bad));
            } catch (InvalidConfigurationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, array<string, mixed>>
     */
    private function process(array $config): array
    {
        $tree = new TreeBuilder('nowo_crm_kit');
        ConfigurationTree::define($tree->getRootNode());

        /** @var array<string, array<string, mixed>> $processed */
        $processed = (new Processor())->process($tree->buildTree(), [$config]);

        return $processed;
    }
}
