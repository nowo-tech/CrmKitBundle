<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Application\Port;

use DateTimeImmutable;
use Nowo\CrmKitBundle\Domain\Enum\ImportStatus;
use Nowo\CrmKitBundle\Domain\Enum\RowOutcome;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Model\Activity;
use Nowo\CrmKitBundle\Domain\Model\Affiliation;
use Nowo\CrmKitBundle\Domain\Model\Consent;
use Nowo\CrmKitBundle\Domain\Model\Contact;
use Nowo\CrmKitBundle\Domain\Model\CrmRecord;
use Nowo\CrmKitBundle\Domain\Model\CrmSettings;
use Nowo\CrmKitBundle\Domain\Model\FieldDefinition;
use Nowo\CrmKitBundle\Domain\Model\HostLink;
use Nowo\CrmKitBundle\Domain\Model\Import;
use Nowo\CrmKitBundle\Domain\Model\ImportRow;
use Nowo\CrmKitBundle\Domain\Model\MergeRecord;
use Nowo\CrmKitBundle\Domain\Model\Note;
use Nowo\CrmKitBundle\Domain\Model\RoleAssignment;
use Nowo\CrmKitBundle\Domain\Model\Tag;
use Nowo\CrmKitBundle\Domain\Model\Tagging;
use Nowo\CrmKitBundle\Domain\Service\ListQuery;
use Nowo\CrmKitBundle\Domain\Value\HostObjectRef;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

/**
 * The CRM's persistence (implemented on the bundle's entity manager). Every lookup by tenant, owner or key re-checks
 * the value with `===` in PHP after the query (REQ-SEC-006 rule 2); callers run inside the transaction runner.
 *
 * @internal
 */
interface CrmStoreInterface
{
    /** Schedules a new row. */
    public function add(object $entity): void;

    /** Schedules a row's removal. */
    public function remove(object $entity): void;

    /** Writes pending changes inside the running unit. */
    public function flush(): void;

    /** A record of the tenant, any status. */
    public function record(SubjectType $type, TenantRef $tenant, string $id): ?CrmRecord;

    /**
     * Records of the tenant locked `FOR UPDATE` in id order (missing ids are absent).
     *
     * @param list<string> $ids
     *
     * @return array<string, CrmRecord>
     */
    public function lockRecords(SubjectType $type, TenantRef $tenant, array $ids): array;

    /**
     * Records of the tenant by id.
     *
     * @param list<string> $ids
     *
     * @return array<string, CrmRecord>
     */
    public function records(SubjectType $type, TenantRef $tenant, array $ids): array;

    /** The contact holding an e-mail key, any status. */
    public function contactByEmailKey(TenantRef $tenant, string $emailKey): ?Contact;

    /**
     * The ids of the contacts holding these e-mail keys, any status, in id order.
     *
     * @param list<string> $emailKeys
     *
     * @return list<string>
     */
    public function contactIdsByEmailKeys(TenantRef $tenant, array $emailKeys): array;

    /**
     * The distinct companies these contacts are affiliated to, in id order (plain ids, no entities loaded).
     *
     * @param list<string> $contactIds
     *
     * @return list<string>
     */
    public function affiliatedCompanyIds(TenantRef $tenant, array $contactIds): array;

    /**
     * The party roles stored for records of one type (every stored key, configured or not), by id.
     *
     * @param list<string> $ids
     *
     * @return list<RoleAssignment>
     */
    public function roleAssignments(TenantRef $tenant, SubjectType $type, array $ids): array;

    /**
     * A page of records and the total.
     *
     * @param list<string>|null $visibleOwners null: every record; else only ownerless records and these owners'
     *
     * @return array{list<CrmRecord>, int}
     */
    public function list(TenantRef $tenant, ListQuery $query, ?array $visibleOwners = null): array;

    /**
     * Records after `$afterId` (keyset by id) matching the query's filters.
     *
     * @param list<string>|null $visibleOwners null: every record; else only ownerless records and these owners'
     *
     * @return list<CrmRecord>
     */
    public function batch(TenantRef $tenant, ListQuery $query, ?string $afterId, int $limit, ?array $visibleOwners = null): array;

