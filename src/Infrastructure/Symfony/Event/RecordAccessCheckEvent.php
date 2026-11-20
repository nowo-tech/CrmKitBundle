<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\Event;

use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\CrmResourceAccessChecker;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched as {@see CrmKitEvents::RECORD_ACCESS_CHECK} before an action on one company or contact (the area check
 * already passed). It carries the person, the record (tenant, type, id, owner), the action (a `CrmPermission` value:
 * `view`, `edit`, `create`, `delete`, `import`, `export`, `manage`, `erase`, …) and the default decision of
 * {@see CrmResourceAccessChecker}. A listener calls grant() or deny();
 * **a deny wins** over any grant, before or after it. Request-scoped; ids only (REQ-ARCH-001 rule 4).
 * **(0.3.0)** {@see self::getChannel()} tells the CRM's pages (`web`) from the API (`api`, `mcp`, …) and
 * {@see self::getTokenId()} names the token, so a host may deny agents what it allows on the pages; the subject type
 * can also be `deal`.
 */
final class RecordAccessCheckEvent extends Event
{
    private bool $granted = false;

    private bool $denied = false;

    /**
     * @param object|null $user the security user (null only with `security.allow_unauthenticated`)
     * @param string $subjectType `company`, `contact` or (0.3.0) `deal`
     * @param string $channel `web`, `api`, `mcp`, …
     * @param string|null $tokenId the API token's id (null on the pages)
     */
    public function __construct(
        private readonly ?object $user,
        private readonly ?string $actorId,
        private readonly string $tenantKey,
        private readonly string $subjectType,
        private readonly string $subjectId,
        private readonly ?string $ownerId,
        private readonly string $action,
        private readonly bool $defaultGranted,
        private readonly string $channel = 'web',
        private readonly ?string $tokenId = null,
    ) {
    }

    /** `web` (the CRM's pages), `api`, `mcp`, … (0.3.0). */
    public function getChannel(): string
    {
        return $this->channel;
    }

    /** The API token's id, or null on the pages (0.3.0). */
    public function getTokenId(): ?string
    {
        return $this->tokenId;
    }

    /** The security user, or null. */
    public function getUser(): ?object
    {
        return $this->user;
    }

    /** The acting person's id as the CRM knows it (`ActorRef` value), or null. */
    public function getActorId(): ?string
    {
        return $this->actorId;
    }

    /** The tenant (`TenantRef` value). */
    public function getTenantKey(): string
    {
        return $this->tenantKey;
    }

    /** `company`, `contact` or (0.3.0) `deal`. */
    public function getSubjectType(): string
    {
        return $this->subjectType;
    }

    /** The record id. */
    public function getSubjectId(): string
    {
        return $this->subjectId;
    }

    /** The record's owner (a host actor id), or null. */
    public function getOwnerId(): ?string
    {
        return $this->ownerId;
    }

    /** The action (a `CrmPermission` value). */
    public function getAction(): string
    {
        return $this->action;
    }

    /** The decision without listeners. */
    public function isDefaultGranted(): bool
    {
        return $this->defaultGranted;
    }

    /** Grants the action (ignored once a listener denied it). */
    public function grant(): void
    {
        $this->granted = true; // @igor-ignore - request-scoped event DTO, not a shared service
    }

    /** Denies the action; final. */
    public function deny(): void
    {
        $this->denied = true; // @igor-ignore - request-scoped event DTO, not a shared service
    }

    /** Whether a listener denied it. */
    public function isDenied(): bool
    {
        return $this->denied;
    }

    /** The decision: never after a deny; else a grant, else the default. */
    public function isGranted(): bool
    {
        return !$this->denied && ($this->granted || $this->defaultGranted);
    }
}
