<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Security;

use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Port\CrmRecordVisibilityInterface;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Event\CrmKitEvents;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Event\RecordAccessCheckEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function in_array;

/**
 * Decisions on one company or contact, after the area check: computes the default and dispatches
 * {@see CrmKitEvents::RECORD_ACCESS_CHECK} so the host can grant or deny ({@see RecordAccessCheckEvent}).
 *
 * The default is the record visibility of the lists ({@see CrmRecordVisibilityInterface}): with
 * `security.resource_scoping: false` (the default) every record of the tenant is open to whoever passed the area check
 * (a shared CRM, as 0.1.0); with `true`, a record is open by default only when it has no owner, when the person owns
 * it, when a `nowo_crm_kit.record_visibility` listener added its owner, or when the person may manage the settings.
 * Stateless.
 */
final readonly class CrmResourceAccessChecker
{
    /**
     * Wires the collaborators.
     */
    public function __construct(
        private ?EventDispatcherInterface $events = null,
        private ?CrmRecordVisibilityInterface $visibility = null,
    ) {
    }

    /**
     * Whether the person may do `$action` on the record.
     */
    public function isGranted(?object $user, ?ActorRef $actor, TenantRef $tenant, CrmPermission $action, SubjectRef $subject): bool
    {
        $owner = $subject->owner?->value;
        // Without a visibility service every record is shared; without an actor only ownerless records are.
        $owners  = $this->visibility instanceof CrmRecordVisibilityInterface ? ($actor instanceof ActorRef ? $this->visibility->visibleOwners($tenant, $actor) : []) : null;
        $default = $owners === null || $owner === null || in_array($owner, $owners, true);
        $event   = new RecordAccessCheckEvent($user, $actor?->value, $tenant->value, $subject->type->value, $subject->id->value, $owner, $action->value, $default);
        $this->events?->dispatch($event, CrmKitEvents::RECORD_ACCESS_CHECK);

        return $event->isGranted();
    }
}