    /**
     * A keyset page of records (0.3.0, the API): the list's filters, ordered by the name key (ascending) or by the
     * last change (newest first), then the id; only rows after `$after` (`{v, id}` of the last row of the previous
     * page). With `$stage` (`{funnel, stage}`), only records currently in that lifecycle stage (pipeline on).
     *
     * @param array{v: string, id: string}|null $after
     * @param list<string>|null $visibleOwners null: every record; else only ownerless records and these owners'
     * @param array{funnel: string, stage: string}|null $stage
     *
     * @return list<CrmRecord>
     */
    public function keyset(TenantRef $tenant, ListQuery $query, bool $byUpdated, ?array $after, int $limit, ?array $visibleOwners = null, ?array $stage = null): array;

    /**
     * The keyset value of a record for {@see self::keyset()}.
     */
    public function keyOf(CrmRecord $record, bool $byUpdated): string;

    /**
     * A keyset page of notes (0.3.0): pinned first, then newest first (id descending), after `{pinned, id}`.
     *
     * @param array{pinned: bool, id: string}|null $after
     *
     * @return list<Note>
     */
    public function notesAfter(TenantRef $tenant, SubjectType $type, string $id, ?array $after, int $limit): array;

    /**
     * Active and trashed records of the tenant whose exact key matches (0.3.0, the API's duplicate check), oldest
     * first, locked `FOR UPDATE` when `$lock`.
     *
     * @param string $field `emailKey`, `taxIdKey`, `domainKey` or `nameKey`
     *
     * @return list<CrmRecord>
     */
    public function matching(SubjectType $type, TenantRef $tenant, string $field, string $key, bool $lock): array;

    /**
     * Active records sharing a key with `$record` (name, domain, tax id / sort key, e-mail).
     *
     * @param list<string>|null $visibleOwners null: every record; else only ownerless records and these owners'
     *
     * @return list<CrmRecord>
     */
    public function duplicates(CrmRecord $record, int $limit = 10, ?array $visibleOwners = null): array;

    /**
     * Active records whose searchable fields contain the text, and the total.
     *
     * @param list<string>|null $visibleOwners null: every record; else only ownerless records and these owners'
     *
     * @return array{list<CrmRecord>, int}
     */
    public function search(SubjectType $type, TenantRef $tenant, string $text, int $limit, ?string $companyId = null, ?array $visibleOwners = null): array;

    /**
     * Affiliations of a contact or of a company.
     *
     * @return list<Affiliation>
     */
    public function affiliations(TenantRef $tenant, SubjectType $side, string $id): array;

    /** One affiliation. */
    public function affiliation(TenantRef $tenant, string $id): ?Affiliation;

    /**
     * Tags of the tenant, by name.
     *
     * @return list<Tag>
     */
    public function tags(TenantRef $tenant): array;

    /** One tag. */
    public function tag(TenantRef $tenant, string $id): ?Tag;

    /**
     * Taggings of records.
     *
     * @param list<string> $ids
     *
     * @return list<Tagging>
     */
    public function taggings(TenantRef $tenant, SubjectType $type, array $ids): array;

    /**
     * Taggings of a tag.
     *
     * @return list<Tagging>
     */
    public function taggingsOfTag(TenantRef $tenant, string $tagId): array;

    /**
     * Field definitions, by position.
     *
     * @return list<FieldDefinition>
     */
    public function fields(TenantRef $tenant, SubjectType $type, bool $withArchived): array;

    /** One field definition. */
    public function field(TenantRef $tenant, string $id): ?FieldDefinition;

    /**
     * Notes of a record, pinned first, newest first.
     *
     * @return list<Note>
     */
    public function notes(TenantRef $tenant, SubjectType $type, string $id): array;

    /** One note. */
    public function note(TenantRef $tenant, string $id): ?Note;

