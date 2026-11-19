<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Port;

use Nowo\CrmKitBundle\Domain\Value\HostObjectView;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * Resolves host objects of one type for links (contract section 6.5; tag `nowo_crm_kit.host_object`, autoconfigured).
 */
interface CrmHostObjectResolverInterface
{
    /** Service tag. */
    public const HOST_OBJECT_TAG = 'nowo_crm_kit.host_object';

    /**
     * The host object type it resolves (`^[a-z][a-z0-9_.-]{0,63}$`).
     */
    public function type(): string;

    /**
     * The objects that exist in the tenant and the actor may see; keys must equal the requested ids byte for byte.
     *
     * @param list<string> $ids
     *
     * @return array<string, HostObjectView>
     */
    public function resolve(TenantRef $tenant, array $ids): array;

    /**
     * Objects whose label matches the text (link picker).
     *
     * @return list<HostObjectView>
     */
    public function search(TenantRef $tenant, string $text, int $limit): array;
}
