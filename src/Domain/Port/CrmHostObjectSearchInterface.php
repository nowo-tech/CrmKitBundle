<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Port;

use Nowo\CrmKitBundle\Domain\Value\HostObjectView;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * Optional capability of a host object resolver (0.7.0): turns on the link picker for its type. A
 * {@see CrmHostObjectResolverInterface} that also implements this interface states that its `search()` really
 * searches; CrmKit then offers an autocomplete on the record page instead of a bare id field. A resolver without it
 * keeps the id field and its `search()` is never called (so a stub that returns `[]` stays harmless).
 *
 * The signature is the one `CrmHostObjectResolverInterface::search()` already declares, so one class implements both.
 *
 * CrmKit calls it only after the actor passed the same access check as the link action (`edit` on the record, the
 * `nowo_crm_kit.record_access_check` listeners included) and in the actor's tenant; it trims the text and nothing
 * else, bounds `$limit` (1–20) and keeps only the answers that `resolve()` confirms byte for byte.
 */
interface CrmHostObjectSearchInterface
{
    /**
     * Objects of the tenant that the actor may see and whose label (or id) matches the text, best first, at most
     * `$limit`. The resolver owns the comparison (case, accents, prefix or infix); the returned ids must be the
     * exact ids `resolve()` accepts.
     *
     * @return list<HostObjectView>
     */
    public function search(TenantRef $tenant, string $text, int $limit): array;
}
