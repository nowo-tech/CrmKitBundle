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
}
