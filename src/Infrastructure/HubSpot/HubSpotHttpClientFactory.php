<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\HubSpot;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The kit's own HttpClient for HubSpot (0.10.0, design section 9.3): not the framework's `http_client` service, so
 * the profiler and the HTTP client logger never see the `Authorization` header. No redirects, peer and host checks
 * on, idle timeout and total duration from `import.hubspot.http`.
 *
 * @internal
 */
final class HubSpotHttpClientFactory
{
    /**
     * The client.
     */
    public static function create(int $timeout, int $maxDuration): HttpClientInterface
    {
        return HttpClient::create([
            'max_redirects' => 0,
            'timeout'       => $timeout,
            'max_duration'  => $maxDuration,
            'verify_peer'   => true,
            'verify_host'   => true,
            'headers'       => ['Accept' => 'application/json', 'User-Agent' => 'nowo-crm-kit'],
        ]);
    }
}
