<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Event;

/**
 * Event names of the CRM's Symfony layer (the domain events after commit are dispatched by class name).
 */
final class CrmKitEvents
{
    /**
     * {@see RecordAccessCheckEvent}: before the CRM grants or refuses an action on one company or contact. Listeners
     * may grant() or deny() (a deny wins); without an answer the default decision stands.
     */
    public const RECORD_ACCESS_CHECK = 'nowo_crm_kit.record_access_check';

    /**
     * {@see RecordVisibilityEvent}: with `security.resource_scoping`, which owners' records a person sees in lists,
     * totals, search, exports and on record pages (team rules). Dispatched for every read of several records and every
     * decision on one record (no memo: keep listeners cheap).
     */
    public const RECORD_VISIBILITY = 'nowo_crm_kit.record_visibility';
}
