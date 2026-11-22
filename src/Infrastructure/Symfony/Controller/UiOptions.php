<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Controller;

/**
 * The 0.6.0 presentation options of the pages (`nowo_crm_kit.ui.*`): where the row and header actions go, the record
 * header layout, the list tabs on records and the filters card. The defaults keep the 0.5.0 behaviour except the pure
 * visual fixes. Immutable.
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
     */
    public function __construct(
        public string $rowActions = 'buttons',
        public string $headerActions = 'toolbar',
        public string $recordActions = 'split',
        public bool $recordTabs = true,
        public bool $filtersFold = false,
        public ?string $filtersSlot = null,
    ) {
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
