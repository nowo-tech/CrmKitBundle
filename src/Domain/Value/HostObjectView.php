<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Value;

/**
 * A host object as the host resolver shows it (contract section 8.2): a label and a link.
 */
final readonly class HostObjectView
{
    /**
     * @param string $id the host id, exactly the one asked for
     * @param string $label what to show
     * @param string|null $url a local path or an http(s) URL
     */
    public function __construct(public string $id, public string $label, public ?string $url = null)
    {
    }
}