    /**
     * Notes on active companies and contacts of the tenant whose body contains the text, case-insensitively (LIKE
     * wildcards are literal), newest first (id descending), before `$beforeId` when given (0.5.0). Only notes whose
     * record passes `$visibleOwners` (null: every record) are read.
     *
     * @param list<string>|null $visibleOwners
     *
     * @return list<Note>
     */
    public function searchNotes(TenantRef $tenant, string $text, int $limit, ?string $beforeId = null, ?array $visibleOwners = null): array;

    /**
     * At most `$perSubject` notes of each record, pinned first, then newest first (0.5.0): one query for the ids, one
     * for the rows, whatever the number of records.
     *
     * @param array<string, list<string>> $idsByType record ids keyed by `SubjectType` value (company, contact)
     *
     * @return list<Note>
     */
    public function notesOfMany(TenantRef $tenant, array $idsByType, int $perSubject): array;

    /**
     * Timeline entries of records, newest first, before an entry id (keyset).
     *
     * @param list<array{SubjectType, string}> $subjects
     *
     * @return list<Activity>
     */
    public function activities(TenantRef $tenant, array $subjects, int $limit, ?string $beforeId): array;

    /**
     * Every timeline entry of a record.
     *
     * @return list<Activity>
     */
    public function allActivities(TenantRef $tenant, SubjectType $type, string $id): array;

    /**
     * Consent rows of a contact, newest first.
     *
     * @return list<Consent>
     */
    public function consents(TenantRef $tenant, string $contactId): array;

    /**
     * Host links of a record.
     *
     * @return list<HostLink>
     */
    public function links(TenantRef $tenant, SubjectType $type, string $id): array;

    /** One host link. */
    public function link(TenantRef $tenant, string $id): ?HostLink;

    /**
     * Host links to a host object.
     *
     * @return list<HostLink>
     */
    public function linksTo(TenantRef $tenant, HostObjectRef $host): array;

    /**
     * Merge traces where the record was the merged one or the survivor.
     *
     * @return list<MergeRecord>
     */
    public function mergesOf(TenantRef $tenant, string $mergedId): array;

    /** The tenant's settings, if saved. */
    public function settings(TenantRef $tenant): ?CrmSettings;

    /** One import. */
    public function import(TenantRef $tenant, string $id): ?Import;

    /**
     * Rows of an import, by row number.
     *
     * @return list<ImportRow>
     */
    public function importRows(string $importId, int $limit, bool $pendingOnly): array;

    /** Claims an import in preview (conditional UPDATE); whether this call won. */
    public function claimImport(TenantRef $tenant, string $id, int $version, string $token, string $mappingJson, string $policy, DateTimeImmutable $now): bool;

    /**
     * Takes over a running import whose claim is older than `$staleBefore` (never one waiting for a person); whether
     * this call won.
     */
    public function takeOverImport(TenantRef $tenant, string $id, string $token, DateTimeImmutable $now, DateTimeImmutable $staleBefore): bool;

    /**
     * Cancels an import in preview, or a running one without a fresh claim (waiting for a person, or a claim older than
     * `$staleBefore`), in one conditional UPDATE; whether this call cancelled it.
     */
    public function cancelImport(TenantRef $tenant, string $id, DateTimeImmutable $now, DateTimeImmutable $staleBefore): bool;

    /**
     * Releases the claim of `$token` on a running import that waits for a person (claim token emptied, claimed time
     * set to now); whether this call held it. Such an import is left out of {@see self::staleImports()}.
     */
    public function pauseImport(string $id, string $token, DateTimeImmutable $now): bool;

    /** Claims a running import that waits for a person (claim token empty) for `$token`; whether this call won. */
    public function resumeImport(TenantRef $tenant, string $id, string $token, DateTimeImmutable $now): bool;

    /** Locks the import row and tells whether `$token` still holds it. */
    public function holdsImport(string $id, string $token): bool;

    /** Settles a pending row (conditional UPDATE); whether this call did. */
    public function settleRow(string $rowId, RowOutcome $outcome, ?string $recordId, ?string $message): bool;

