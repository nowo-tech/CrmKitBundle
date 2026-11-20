<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Api;

use Nowo\CrmKitBundle\Api\CrmApiPrincipal;
use Symfony\Component\HttpFoundation\Request;

/**
 * Who calls the `/crm/v1` JSON API (0.3.0): the host builds the principal from the request its firewall
 * authenticated (an API token: grant from the token's level and ticks, reach from its tenants). Called on every
 * request; must keep nothing (worker mode). `api.http.authenticator` names the host's service.
 */
interface CrmApiAuthenticatorInterface
{
    /**
     * The principal of the request, or null (answered as 404 `ACCESS_001`).
     */
    public function authenticate(Request $request): ?CrmApiPrincipal;
}
