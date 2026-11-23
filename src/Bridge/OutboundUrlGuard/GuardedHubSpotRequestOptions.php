<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Bridge\OutboundUrlGuard;

use Nowo\CrmKitBundle\Application\HubSpot\HubSpotException;
use Nowo\CrmKitBundle\Infrastructure\HubSpot\HubSpotRequestOptionsInterface;
use Nowo\OutboundUrlGuardBundle\Exception\UnsafeOutboundUrlException;
use Nowo\OutboundUrlGuardBundle\Guard\OutboundUrlGuard;

/**
 * Pins the HubSpot host to the public address OutboundUrlGuard resolved (0.10.0, Q-H5: defence in depth against DNS
 * tampering that points `api.hubapi.com` at an internal address). Private addresses are never allowed here, whatever
 * the guard's own default; an unsafe answer refuses the request (`hubspot_bad_response`, the import pauses).
 */
final readonly class GuardedHubSpotRequestOptions implements HubSpotRequestOptionsInterface
{
    /**
     * Wires the guard.
     */
    public function __construct(private OutboundUrlGuard $guard)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function options(string $url): array
    {
        try {
            return $this->guard->httpClientOptions($url, false, true);
        } catch (UnsafeOutboundUrlException) {
            throw new HubSpotException(HubSpotException::BAD_RESPONSE);
        }
    }
}
