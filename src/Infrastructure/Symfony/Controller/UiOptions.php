<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Controller;

use Nowo\UiKitBundle\View\Pagination;

use function array_key_exists;
use function array_unique;
use function array_values;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function preg_match;
use function sort;

/**
 * The 0.6.0 presentation options of the pages (`nowo_crm_kit.ui.*`): where the row and header actions go, the record
 * header layout, the list tabs on records and the filters card; 0.6.1: the area tabs of the other pages, create in a
 * dialog on Tags and Custom fields, the Settings width; 0.7.0: the icon of each area tab. The defaults keep the previous
 * behaviour except the pure visual fixes. 0.8.0: the "Rows per page" choices of the lists and the "New company / contact"
 * dialog; 0.9.0: the funnel pages' forms on demand. Immutable: the tab icons are resolved from the configured map on every call,
 * nothing is memoised (FrankenPHP worker, REQ-RUNTIME-002).
 *
 * @internal
 */
final readonly class UiOptions
{
    /**
     * The built-in icon of each area tab (UiKit `AreaNavItem::$icon`, Tabler names as in the SearchKit groups):
     * shown when the host's tab style asks for icons and its UiKit `icon_set` draws Tabler names (`tabler-icons`, or
     * `ux_icon` with the Tabler set); any other set shows the label only.
     */
    public const DEFAULT_TAB_ICONS = [
        'companies' => 'tabler:building',
        'contacts'  => 'tabler:users',
        'deals'     => 'tabler:briefcase',
        'tags'      => 'tabler:tags',
        'fields'    => 'tabler:forms',
        'settings'  => 'tabler:settings',
        'funnels'   => 'tabler:filter',
        'templates' => 'tabler:mail',
    ];

    /**
     * 0.10.2: the filter fields of each list that may fold under "More filters" (`ui.filters_more`).
     */
    public const FILTER_FIELDS = [
        'companies' => ['tag', 'role', 'owner', 'status', 'created_from', 'created_to', 'updated_from', 'updated_to'],
        'contacts'  => ['tag', 'role', 'purpose', 'primary_contact', 'company', 'owner', 'status', 'created_from', 'created_to', 'updated_from', 'updated_to'],
        'deals'     => ['funnel', 'status', 'owner', 'stage', 'company', 'contact', 'stale', 'expected_close_from', 'expected_close_to'],
    ];

    /**
     * 0.10.2: the folded fields by default (Beacon filter standard request 2026-11-24): the rarer filters of each list.
     */
    public const DEFAULT_FILTERS_MORE = [
        'companies' => ['role', 'created_from', 'created_to', 'updated_from', 'updated_to'],
        'contacts'  => ['role', 'purpose', 'primary_contact', 'company', 'created_from', 'created_to', 'updated_from', 'updated_to'],
        'deals'     => ['stage', 'company', 'contact', 'stale', 'expected_close_from', 'expected_close_to'],
    ];

    /**
     * An icon name `[prefix:]name` as UiKit takes it (lowercase letters, digits and single hyphens).
     */
    public const ICON_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*(?::[a-z0-9]+(?:-[a-z0-9]+)*)?$/D';

    /**
     * @param string $rowActions `buttons` | `menu`
     * @param string $headerActions `toolbar` | `split`
     * @param string $recordActions `split` | `toolbar`
     * @param string|null $filtersSlot host template wrapping the filters region
     * @param bool $listTabs 0.6.1: the area tabs on every page but the company / contact record page
     * @param string $adminCreate 0.6.1: `inline` | `dialog` (0.8.0: on demand) | `dialog_inline` (deprecated)
     * @param string $settingsWidth 0.6.1: `form` | `full`
     * @param array<string, string|null> $tabIcons 0.7.0: host overrides of {@see self::DEFAULT_TAB_ICONS} by area;
     *                                             `''` or null = no icon (the tab shows its label in every tab style)
     * @param list<int> $listPerPage 0.8.0: the "Rows per page" choices of the lists; [] = no select
     * @param string $recordCreate 0.8.0: `page` | `dialog` ("New company / contact" in a remote form dialog)
     * @param string $pipelineForms 0.9.0: `inline` | `dialog` (the forms and confirms of the funnel pages on demand)
     * @param array<string, list<string>> $filtersMore 0.10.2: list (`companies` | `contacts` | `deals`) => folded fields
     * @param string $perPagePosition 0.10.2: `filters` (compact, before Filter / Clear) | `pagination`
     * @param string|null $searchWidth 0.10.2: UiKit FilterField width of the search field (null = one column)
     * @param string|null $perPageRowClass 0.10.2: extra classes of the "Rows per page" row
     */
    public function __construct(
        public string $rowActions = 'buttons',
        public string $headerActions = 'toolbar',
        public string $recordActions = 'split',
        public bool $recordTabs = true,
        public bool $filtersFold = false,
        public ?string $filtersSlot = null,
        public bool $listTabs = true,
        public string $adminCreate = 'inline',
        public string $settingsWidth = 'form',
        public array $tabIcons = [],
        public array $listPerPage = [],
        public string $recordCreate = 'page',
        public string $pipelineForms = 'inline',
        public array $filtersMore = self::DEFAULT_FILTERS_MORE,
        public string $perPagePosition = 'filters',
        public ?string $searchWidth = null,
        public ?string $perPageRowClass = null,
    ) {
    }

    /**
     * Whether a `ui.filters_more` map only names fields of its list, each once (0.10.2).
     *
     * @param array<mixed> $map
     */
    public static function filtersMoreValid(array $map): bool
    {
        foreach ($map as $list => $fields) {
            if (!is_string($list) || !isset(self::FILTER_FIELDS[$list]) || !is_array($fields)) {
                return false;
            }
            $seen = [];
            foreach ($fields as $field) {
                if (!is_string($field) || !in_array($field, self::FILTER_FIELDS[$list], true) || isset($seen[$field])) {
                    return false;
                }
                $seen[$field] = true;
            }
        }

        return true;
    }

    /**
     * The folded filter fields of a list (0.10.2; `companies` | `contacts` | `deals`).
     *
     * @return list<string>
     */
    public function filtersMoreOf(string $list): array
    {
        return array_values($this->filtersMore[$list] ?? []);
    }

    /**
     * Whether the "Rows per page" select sits in the filter actions (0.10.2): with choices and `per_page_position:
     * filters`.
     */
    public function perPageInFilters(): bool
    {
        return $this->perPageSelect() && $this->perPagePosition === 'filters';
    }

    /**
     * Whether the "Rows per page" select sits in the pagination bar instead (0.10.2, `per_page_position: pagination`).
     */
    public function perPageInPagination(): bool
    {
        return $this->perPageSelect() && $this->perPagePosition === 'pagination';
    }

    /**
     * The icon of an area tab: the host override when it names the area, else the built-in one; null = no icon.
     */
    public function tabIcon(string $area): ?string
    {
        $icon = array_key_exists($area, $this->tabIcons) ? $this->tabIcons[$area] : (self::DEFAULT_TAB_ICONS[$area] ?? null);

        return $icon === '' ? null : $icon;
    }

    /**
     * Whether Tags and Custom fields create through a header action and the 0.6.1 dialog whose form is in the page
     * (`ui.admin_create: dialog_inline`, deprecated since 0.8.0).
     */
    public function createDialog(): bool
    {
        return $this->adminCreate === 'dialog_inline';
    }

    /**
     * Whether Tags and Custom fields create through a header action and a remote form dialog loaded on demand
     * (`ui.admin_create: dialog`, 0.8.0).
     */
    public function createRemote(): bool
    {
        return $this->adminCreate === 'dialog';
    }

    /**
     * Whether "New company" / "New contact" open the form page in a remote form dialog (0.8.0).
     */
    public function recordDialog(): bool
    {
        return $this->recordCreate === 'dialog';
    }

    /**
     * Whether the forms and confirms of the funnel pages (move a deal, next action, lifecycle stage, new funnel, new /
     * edit stage, trash / purge a deal) load on demand into a remote form dialog (0.9.0, `ui.pipeline_forms: dialog`).
     */
    public function pipelineDialogs(): bool
    {
        return $this->pipelineForms === 'dialog';
    }

    /**
     * Whether the lists show the "Rows per page" select (0.8.0).
     */
    public function perPageSelect(): bool
    {
        return $this->listPerPage !== [];
    }

    /**
     * The per-page values a list accepts (0.8.0): the configured choices, else UiKit's (10, 25, 50, 100) and the
     * configured page size, ascending.
     *
     * @return list<int>
     */
    public function allowedPerPage(int $pageSize): array
    {
        $allowed = $this->listPerPage !== [] ? $this->listPerPage : array_values(array_unique([...Pagination::ALLOWED_PER_PAGE, $pageSize]));
        sort($allowed);

        return $allowed;
    }

    /**
     * The rows per page of a request value (0.8.0): one of {@see self::allowedPerPage()}, else the page size.
     */
    public function perPage(mixed $requested, int $pageSize): int
    {
        $value = is_string($requested) && preg_match('/^\d{1,3}$/D', $requested) === 1 ? (int) $requested : (is_int($requested) ? $requested : null);

        return $value !== null && in_array($value, $this->allowedPerPage($pageSize), true) ? $value : $pageSize;
    }

    /**
     * Whether the Settings form takes the full content width (0.6.1).
     */
    public function settingsFull(): bool
    {
        return $this->settingsWidth === 'full';
    }

    /**
     * Whether the secondary row actions go into the row-actions menu.
     */
    public function rowMenu(): bool
    {
        return $this->rowActions === 'menu';
    }

    /**
     * Whether the list header actions use the split layout.
     */
    public function headerSplit(): bool
    {
        return $this->headerActions === 'split';
    }

    /**
     * Whether the record header uses the split layout.
     */
    public function recordSplit(): bool
    {
        return $this->recordActions === 'split';
    }
}
