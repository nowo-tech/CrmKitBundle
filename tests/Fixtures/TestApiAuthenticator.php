<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Fixtures;

use Nowo\CrmKitBundle\Api\CrmApiPrincipal;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Api\CrmApiAuthenticatorInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;

/**
 * The JSON API's principal in the tests: whatever {@see TestApiState} holds.
 */
final readonly class TestApiAuthenticator implements CrmApiAuthenticatorInterface
{
    public function __construct(private TestApiState $state)
    {
    }

    public function authenticate(Request $request): ?CrmApiPrincipal
    {
        if ($this->state->fail) {
            throw new RuntimeException('boom with secret details');
        }

        return $this->state->principal;
    }
}
