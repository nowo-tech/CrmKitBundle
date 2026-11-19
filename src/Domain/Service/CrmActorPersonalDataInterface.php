<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Service;

use Nowo\CrmKitBundle\Domain\Value\ActorRef;

/**
 * A host person's references in the CRM across EVERY tenant (0.2.1, contract section 16.5). A SYSTEM API: no tenant
 * scope, no actor and no permission check. Only trusted server-side code (an account-deletion job) may call it; never
 * expose it to end users, a host GDPR controller, an HTTP API or agent tools (MCP). The tenant-scoped GDPR service of
 * one contact is {@see CrmPersonalDataInterface}.
 */
interface CrmActorPersonalDataInterface
{
    /**
     * Every CRM reference to the actor id in every tenant (owner, note author, created_by / updated_by, timeline actor,
     * consent recorder, link and role creator, import uploader, merge actor, owner inside timeline entries and merge
     * snapshots), as tenant => kind => row keys.
     *
     * @return array{actor: string, tenants: array<string, array<string, list<string>>>}
     */
    public function exportActor(string $actorId): array;

    /**
     * Replaces every CRM reference to the actor id in every tenant by the reserved placeholder `anonymised`
     * ({@see ActorRef::ANONYMISED}); records and other people's data stay. Bounded
     * batches, one write unit each; `ActorAnonymised` and the audit per tenant after the commits (also for the batches
     * committed before a failure). Idempotent: the references replaced, 0 when run again.
     */
    public function anonymiseActor(string $actorId): int;
}
