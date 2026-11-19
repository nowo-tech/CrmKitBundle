<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Security;

use Nowo\CrmKitBundle\Domain\Port\CrmRecordVisibilityInterface;
use Nowo\CrmKitBundle\Domain\Value\ActorRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Event\CrmKitEvents;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Event\RecordVisibilityEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Resource scoping (`security.resource_scoping`) for every read that returns several records: off ⇒ every record of
 * the tenant; on ⇒ ownerless records and the person's own, plus the owners {@see CrmKitEvents::RECORD_VISIBILITY}
 * listeners add, unless the person may manage the settings (`canManageSettings` of the area checker, the host's when
 * configured). Applied in SQL by the store and to the related records of a page; the same set is the default of a
 * decision on one record ({@see CrmResourceAccessChecker}). Stateless.
 */
final readonly class ScopedRecordVisibility implements CrmRecordVisibilityInterface
{
    /**
     * Wires the collaborators.
     */
    public function __construct(
        private CrmKitAccessCheckerInterface $areas,
        private ?TokenStorageInterface $tokens = null,
        private ?EventDispatcherInterface $events = null,
        private bool $scoping = false,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function visibleOwners(TenantRef $tenant, ActorRef $actor): ?array
    {
        if (!$this->scoping) {
            return null;
        }
        $user = $this->tokens?->getToken()?->getUser();
        $user = $user instanceof UserInterface ? $user : null;
        $all  = $this->areas instanceof TenantAwareCrmKitAccessCheckerInterface ? $this->areas->canManageSettings($user, $tenant->value) : $this->areas->canManageSettings($user);
        if ($all) {
            return null;
        }
        $event = new RecordVisibilityEvent($user, $actor->value, $tenant->value);
        $this->events?->dispatch($event, CrmKitEvents::RECORD_VISIBILITY);

        $owners = $event->getOwners();

        // A listener can never make the anonymised placeholder an owner the person sees (audit VD-1).
        return $owners === null ? null : array_values(array_filter($owners, static fn (string $o): bool => !ActorRef::isReserved($o)));
    }
}
