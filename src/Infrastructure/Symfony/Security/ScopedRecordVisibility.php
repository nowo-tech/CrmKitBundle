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
        $user = $this->tokens?->getToken()?->getUser();

        return $this->visibleOwnersFor($user instanceof UserInterface ? $user : null, $tenant, $actor);
    }

    /**
     * The visible owners of a given person (0.3.0: an API principal's user): `$manageReach` false keeps a settings
     * manager to the scoped set (an API token below the manage level never widens the reach).
     *
     * @return list<string>|null
     */
    public function visibleOwnersFor(?object $user, TenantRef $tenant, ActorRef $actor, bool $manageReach = true, string $channel = 'web', ?string $tokenId = null): ?array
    {
        if (!$this->scoping) {
            return null;
        }
        $all = $manageReach && ($this->areas instanceof TenantAwareCrmKitAccessCheckerInterface ? $this->areas->canManageSettings($user, $tenant->value) : $this->areas->canManageSettings($user));
        if ($all) {
            return null;
        }
        $event = new RecordVisibilityEvent($user, $actor->value, $tenant->value, $channel, $tokenId);
        $this->events?->dispatch($event, CrmKitEvents::RECORD_VISIBILITY);

        // The anonymised placeholder is dropped by CrmAccess::visibleOwners(), for this adapter and a host's alike.
        return $event->getOwners();
    }
}
