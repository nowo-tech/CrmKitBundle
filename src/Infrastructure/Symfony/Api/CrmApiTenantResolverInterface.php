<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Api;

use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Symfony\Component\HttpFoundation\Request;

/**
 * The tenant a JSON API request names (0.3.0, QA-2): a header, a path or the host's own rule. The bundle never looks a
 * record's tenant up by its id. `api.http.tenant_resolver` names the host's service; the default asks the configured
 * tenant resolver.
 */
interface CrmApiTenantResolverInterface
{
    /**
     * The tenant of the request, or null (answered as 404 `ACCESS_001`).
     */
    public function tenant(Request $request): ?TenantRef;
}
