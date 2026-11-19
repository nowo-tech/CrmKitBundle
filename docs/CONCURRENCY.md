# Concurrency

## Table of contents

- [Write units](#write-units)
- [Two kinds of 409](#two-kinds-of-409)
- [Write paths](#write-paths)
- [Plan-limit counters](#plan-limit-counters)
- [Version checks](#version-checks)
- [Merge](#merge)
- [Import](#import)
- [Isolation and locks](#isolation-and-locks)
- [Tests](#tests)

REQ-RUNTIME-003; design in [CONTRACT.md](CONTRACT.md) section 15.

## Write units

Every write is one **write unit** of `nowo-tech/transaction-doctrine` ^1.6 (`WriteUnitInterface`) around one
transaction on the bundle's entity manager (`DoctrineTransactionRunner`):

- flushed before the commit; replayed after a deadlock, a lock-wait timeout or a serialization failure (MySQL 1213 /
  1205, MariaDB 1020, PostgreSQL 40001 / 40P01 / 55P03), up to transaction-doctrine's attempt limit;
- the entity manager is cleared (private manager) or the bundle's entities detached (shared manager) when the unit
  ends, so no attempt sees an entity of an earlier one; a private manager closed by a refused unit is reset through
  the registry before the next unit;
- on a **shared** manager (`entity_manager: null`) the runner begins, flushes and commits the transaction itself and,
  on any failure, rolls back and detaches only the bundle's entities: a refused write (stale version, merged record,
  limit, invalid input) leaves the host's manager open and its entities managed. Only a failed flush closes it
  (Doctrine does that), and only then is it reset before the next unit. A write while the host has pending changes of
  its own is refused;
- a write is **refused inside a transaction that is already open** on the same connection (a host's unit of work,
  Messenger's `doctrine_transaction` middleware): the events and audit entries are released after the bundle's own
  commit, which an outer transaction could still roll back; the caller gets a `LogicException` naming the fix. Reads
  inside an open transaction run in it;
- events and audit entries are collected in a per-call `Effects` buffer that every attempt starts empty and that is
  released only after the commit, so a replay or a rollback records nothing twice;
- a unique-key refusal inside the unit becomes `DuplicateRowException`, an optimistic-lock failure at flush
  `StaleRowException`; the services decide what each means (below);
- no HTTP, mail or file I/O inside a transaction (an import file is parsed before the unit that stores its rows);
- **a replay clears the host's entity manager too** (UD2-L-2, inherited from transaction-doctrine 1.6): before the
  next attempt, its `WriteUnit` clears every open entity manager, the host's default one included, in private and in
  shared mode. A host that holds managed entities while it calls a CRM write must re-read them after the call (or call
  the CRM first). A refused write without a replay leaves the host's manager as it was. A write unit scoped to the
  bundle's own manager is a platform item of transaction-doctrine.

The bundle's write controllers also carry `#[AsWriteUnit]`, so with the transaction bundle's controller attribute
switched on the whole action is replayed (that attribute opens no transaction, so the services' units still run on
their own).

`NowoTransactionDoctrineBundle` is **required** (the container build fails with the fix without it): its write unit,
its commit ledger with the DBAL middleware and its request watermark are what turn a conflict after a commit earlier
in the same request into `CONFLICT_002`.

## Two kinds of 409

| Kind | Raised as | Answer | Retry |
| --- | --- | --- | --- |
| **Lock conflict** (another writer holds the rows; the replays did not settle it) | transaction-doctrine's `ConcurrencyConflict` | 409 `CONFLICT_001` with `Retry-After` and `X-Conflict-Code: CONFLICT_001`; **`CONFLICT_002`** without `Retry-After` when part of the request already committed (request commit watermark) | safe (001) / reload first (002) |
| **Domain conflict** (the request cannot succeed as sent) | `Domain\Exception\ConflictException`: `crm_record_stale`, `crm_record_merged`, `crm_record_trashed`, `crm_merge_stale`, `crm_import_running`, `crm_limit_reached`, `crm_settings_stale`, `crm_write_conflict` | 409 with its own body code, **no `Retry-After`, no `X-Conflict-Code`** | no: reload, compare, decide (`crm_write_conflict`: nothing was committed, sending it again is safe) |

`crm_write_conflict` is a unique key refused twice: another writer stored the same row at the same moment and one
more run did not settle it. It is a domain answer, never `CONFLICT_001`: no lock was waited on, and the code does not
depend on what the request committed before.

Lock conflicts go through transaction-doctrine's `ConcurrencyConflictResponder` when `NowoTransactionDoctrineBundle`
is registered; on the bundle's routes `CrmConflictFormatter` gives JSON requests
`{"error": "concurrency_conflict", "code": "CONFLICT_001"}` (or `concurrency_conflict_partial` / `CONFLICT_002`) and
HTML falls through to the responder's page. When the host turns the transaction bundle's `conflict_response` off,
`ConflictFallbackListener` answers lock conflicts on the bundle's routes with the same status, codes and headers
(plain text for HTML).

Domain conflicts are handled by the pages (a record form is shown again with the other writer's values, a flash and
status 409; POST actions flash and redirect); `DomainExceptionListener` is the safety net (409, `{"error": "<code>"}`
for JSON).

## Write paths

| Write | Protection | Under contention | Answer |
| --- | --- | --- | --- |
| **Create company / contact** | Plan counter `UPDATE … WHERE value < :limit` in the same transaction; contact `email_key` unique `(tenant_id, email_key)` | Another contact (on an edit: not the edited one) holds the e-mail: form error `error.email_taken` naming it. Any other refusal, or the racing row vanished (its writer rolled back): **one more run**; refused again: `crm_write_conflict` | limit: `crm_limit_reached`; `crm_write_conflict` (409 domain) |
| **Edit company / contact** (fields, owner, custom values, tags in the form) | `version` posted and compared before the change; ORM version at flush; e-mail key as above; taggings unique `(tag_id, subject_type, subject_id)` | A moved version: `crm_record_stale`, form shown again with the current values | `crm_record_stale` |
| Trash | Record `FOR UPDATE`, `version` compared, status re-checked; counter −1 | Stale: `crm_record_stale`; not active: `crm_record_trashed` / `crm_record_merged` | 409 domain |
| Restore | Record `FOR UPDATE`, status must be `trashed`; counter +1 with the limit | Limit: `crm_limit_reached`; not trashed: `crm_record_stale` | 409 domain |
| Purge (`manage`) | Record `FOR UPDATE`, status must be `trashed`; children deleted by primary key | Not trashed: `crm_record_stale` | 409 domain |
| Affiliate / unaffiliate | Affiliate: contact `FOR UPDATE`, then company `FOR UPDATE` (always this order, imports too); unaffiliate: contact `FOR UPDATE`; the contact's version moves; unique `(contact_id, company_id)` | A merged or non-active contact or company: 409; a racing insert of the same pair: one more run, now as an update of that row; refused again: `crm_write_conflict` | 409 domain |
| **Role edit** (0.2.0, `setRoles`) | The record `FOR UPDATE` first; set semantics over the configured roles (a role no longer configured is never touched); unique `(tenant_id, subject_id, role_key)` | Two role edits of one record run one after the other; a form edit or an import (no record lock) storing the same role at the same moment: one more run, now that row is there; refused again: `crm_write_conflict` | lock: `CONFLICT_001`; `crm_write_conflict` |
| **The company's primary contact** (0.2.0: `setPrimaryContact`, `affiliate(…, primaryContact: true)`, the import column) | The contact `FOR UPDATE`, then the company `FOR UPDATE` (the order of affiliate); the company's affiliations re-read under the company lock and every other flag cleared; `edit` on the company | Two "set primary" calls on one company run one after the other: the later one wins, never two primary contacts | lock: `CONFLICT_001` |
| Tag create / rename | Name key unique `(tenant_id, name_key)`, checked first, then the key; `version` on rename | Taken name: form error `error.tag_taken`; stale rename: `crm_record_stale` | form error / 409 |
| Tag delete | Taggings deleted with the tag in one unit | — | — |
| Tag assign | Record `FOR UPDATE`; set difference; taggings unique | A racing duplicate tagging: one more run against its rows | — |
| Field define | Key unique `(tenant_id, subject_type, field_key)`, checked first; count ≤ `fields.max_per_subject` read after locking the `fields.<type>` row of `crm_counter` `FOR UPDATE` (one define at a time per tenant and type) | Taken key: form error `error.field_key_taken` | form error |
| Field change / archive | `version` (archive: the version the page showed, posted with the button) | Stale: `crm_record_stale` | 409 domain |
| Note add / edit / delete | The parent record `FOR UPDATE` first; `version` on edit; delete by id | Merged / non-active parent: 409; stale edit: `crm_record_stale`; note gone: 404 | 409 / 404 |
| Host link add / remove | The parent record `FOR UPDATE` first; a type with a resolver links only an object it confirms; pre-check of the pair; unique `(tenant_id, subject_type, subject_id, host_type, host_id)` | Merged / non-active parent: 409; a racing add: one more run returns the existing link (idempotent) | 409 domain |
| Host timeline entry | The parent record `FOR UPDATE` first; insert only | Merged / non-active parent: 409 | 409 domain |
| Consent record | The contact `FOR UPDATE` first; insert only; the current state is the latest `(recorded_at, id)` per purpose (UUIDv7 ids order rows of the same second) | Merged / erased contact: 409 (a merge or an erasure waits for it, or it waits for them) | 409 domain |
| Settings | Version 0 = insert (primary key `tenant_id`), else `version` | A racing first save or a moved version: `crm_settings_stale` | 409 domain |
| Import upload | One unit: the import row and its parsed rows (unique `(import_id, row_no)`) | — | — |
| **Import confirm / run** | Claim by conditional `UPDATE`; per chunk the import row `FOR UPDATE`; rows settled `WHERE outcome = 'pending'`; **before any row of a chunk is settled, the existing contacts of its e-mails are locked in id order, then the companies its rows name in id order** (UD2-L-1); counters by atomic `UPDATE` ([Import](#import)) | Second confirm: `crm_import_running`; a racing e-mail in a chunk: the chunk runs once more, the row becomes `duplicate` / `updated`; refused again: `crm_write_conflict`. An existing contact a row updates is locked `FOR UPDATE` (status re-read: merged / erased ⇒ `duplicate`), then the named company | `crm_import_running`; `crm_write_conflict` (409 domain); a lock wait ⇒ `CONFLICT_002` when the claim of the same request already committed |
| Import cancel | Conditional `UPDATE … WHERE status = 'preview'` (or `running` with a stale claim) | Lost race: `crm_import_running` | 409 domain |
| **Merge** | Both records `FOR UPDATE` in id order, versions re-checked ([Merge](#merge)) | `crm_merge_stale` | 409 domain |
| GDPR erasure | Contact `FOR UPDATE`, status re-checked; children deleted by primary key after an exact re-select; the merge tombstones pointing at it, and theirs in turn, `FOR UPDATE` and blanked, their merge traces deleted | Already erased: nothing to do (idempotent) | — |
| GDPR export | A unit (it writes the `exported` timeline entry) | — | — |
| Retention purge (command) | Single run: a claim row (`__crm__` / `__purge__` in `crm_counter`): locked like a counter row, then a conditional `UPDATE`; a claim older than an hour is taken over. Every batch refreshes the claim in its own unit (`WHERE value = <this run's claim>`); the release in `finally` frees it only while it is still this run's. One unit per batch of 200: trashed records and merge tombstones (past `merge_keep_days`) re-selected `FOR UPDATE` and re-checked (status, date); timeline entries, import rows and merge traces deleted by id with the tenant, counted by the rows really deleted | A second run: "another purge is running", **at once** (UD2-L-3: the claim row is read `FOR UPDATE SKIP LOCKED`, so a run never waits on a batch of another run; between batches the conditional `UPDATE` refuses it); a run whose claim was taken over stops at its next batch; a record restored meanwhile is skipped | — |
| Counter rebuild (command) | One unit per tenant and resource: the counter row `FOR UPDATE` first (selected, inserted only when missing, selected again), then the count of active rows, then set; a create waits for it | — | — |

## Plan-limit counters

`crm_counter (tenant_id, resource, value)`, one row per tenant and resource (`companies`, `contacts`):

```sql
UPDATE nowo_crm_counter SET value = value + 1 WHERE tenant_id = ? AND resource = ? AND value < ?   -- no limit: without the last condition
```

One row changed: taken. None: if the counter row exists, the limit is reached (`crm_limit_reached`, no
`Retry-After`: a retry cannot succeed); if it does not exist yet, it is created from a count of the active rows with
`INSERT IGNORE` (MySQL / MariaDB), `INSERT … ON CONFLICT DO NOTHING` (PostgreSQL) or `INSERT OR IGNORE` (SQLite), so a
racing creation never fails the statement (a PostgreSQL transaction stays usable), and the `UPDATE` runs again. The
counter moves in the record's transaction, so a rollback restores it: −1 on trash, merge and erasure of an active
contact, +1 on create and restore. `nowo:crm-kit:counters:rebuild` locks the counter row first, then recounts (after
manual SQL). Never `COUNT()` then insert (REQ-RUNTIME-003 rule 4).

Locking a counter row (rebuild, field define, the purge claim) is `SELECT … FOR UPDATE` first, the insert only when no
row came back, then `SELECT … FOR UPDATE` again. An `INSERT IGNORE` on an existing row before the lock would take a
shared lock on the duplicate in two sessions on MySQL and deadlock them on the upgrade.

## Version checks

Every editable row (records, notes, tags, fields, settings) has `version`. The service compares the version the form
showed before the change (cheap refusal) and Doctrine's optimistic lock compares it again at flush (the guard against
a writer between the read and the flush). A mismatch is a **domain** conflict (`crm_record_stale`,
`crm_settings_stale`, `crm_merge_stale`): retrying the same version cannot succeed, so there is no `Retry-After` and
no `X-Conflict-Code`.

## Merge

```
merge(type, survivor, other, take, vS, vO), one unit:
  SELECT … FROM crm_<type> WHERE id IN (a, b) AND tenant_id = ? ORDER BY id FOR UPDATE   # fixed lock order
  both exist in the tenant (=== in PHP), edit granted on each, both active, versions === (vS, vO), survivor ≠ other
    else crm_merge_stale, nothing changed
  snapshot of other (crm_merge), then other ⇒ tombstone (status merged, merged_into_id, every personal column blank,
    email_key NULL), flushed first so the survivor may take the e-mail
  survivor takes the chosen fields; custom values united (the survivor's win unless taken); owner kept, or the other's
  children of other, re-checked === in PHP, moved by primary key:
    taggings / host links / affiliations the survivor already has are deleted first, the rest moved
    notes, timeline entries, consents moved
  counter −1; crm_merge snapshot; "merged" timeline entry on the survivor
  after the commit: RecordsMerged, audit crm.records.merged
```

A→B and B→A at the same moment: the second waits on the first lock, then sees `merged` and answers 409. A→B and C→A:
the ordered locks serialise them; the later one sees A merged or its version moved. A merge racing an edit of either
record: the edit's version check or the merge's refuses the later one.

## Import

```
confirm   UPDATE crm_import SET status='running', claim_token=?, claimed_at=now, mapping=?, duplicate_policy=?,
                 version=version+1
          WHERE id=? AND tenant_id=? AND status='preview' AND version=?         # one winner; 0 rows ⇒ crm_import_running
run       per chunk of import.chunk_rows, one unit each:
            SELECT status, claim_token FROM crm_import WHERE id=? FOR UPDATE    # still running with my token? else stop
            pending rows ORDER BY row_no LIMIT chunk_rows
            lock the existing contacts of the rows' e-mails FOR UPDATE (id order), then the named companies (id order)
            per row: validate → existing contact by e-mail? (skip / update) → counter +1 or outcome 'limit' → insert
            UPDATE crm_import_row SET outcome=?, record_id=?, message=? WHERE id=? AND outcome='pending'
            UPDATE crm_import SET rows_done = rows_done + n, created_count = created_count + c, …, claimed_at=now
          last chunk: status 'done', finished_at; after the commit ImportCompleted, audit crm.import.completed
resume    UPDATE crm_import SET claim_token=?, claimed_at=now, version=version+1
          WHERE id=? AND tenant_id=? AND status='running' AND claimed_at < now - stale_claim_seconds
```

The confirm request runs `import.sync_rows` rows (`sync_rows / chunk_rows` chunks); the page's "Continue" runs the same
amount again, the rows attributed to the person who clicked (the resume command attributes them to the uploader); `nowo:crm-kit:import:resume` takes over a claim that made no progress for `stale_claim_seconds` (a
killed worker) and finishes it. A double confirm, a double "Continue" or two runners never store a row twice: the
chunk holds the import row lock, a runner whose token was replaced stops, and each row settles once. Two imports of
the same person store one contact (the e-mail key); the second row becomes `duplicate` (or `updated`). No Messenger
handler ships in 0.1.0.

## Isolation and locks

The bundle assumes READ COMMITTED (REQ-DB-003) and short lock waits. Row locks are taken in a fixed order (two records
by id; a parent record before its children; contacts before companies, each kind by id: affiliate, the company's
primary contact, a merge of contacts (the two contacts, then their companies) and an import chunk (all its contacts,
then all its companies) follow it; a survivor before its tombstones; the import
row before its rows), so the paths cannot deadlock against themselves; a deadlock with another path is replayed by
the write unit. SQLite has one writer and no `FOR UPDATE` (the clause is left out there). No lock,
transaction or entity survives the call (FrankenPHP worker mode, kernel reset false).

## Tests

- `tests/Integration/Database/RaceTest.php`: a second kernel on **its own connection** writes while the first holds
  its locks (MySQL 8.4, MariaDB 11.4 and PostgreSQL 16 in CI; the two-connection cases are skipped on SQLite):
  a racing e-mail is a form error and a moved version is stale (injected on every database), crossed merges, two
  edits of one version, two imports confirmed at once with one e-mail, creates at the plan limit, and an HTTP write
  waiting on a lock answering 409 with `CONFLICT_001`, `Retry-After` and `X-Conflict-Code` with the connection usable
  afterwards.
- `tests/Integration/Database/RaceTest.php` also holds a record lock while the second connection records a consent
  and adds a note (both wait and give up with a lock conflict, then see the merged record); holds the `fields.<type>`
  counter row while a define waits (`max_per_subject` never passed); holds the counter row of a rebuild while a create
  waits; holds the purge claim while a second purge waits; and checks a lock conflict after a commit earlier in the
  same request answers `CONFLICT_002`.
- `tests/Integration/Database/RaceTest.php` (0.2.0): two role edits of one record (the second waits on the record
  lock, then runs; an injected racing role row is one more run, never a duplicate), two "set primary contact" calls on
  one company (the second waits, gives up, then wins; never two primary contacts), an import chunk that waits on a
  locked company before it settles any row (nothing stored, then the whole chunk), and a second purge that answers
  "Another purge is running" while a batch holds the claim row.
- `tests/Integration/Database/HostKindsAndWorkerTest.php`: on the single-entity-manager host a stale edit leaves the
  host's manager open and its entity managed; a write inside a host transaction is refused.
- `tests/Integration/Database/GdprTest.php`: erasure blanks the merge tombstones in the database rows (not only the
  export); the purge deletes tombstones after `merge_keep_days`.
- `tests/Integration/Database/EdgeCasesTest.php`: an injected racing writer on every unique key (field key, host link,
  affiliation, settings, tag name, tag rename, tagging), an import chunk replayed on a racing e-mail (and the lock
  conflict when it races twice), exact company matching of imports, purge of every child kind, the store's exact
  re-check of what the database returns.
