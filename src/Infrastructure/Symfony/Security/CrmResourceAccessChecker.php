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
        // Without a visibility service every record is shared; without an actor only ownerless records are.
        $owners = $actor instanceof ActorRef ? $this->visibleOwners($tenant, $actor) : ($this->visibility instanceof CrmRecordVisibilityInterface ? [] : null);

        return $this->decide($user, $actor, $tenant, $action, $subject, $owners);
    }

    /**
     * The visible owners of an actor, once for a page (null: every record).
     *
     * @return list<string>|null
     */
    public function visibleOwners(TenantRef $tenant, ActorRef $actor): ?array
    {
        return $this->visibility?->visibleOwners($tenant, $actor);
    }

    /**
     * The decision with the visible owners already known (0.3.0: an API principal's), and the call's channel and
     * token for the listeners.
     *
     * @param list<string>|null $owners the visible owners of the person (null: every record)
     */
    public function decide(?object $user, ?ActorRef $actor, TenantRef $tenant, CrmPermission $action, SubjectRef $subject, ?array $owners, string $channel = 'web', ?string $tokenId = null): bool
    {
        $owner   = $subject->owner?->value;
        $default = $owners === null || $owner === null || in_array($owner, $owners, true);
        $event   = new RecordAccessCheckEvent($user, $actor?->value, $tenant->value, $subject->type->value, $subject->id->value, $owner, $action->value, $default, $channel, $tokenId);
        $this->events?->dispatch($event, CrmKitEvents::RECORD_ACCESS_CHECK);

        return $event->isGranted();
    }
}
