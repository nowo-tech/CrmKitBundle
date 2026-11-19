<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Read;

/**
 * A host link, resolved for display.
 */
final readonly class LinkView
{
    /**
     * Wires the view.
     */
    public function __construct(
        public string $id,
        public string $hostType,
        public string $hostId,
        public string $label,
        public ?string $url,
    ) {
    }
}
