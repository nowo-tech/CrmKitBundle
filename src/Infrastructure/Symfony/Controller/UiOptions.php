<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Controller;

/**
 * The 0.6.0 presentation options of the pages (`nowo_crm_kit.ui.*`): where the row and header actions go, the record
 * header layout, the list tabs on records and the filters card; 0.6.1: the area tabs of the other pages, create in a
 * dialog on Tags and Custom fields, the Settings width. The defaults keep the previous behaviour except the pure visual
 * fixes. Immutable.
 *
 * @internal
 */
final readonly class UiOptions
{
    /**
     * @param string $rowActions `buttons` | `menu`
     * @param string $headerActions `toolbar` | `split`
     * @param string $recordActions `split` | `toolbar`
     * @param string|null $filtersSlot host template wrapping the filters region
     * @param bool $listTabs 0.6.1: the area tabs on every page but the company / contact record page
     * @param string $adminCreate 0.6.1: `inline` | `dialog` (create on Tags and Custom fields)
     * @param string $settingsWidth 0.6.1: `form` | `full`
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
    ) {
    }

    /**
     * Whether Tags and Custom fields create through a header action and a dialog (0.6.1).
     */
    public function createDialog(): bool
    {
        return $this->adminCreate === 'dialog';
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
