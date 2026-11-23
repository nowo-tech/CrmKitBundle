<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application\HubSpot\Port;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Model\HubSpot\ExternalRef;
use Nowo\CrmKitBundle\Domain\Model\HubSpot\HubSpotConnection;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * Persistence of the HubSpot import (0.10.0, internal port) on the bundle's entity manager: connections, external
 * references and the HubSpot columns of `crm_import`. Every query names the tenant and every result is re-checked
 * with `===` (REQ-SEC-006). Writes run inside the caller's unit.
 *
 * @internal
 */
interface HubSpotStoreInterface
{
    /** The tenant's connection. */
    public function connection(TenantRef $tenant): ?HubSpotConnection;

    /** The tenant's connection, locked `FOR UPDATE` and refreshed. */
    public function lockConnection(TenantRef $tenant): ?HubSpotConnection;

    /**
     * Connection ids after `$afterId`, in id order (the rotation command).
     *
     * @return list<array{tenant: string, id: string}>
     */
    public function connections(?string $afterId, int $limit): array;

    /** One connection by id, locked (the rotation command). */
    public function lockConnectionById(TenantRef $tenant, string $id): ?HubSpotConnection;

    /**
     * Counts a token check of the tenant in the current window (`$interval` seconds); false once `$limit` checks were
     * made in it. Atomic under a row lock.
     */
    public function takeConnectAttempt(TenantRef $tenant, DateTimeImmutable $now, int $limit, int $interval): bool;

    /** Whether the tenant has a HubSpot import in preview or running. */
    public function hasOpenImport(TenantRef $tenant): bool;

    /**
     * The references of HubSpot objects of one kind, keyed by HubSpot id (exact match).
     *
     * @param list<string> $externalIds
     *
     * @return array<string, ExternalRef>
     */
    public function refs(TenantRef $tenant, string $accountId, string $objectType, array $externalIds): array;

    /**
     * Points a reference at another target (a merged record's survivor).
     */
    public function retarget(ExternalRef $ref, string $targetId, DateTimeImmutable $now): void;

    /**
     * The run cursor of an import whose claim `$token` holds, read under the import row's lock (null: claim lost),
     * with its rows done.
     *
     * @return array{cursor: string|null, rows: int}|null
     */
    public function lockCursor(string $importId, string $token): ?array;

    /** Stores the next run cursor (inside the unit that settles the page). */
    public function saveCursor(string $importId, string $cursorJson): void;

    /**
     * Lets the tenant's imports paused for the connection's token run again now (a new token was saved); how many.
     */
    public function resumeForConnection(TenantRef $tenant, string $connectionId, DateTimeImmutable $now): int;

    /** Marks the connection invalid in its own statement (HubSpot refused the token). */
    public function invalidate(TenantRef $tenant, string $connectionId, DateTimeImmutable $now): void;
}
