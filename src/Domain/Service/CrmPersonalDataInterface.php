<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Service;

/**
 * GDPR export and erasure of a contact (contract section 16), `manage`.
 */
interface CrmPersonalDataInterface
{
    /**
     * Everything the CRM holds about the contact, JSON-ready.
     *
     * @return array<string, mixed>
     */
    public function export(string $contactId): array;

    /**
     * Erases the contact's personal data (idempotent).
     */
    public function erase(string $contactId): void;

    /**
     * SYSTEM API (0.2.1), never for end users nor agent tools: every CRM reference to a host person's actor id in
     * every tenant (owner, note author, created_by / updated_by, timeline actor, consent recorder, import uploader,
     * merge actor, owner inside timeline and merge snapshots), as tenant ⇒ kind ⇒ row keys. No tenant, actor or
     * permission check.
     *
     * @return array{actor: string, tenants: array<string, array<string, list<string>>>}
     */
    public function exportActor(string $actorId): array;

    /**
     * SYSTEM API (0.2.1), never for end users nor agent tools: replaces every CRM reference to a host person's actor
     * id in every tenant by the placeholder `anonymised` (records and other people's data stay), in bounded batches,
     * one write unit each; `ActorAnonymised` per tenant after the commits. Idempotent: the number of references
     * replaced, 0 when run again. No tenant, actor or permission check.
     */
    public function anonymiseActor(string $actorId): int;
}
