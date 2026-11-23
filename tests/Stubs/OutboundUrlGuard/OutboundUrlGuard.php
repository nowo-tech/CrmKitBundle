<?php

declare(strict_types=1);

// Minimal stand-ins of nowo-tech/outbound-url-guard-bundle's guard used by the CRM's HubSpot bridge, loaded by
// tests/bootstrap.php only when the bundle is not installed. The answer comes from a public closure.

namespace Nowo\OutboundUrlGuardBundle\Exception {
    use RuntimeException;

    final class UnsafeOutboundUrlException extends RuntimeException
    {
    }
}

namespace Nowo\OutboundUrlGuardBundle\Guard {
    use Closure;

    final class OutboundUrlGuard
    {
        /** @var list<array{string, bool|null, bool|null}> */
        public array $calls = [];

        /**
         * @param Closure(string): array{resolve?: array<string, string>} $answer
         */
        public function __construct(public Closure $answer)
        {
        }

        /**
         * @return array{resolve?: array<string, string>}
         */
        public function httpClientOptions(string $url, ?bool $allowPrivate = null, ?bool $resolveDns = null): array
        {
            $this->calls[] = [$url, $allowPrivate, $resolveDns];

            return ($this->answer)($url);
        }
    }
}
