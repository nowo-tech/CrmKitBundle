<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\HubSpot;

/**
 * Extra HttpClient options for the HubSpot host (0.10.0): the OutboundUrlGuard bridge pins the resolved public
 * address of the constant host (`resolve`). Asked on every request; keeps nothing.
 *
 * @internal
 */
interface HubSpotRequestOptionsInterface
{
    /**
     * Options for a request to the URL (only `resolve` is used).
     *
     * @return array{resolve?: array<string, string>}
     */
    public function options(string $url): array;
}