    /**
     * Adds to the progress and counters of an import (atomic UPDATE).
     *
     * @param array<string, int> $counts outcome => rows
     */
    public function advanceImport(string $id, int $rows, array $counts, DateTimeImmutable $now): void;

    /** Moves an import to a final status. */
    public function finishImport(string $id, ImportStatus $status, DateTimeImmutable $now, ?ImportStatus $from = null): bool;

    /**
     * Running imports whose claim is older than the time (never one waiting for a person).
     *
     * @return list<array{tenant: string, id: string}>
     */
    public function staleImports(DateTimeImmutable $before, int $limit): array;

    /**
     * 0.4.0: the running imports (paused, waiting for a person, or stopped after a conflict) a person uploaded in the
     * tenant, newest first: how many, and the first `$limit` (tenant and person compared byte-exact).
     *
     * @return array{total: int, imports: list<array{id: string, file: string}>}
     */
    public function unfinishedImports(TenantRef $tenant, string $actor, int $limit): array;

    /**
     * Takes one unit of a counter (atomic UPDATE; the row is created from `$current()` on first use); false at the
     * limit.
     *
     * @param callable(): int $current the count of active rows, asked only when the row does not exist yet
     */
    public function takeCounter(TenantRef $tenant, string $resource, ?int $limit, callable $current): bool;

    /** Gives one unit back (never below 0). */
    public function releaseCounter(TenantRef $tenant, string $resource): void;

    /**
     * Locks a counter row `FOR UPDATE` (created at 0 when missing: `SELECT … FOR UPDATE` first, the insert only when
     * no row, then `SELECT … FOR UPDATE` again, so two lockers of an existing row never deadlock); held until the unit
     * ends.
     */
    public function lockCounter(TenantRef $tenant, string $resource): void;

    /** Sets a counter (rebuild); the caller locked its row first. */
    public function setCounter(TenantRef $tenant, string $resource, int $value): void;

    /**
     * Claims the single run of the retention purge: the claim row locked without waiting (`FOR UPDATE SKIP LOCKED`;
     * a row locked by the batch of a running purge answers false at once), created when missing, then a conditional
     * UPDATE: true when free or the claim is older than `$staleBefore` (unix times).
     */
    public function claimPurge(int $now, int $staleBefore): bool;

    /**
     * Moves this run's claim from `$claim` to `max($now, $claim + 1)` (once per batch, so a long run is never taken
     * over): the new claim, or null when another run holds it now.
     */
    public function refreshPurge(int $claim, int $now): ?int;

    /** Frees the purge claim, only while it is still this run's (`value = $claim`). */
    public function releasePurge(int $claim): void;

    /** Active records of the tenant. */
    public function countActive(TenantRef $tenant, SubjectType $type): int;

    /**
     * Tenants that have CRM rows.
     *
     * @return list<string>
     */
    public function tenants(): array;

    /**
     * Ids of trashed records older than the time.
     *
     * @return list<string>
     */
    public function trashedBefore(TenantRef $tenant, SubjectType $type, DateTimeImmutable $before, int $limit): array;

    /**
     * Ids of merge tombstones last changed before the time (retention: `merge_keep_days`).
     *
     * @return list<string>
     */
    public function mergedBefore(TenantRef $tenant, SubjectType $type, DateTimeImmutable $before, int $limit): array;

    /**
     * Locks (`FOR UPDATE`, id order) the merge tombstones that point at one of the records.
     *
     * @param list<string> $survivorIds
     *
     * @return list<CrmRecord>
     */
    public function lockTombstones(SubjectType $type, TenantRef $tenant, array $survivorIds): array;

    /** Deletes timeline entries older than the time (one batch); how many. */
    public function deleteActivitiesBefore(TenantRef $tenant, DateTimeImmutable $before, int $limit): int;

    /** Deletes the rows of imports finished before the time (one batch); how many. */
    public function deleteImportRowsBefore(TenantRef $tenant, DateTimeImmutable $before, int $limit): int;

    /** Deletes merge traces older than the time (one batch); how many. */
    public function deleteMergesBefore(TenantRef $tenant, DateTimeImmutable $before, int $limit): int;
}
