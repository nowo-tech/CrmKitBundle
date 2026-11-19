<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Event;

use Symfony\Contracts\EventDispatcher\Event;

use function array_values;
use function in_array;

/**
 * Dispatched as {@see CrmKitEvents::RECORD_VISIBILITY} once per call that lists records while
 * `security.resource_scoping` is on and the person does not see everything (`canManageSettings`). The visible owners
 * start with the person; a listener adds the owners of their team (addOwner()) or opens every record (allowAll()). The
 * same set decides the default of {@see RecordAccessCheckEvent}, so lists, totals, search, exports and record pages
 * agree. Records without an owner are always visible. Request-scoped; ids only.
 */
final class RecordVisibilityEvent extends Event
{
    /** @var list<string> */
    private array $owners;

    private bool $all = false;

    /**
     * @param object|null $user the security user
     */
    public function __construct(private readonly ?object $user, private readonly string $actorId, private readonly string $tenantKey)
    {
        $this->owners = [$actorId];
    }

    /** The security user, or null. */
    public function getUser(): ?object
    {
        return $this->user;
    }

    /** The acting person's id (`ActorRef` value). */
    public function getActorId(): string
    {
        return $this->actorId;
    }

    /** The tenant (`TenantRef` value). */
    public function getTenantKey(): string
    {
        return $this->tenantKey;
    }

    /** Makes the records of this owner (a host actor id, compared exactly) visible. */
    public function addOwner(string $ownerId): void
    {
        if (!in_array($ownerId, $this->owners, true)) {
            $this->owners[] = $ownerId; // @igor-ignore - request-scoped event DTO, not a shared service
        }
    }

    /** Makes every record of the tenant visible. */
    public function allowAll(): void
    {
        $this->all = true; // @igor-ignore - request-scoped event DTO, not a shared service
    }

    /**
     * The visible owners, or null when every record is visible.
     *
     * @return list<string>|null
     */
    public function getOwners(): ?array
    {
        return $this->all ? null : array_values($this->owners);
    }
}
