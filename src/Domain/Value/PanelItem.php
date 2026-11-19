<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

/**
 * One row of a host panel.
 */
final readonly class PanelItem
{
    /**
     * @param string $title what to show (rendered as text)
     * @param string|null $url a local path or an http(s) URL
     * @param string|null $badge a short badge text
     * @param string $tone badge tone: neutral, info, success, warning, danger
     */
    public function __construct(
        public string $title,
        public ?string $url = null,
        public ?string $subtitle = null,
        public ?string $badge = null,
        public string $tone = 'neutral',
    ) {
    }
}
