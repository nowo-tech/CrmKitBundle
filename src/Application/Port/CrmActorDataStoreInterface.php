<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application\Port;

/**
 * Where the CRM stores a host person's id (0.2.1, contract section 16.5), across every tenant: one scan per kind of
 * reference, keyset-paged by the row key. Rows are re-checked exactly (`===`) after reading.
 *
 * @internal
 */
interface CrmActorDataStoreInterface
{
    /** Every kind of reference: `<table>.<role>`. */
    public const KINDS = [
        'company.owner', 'company.created_by', 'company.updated_by',
        'contact.owner', 'contact.created_by', 'contact.updated_by',
        'note.author', 'activity.actor', 'activity.owner', 'consent.recorded_by', 'host_link.created_by',
        'role.created_by', 'import.created_by', 'merge.actor', 'merge.owner', 'settings.updated_by',
    ];

    /** The kinds of the logged activities (0.11.0, only while `activities.enabled` maps their tables). */
    public const ACTIVITY_KINDS = ['engagement.author', 'engagement_attendee.actor'];

    /**
     * Up to `$limit` rows of `$kind` after the key `$after` that reference `$actorId` exactly, in key order; with
     * `$replacement`, those references are replaced in the same call (run it inside a write unit: the rows are locked
     * first, a versioned row's version moves). `next` is the key to continue from, null when the scan is complete.
     *
     * @return array{rows: list<array{tenant: string, key: string}>, next: string|null}
     */
    public function scan(string $kind, string $actorId, ?string $after, int $limit, ?string $replacement = null): array;
}
