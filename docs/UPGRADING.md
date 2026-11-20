# Upgrading

## Table of contents

- [Unreleased](#unreleased)
- [To 0.3.0](#to-030)
- [To 0.2.1](#to-021)
- [To 0.2.0](#to-020)
- [To 0.2.0](#to-020-1)
- [To 0.1.0](#to-010)
- [Policy for later releases](#policy-for-later-releases)

## Unreleased

## To 0.3.0

From **0.2.1**:

```bash
composer update nowo-tech/crm-kit-bundle
```

**Funnels (0.3.0, `pipeline.enabled`).** Nothing changes while the funnels stay off: no table is mapped and no route
is added. To turn them on, set `nowo_crm_kit.pipeline.enabled: true` and create the tables (`doctrine:migrations:diff
--em=nowo_crm_kit` produces the same DDL as below; `<p>` is `doctrine.table_prefix`, `nowo_` by default).

- `SubjectType` gains `Deal` (`deal`; `segment()` `deals`, `isRecord()` false) and `CrmResource` gains `Deals`.
  `nowo_crm_kit.record_access_check` listeners that assumed `company | contact` must expect `deal`
  (`getSubjectType()`); a `match` over `SubjectType` in host code needs the new case.
- `nowo:crm-kit:purge` returns and prints a `deals` count; `RecordPurger::purge()` gains the `deals` key.
- The 360° page renders a funnels block while they are on; the area navigation gains Deals and Funnels.

MySQL 8.4 / MariaDB 11.4 (identifier columns use `utf8mb4_0900_bin` on MySQL, `utf8mb4_nopad_bin` on MariaDB):

```sql
CREATE TABLE nowo_crm_funnel (id VARCHAR(36) NOT NULL, tenant_id VARCHAR(128) NOT NULL, kind VARCHAR(16) NOT NULL, subject_type VARCHAR(16) DEFAULT NULL, name VARCHAR(120) NOT NULL, position INT NOT NULL, archived TINYINT(1) NOT NULL, version INT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME(6) NOT NULL, INDEX nowo_crm_funnel_list (tenant_id, kind, archived, position), PRIMARY KEY (id));
CREATE TABLE nowo_crm_stage (id VARCHAR(36) NOT NULL, tenant_id VARCHAR(128) NOT NULL, funnel_id VARCHAR(36) NOT NULL, name VARCHAR(80) NOT NULL, position INT NOT NULL, kind VARCHAR(8) NOT NULL, probability SMALLINT NOT NULL, rot_days INT DEFAULT NULL, archived TINYINT(1) NOT NULL, version INT DEFAULT 1 NOT NULL, INDEX nowo_crm_stage_funnel (tenant_id, funnel_id, position), PRIMARY KEY (id));
CREATE TABLE nowo_crm_deal (id VARCHAR(36) NOT NULL, tenant_id VARCHAR(128) NOT NULL, funnel_id VARCHAR(36) NOT NULL, stage_id VARCHAR(36) NOT NULL, title VARCHAR(200) NOT NULL, company_id VARCHAR(36) DEFAULT NULL, contact_id VARCHAR(36) DEFAULT NULL, owner_id VARCHAR(128) DEFAULT NULL, amount NUMERIC(18, 4) DEFAULT NULL, currency VARCHAR(3) DEFAULT NULL, probability SMALLINT DEFAULT NULL, expected_close DATE DEFAULT NULL, status VARCHAR(16) NOT NULL, trashed_from VARCHAR(16) DEFAULT NULL, lost_reason VARCHAR(32) DEFAULT NULL, lost_note VARCHAR(500) DEFAULT NULL, stage_entered_at DATETIME(6) NOT NULL, closed_at DATETIME DEFAULT NULL, next_action_at DATETIME DEFAULT NULL, trashed_at DATETIME DEFAULT NULL, version INT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, created_by_id VARCHAR(128) DEFAULT NULL, updated_at DATETIME(6) NOT NULL, updated_by_id VARCHAR(128) DEFAULT NULL, INDEX nowo_crm_deal_stage (tenant_id, funnel_id, stage_id, status), INDEX nowo_crm_deal_keyset_updated (tenant_id, status, updated_at, id), INDEX nowo_crm_deal_company (tenant_id, company_id), INDEX nowo_crm_deal_contact (tenant_id, contact_id), INDEX nowo_crm_deal_owner (tenant_id, owner_id), PRIMARY KEY (id));
CREATE TABLE nowo_crm_stage_change (id VARCHAR(36) NOT NULL, tenant_id VARCHAR(128) NOT NULL, item_type VARCHAR(16) NOT NULL, item_id VARCHAR(36) NOT NULL, funnel_id VARCHAR(36) NOT NULL, from_stage_id VARCHAR(36) DEFAULT NULL, to_stage_id VARCHAR(36) NOT NULL, actor_id VARCHAR(128) DEFAULT NULL, via VARCHAR(16) DEFAULT NULL, occurred_at DATETIME(6) NOT NULL, seconds_in_from BIGINT DEFAULT NULL, INDEX nowo_crm_stage_change_funnel (tenant_id, funnel_id, occurred_at), INDEX nowo_crm_stage_change_item (tenant_id, item_type, item_id, occurred_at), PRIMARY KEY (id));
CREATE TABLE nowo_crm_party_stage (id VARCHAR(36) NOT NULL, tenant_id VARCHAR(128) NOT NULL, funnel_id VARCHAR(36) NOT NULL, subject_type VARCHAR(16) NOT NULL, subject_id VARCHAR(36) NOT NULL, stage_id VARCHAR(36) NOT NULL, entered_at DATETIME(6) NOT NULL, version INT DEFAULT 1 NOT NULL, INDEX nowo_crm_party_stage_stage (tenant_id, funnel_id, stage_id), UNIQUE INDEX nowo_crm_party_stage_unique (funnel_id, subject_id), PRIMARY KEY (id));
CREATE TABLE nowo_crm_next_action (id VARCHAR(36) NOT NULL, tenant_id VARCHAR(128) NOT NULL, subject_type VARCHAR(16) NOT NULL, subject_id VARCHAR(36) NOT NULL, title VARCHAR(200) NOT NULL, due_at DATETIME NOT NULL, assignee_id VARCHAR(128) DEFAULT NULL, status VARCHAR(16) NOT NULL, task_type VARCHAR(32) DEFAULT NULL, task_id VARCHAR(128) DEFAULT NULL, dedupe_key VARCHAR(64) NOT NULL, created_by_id VARCHAR(128) DEFAULT NULL, created_at DATETIME NOT NULL, done_at DATETIME DEFAULT NULL, version INT DEFAULT 1 NOT NULL, INDEX nowo_crm_next_action_subject (tenant_id, subject_type, subject_id, status, due_at), UNIQUE INDEX nowo_crm_next_action_dedupe (tenant_id, dedupe_key), PRIMARY KEY (id));
```

PostgreSQL 16: the same tables with `BOOLEAN` for `archived`, `TIMESTAMP(0) WITHOUT TIME ZONE` for the plain
instants, `TIMESTAMP(6) WITHOUT TIME ZONE` for `updated_at`, `stage_entered_at`, `occurred_at`, `entered_at`, and
`CREATE INDEX` / `CREATE UNIQUE INDEX` statements for the keys. SQLite: `TEXT` / `INTEGER` / `NUMERIC` columns with the
same keys. No column of an earlier table changes and no backfill is needed; the `deals` row of `crm_counter` is created
on the first deal.

**The API (0.3.0, `api.enabled`, `api.http.enabled`).** Nothing changes while both stay off. No table is mapped and
no route is added.

- **Removed**: the root key `access_checker` (deprecated since 0.2.0) and `LegacyCrmAccessChecker`. Move a 0.1.0 checker
  to `security.access_checker` (a `CrmKitAccessCheckerInterface`), and move record rules to
  `nowo_crm_kit.record_access_check` listeners. `CrmPermission::legacy()` is removed too.
- **transaction-doctrine ^1.8.1** is required (`composer update nowo-tech/transaction-doctrine`). It fixes
  GHSA-9wh3-m3m5-jffx and GHSA-65w2-9r4h-848g, and a write-unit replay no longer clears your entity manager: it is put
  back to what the database holds (see CONCURRENCY).
- **`ext-sodium`** is needed for the API's sealed cursors. It ships with PHP and is on by default.
- **Turn the API on.** Set `api.enabled: true` and create the idempotency table and the keyset indexes:

```sql
-- MySQL 8.4 / MariaDB 11.4 (MariaDB: utf8mb4_nopad_bin); <p> = nowo_
CREATE TABLE nowo_crm_api_idempotency (scope_hash VARCHAR(64) NOT NULL, key_hash VARCHAR(64) NOT NULL, tenant_id VARCHAR(128) NOT NULL, operation VARCHAR(48) NOT NULL, fingerprint VARCHAR(64) NOT NULL, outcome SMALLINT DEFAULT NULL, result_ids JSON DEFAULT NULL, created_at DATETIME(6) NOT NULL, expires_at DATETIME NOT NULL, INDEX nowo_crm_api_idem_expires (expires_at), PRIMARY KEY (scope_hash, key_hash));
CREATE INDEX nowo_crm_company_keyset_name    ON nowo_crm_company (tenant_id, status, name_key, id);
CREATE INDEX nowo_crm_company_keyset_updated ON nowo_crm_company (tenant_id, status, updated_at, id);
CREATE INDEX nowo_crm_contact_keyset_name    ON nowo_crm_contact (tenant_id, status, sort_key, id);
CREATE INDEX nowo_crm_contact_keyset_updated ON nowo_crm_contact (tenant_id, status, updated_at, id);
CREATE INDEX nowo_crm_note_keyset            ON nowo_crm_note (tenant_id, subject_type, subject_id, pinned, id);
```

  The hash, operation and id columns use the binary collation (the metadata listener sets it, and
  `doctrine:migrations:diff --em=nowo_crm_kit` produces the same DDL). PostgreSQL 16 uses `JSON` and
  `TIMESTAMP(6) WITHOUT TIME ZONE` / `TIMESTAMP(0) WITHOUT TIME ZONE`. SQLite uses `CLOB` / `DATETIME`.
- **Every host migrates the five keyset indexes**, with or without the API: they are part of the base mapping (the
  pages and the API lists use them), so `doctrine:schema:validate` reports them missing until they exist. The
  idempotency table is needed only with `api.enabled`.
- **Lists hide records a listener denies (BC change, owner decision Q2).** Every list, search, related label and
  timeline now passes its rows through the record decision (`nowo_crm_kit.record_access_check`, batched per page),
  not only through the SQL visibility of `security.resource_scoping`. A record a host listener denies `view` no longer
  appears in the 0.2 web lists, the CSV export of a list, a company's contacts, a contact's companies, the company
  timeline (its contacts' entries), the duplicate hints or the merge preview. A list page can then be shorter than the
  page size (a denied row still takes its slot), a page can be empty while the pager offers more, and its total is the
  SQL count less the denied rows of that page, so it can differ from page to page: it reveals where denied rows sit in
  the order, never their content. Funnel statistics are aggregated in SQL
  and still count deals that only a listener denies (their visibility is the owner scoping); they never show such a
  deal itself.
- **Header search by tax id.** The groups match the stored tax id key (upper case, without separators) as typed: a
  query with separators (`B-12`) no longer matches; search `B12`.
- **TaskBoard bridge.** `pipeline.taskboard` names one board for every tenant: it is meant for a single-tenant host
  (or a host whose board is shared on purpose); a multi-tenant host implements `CrmTaskPort` itself. Erasing a contact
  deletes its next actions but not the tasks a host port created for them; remove them on your side
  (`ContactErased` event) when your retention requires it.
- **JSON API.** Set `api.http.enabled: true`, import the routes (`resource: .`, `type: nowo_crm_kit_api`) and protect
  `^%nowo_crm_kit.api_path%/` with a firewall. Without `api.http.authenticator`, the firewall's user is the principal,
  with `api.http.default_grant` (view only by default).
- **Listeners**: `RecordAccessCheckEvent` / `RecordVisibilityEvent` gain `getChannel()` (`web`, `api`, `mcp`) and
  `getTokenId()`. A deny for agents only looks like `if ($event->getChannel() !== 'web') { $event->deny(); }`.
- **`AuditEntry`** gains `?AuditOrigin $origin` (the last constructor argument). A host sink that copies fields keeps
  working; one that wants the token reads `$entry->origin`.
- **`CrmCallerInterface`** is new and internal. A host never implements it: the bundle builds the callers.

**Header search (0.3.0).** The SearchKit groups need **SearchKit ^1.9** (`composer require nowo-tech/search-kit-bundle:^1.9`,
PHP >= 8.3); with an older SearchKit they are not registered. Their keys stay `crm_companies` and `crm_contacts`, plus
`crm_deals` with `pipeline.enabled`; `nowo_search_kit.groups.<key>` switches one off. The services
`nowo_crm_kit.search.company` / `.contact` are now `EntitySearchDefinition`s (tag `nowo_search_kit.entity_definition`)
and `Bridge\SearchKit\CrmSearchProvider` is removed: a host that decorated or called it uses SearchKit's generated
provider (`nowo_search_kit.entity_provider.nowo_crm_kit.search.company`, …) instead. Results now also pass SearchKit's
`nowo_search_kit.item_visibility` event.

## To 0.2.1

From **0.2.0**:

```bash
composer update nowo-tech/crm-kit-bundle
```

**0.2.1 (additive, nothing to change).** `CrmBatchLookupInterface` is a new public service for backend jobs: it
checks no permission and no record visibility, so call it only from trusted server-side code with a tenant your job
already trusts (USAGE "Batch lookups for host jobs"). `CrmKitEvents` gains constants equal to the domain event class
names; listeners registered by class name keep working. The retention purge now also dispatches `RecordsPurged` per
committed batch (no listener: nothing changes); `CrmBatchLookupInterface::existing()` is new. No schema change.

**Actor ids (`security.actor_resolver`).** Nothing changes until you configure a resolver: the default keeps
`getUserIdentifier()`. The new system API `CrmActorPersonalDataInterface` (`exportActor()`, `anonymiseActor()`) is a
separate service: `CrmPersonalDataInterface` is unchanged. `RecordViews`, `SecurityCrmAccessChecker` and
`SecurityActorDirectory` take the resolver as a new trailing argument with a default.

- The resolver feeds `SecurityCrmAccessChecker` (the default `access_checker` port) and the default directory. With a
  host `access_checker` of your own (or a 0.1.0 checker wrapped by `LegacyCrmAccessChecker`), the bundle does not ask
  the resolver for `currentActor()`: return `idOf($user)` from your checker yourself.
- `nowo_crm_kit.record_visibility` listeners and `record_access_check` listeners compare owners with the actor id:
  after the switch they must add and compare the new ids.
- `anonymised` is reserved (`ActorRef::ANONYMISED`): a checker, resolver, directory or visibility listener must never
  return it (the bundle refuses it anyway: no actor, no owner, no visible owner).

Switching an existing installation from identifiers (e-mails) to stable ids rewrites every stored actor id. Stop the
writers (maintenance mode, the import resume cron and the retention purge off), take a backup, fill a mapping table
from your users (old identifier ⇒ new id), run the pre-check, run the UPDATEs in one transaction, then deploy the
resolver. On MySQL and MariaDB `CREATE TABLE` and `DROP TABLE` commit implicitly: create and fill the map first, then
`START TRANSACTION`, the UPDATEs, `COMMIT`, and drop the map afterwards (PostgreSQL may run it all in one transaction).
Default prefix `nowo_`; the map uses the same binary collation as the CRM columns so the comparison is exact.

```sql
-- MySQL 8.4
CREATE TABLE crm_actor_map (old_id VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL PRIMARY KEY,
                            new_id VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL);
-- MariaDB 11.4
CREATE TABLE crm_actor_map (old_id VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL PRIMARY KEY,
                            new_id VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL);
-- PostgreSQL 16 (byte-exact by default)
CREATE TABLE crm_actor_map (old_id VARCHAR(128) NOT NULL PRIMARY KEY, new_id VARCHAR(128) NOT NULL);

INSERT INTO crm_actor_map (old_id, new_id) SELECT email, uuid FROM app_user;   -- your users

-- Pre-check: must return no row. A new id may not be reserved ('anonymised', 'anonymous'), nor an id already stored
-- (in a column or inside a timeline / merge JSON document) that the map does not replace, nor shared by two old ids:
-- each would merge two people. MySQL 8.4 / MariaDB 11.4:
WITH stored_ids (id) AS (
    SELECT owner_id FROM nowo_crm_company UNION SELECT created_by_id FROM nowo_crm_company UNION SELECT updated_by_id FROM nowo_crm_company
    UNION SELECT owner_id FROM nowo_crm_contact UNION SELECT created_by_id FROM nowo_crm_contact UNION SELECT updated_by_id FROM nowo_crm_contact
    UNION SELECT author_id FROM nowo_crm_note UNION SELECT actor_id FROM nowo_crm_activity UNION SELECT recorded_by_id FROM nowo_crm_consent
    UNION SELECT created_by_id FROM nowo_crm_host_link UNION SELECT created_by_id FROM nowo_crm_role_assignment
    UNION SELECT created_by_id FROM nowo_crm_import UNION SELECT actor_id FROM nowo_crm_merge UNION SELECT updated_by_id FROM nowo_crm_settings
    UNION SELECT JSON_UNQUOTE(JSON_EXTRACT(params, '$.owner')) FROM nowo_crm_activity WHERE kind = 'owner_changed'
    UNION SELECT JSON_UNQUOTE(JSON_EXTRACT(snapshot, '$.owner')) FROM nowo_crm_merge
)
SELECT m.new_id FROM crm_actor_map m
 WHERE m.new_id IN ('anonymised', 'anonymous')
    OR (m.new_id IN (SELECT id FROM stored_ids) AND m.new_id NOT IN (SELECT old_id FROM crm_actor_map))
    OR m.new_id IN (SELECT new_id FROM crm_actor_map GROUP BY new_id HAVING COUNT(*) > 1);
-- PostgreSQL 16: the same query with the JSON owners read by ->>
WITH stored_ids (id) AS (
    SELECT owner_id FROM nowo_crm_company UNION SELECT created_by_id FROM nowo_crm_company UNION SELECT updated_by_id FROM nowo_crm_company
    UNION SELECT owner_id FROM nowo_crm_contact UNION SELECT created_by_id FROM nowo_crm_contact UNION SELECT updated_by_id FROM nowo_crm_contact
    UNION SELECT author_id FROM nowo_crm_note UNION SELECT actor_id FROM nowo_crm_activity UNION SELECT recorded_by_id FROM nowo_crm_consent
    UNION SELECT created_by_id FROM nowo_crm_host_link UNION SELECT created_by_id FROM nowo_crm_role_assignment
    UNION SELECT created_by_id FROM nowo_crm_import UNION SELECT actor_id FROM nowo_crm_merge UNION SELECT updated_by_id FROM nowo_crm_settings
    UNION SELECT params->>'owner' FROM nowo_crm_activity WHERE kind = 'owner_changed'
    UNION SELECT snapshot->>'owner' FROM nowo_crm_merge
)
SELECT m.new_id FROM crm_actor_map m
 WHERE m.new_id IN ('anonymised', 'anonymous')
    OR (m.new_id IN (SELECT id FROM stored_ids) AND m.new_id NOT IN (SELECT old_id FROM crm_actor_map))
    OR m.new_id IN (SELECT new_id FROM crm_actor_map GROUP BY new_id HAVING COUNT(*) > 1);

-- Every id column (portable SQL). Repeat for each pair below; add ", version = version + 1" on the versioned tables
-- (company, contact, note, import, settings) so open forms fail as stale.
UPDATE nowo_crm_company SET owner_id = (SELECT m.new_id FROM crm_actor_map m WHERE m.old_id = nowo_crm_company.owner_id),
       version = version + 1
 WHERE owner_id IN (SELECT old_id FROM crm_actor_map);
--   nowo_crm_company: owner_id, created_by_id, updated_by_id      nowo_crm_contact: owner_id, created_by_id, updated_by_id
--   nowo_crm_note: author_id          nowo_crm_activity: actor_id          nowo_crm_consent: recorded_by_id
--   nowo_crm_host_link: created_by_id nowo_crm_role_assignment: created_by_id nowo_crm_import: created_by_id
--   nowo_crm_merge: actor_id          nowo_crm_settings: updated_by_id

-- The owner inside JSON documents. MySQL / MariaDB:
UPDATE nowo_crm_activity a JOIN crm_actor_map m ON m.old_id = JSON_UNQUOTE(JSON_EXTRACT(a.params, '$.owner'))
   SET a.params = JSON_SET(a.params, '$.owner', m.new_id) WHERE a.kind = 'owner_changed';
UPDATE nowo_crm_merge r JOIN crm_actor_map m ON m.old_id = JSON_UNQUOTE(JSON_EXTRACT(r.snapshot, '$.owner'))
   SET r.snapshot = JSON_SET(r.snapshot, '$.owner', m.new_id);
-- PostgreSQL:
UPDATE nowo_crm_activity a SET params = jsonb_set(a.params::jsonb, '{owner}', to_jsonb(m.new_id))::json
  FROM crm_actor_map m WHERE a.kind = 'owner_changed' AND m.old_id = a.params->>'owner';
UPDATE nowo_crm_merge r SET snapshot = jsonb_set(r.snapshot::jsonb, '{owner}', to_jsonb(m.new_id))::json
  FROM crm_actor_map m WHERE m.old_id = r.snapshot->>'owner';

DROP TABLE crm_actor_map;
```

Ids your resolver keeps (people without a new id) need no row in the map. The audit entries your sink already stored
keep the old identifiers: map them in the sink's own storage if you need to.

**Imports paused by the resume command.** An import that `nowo:crm-kit:import:resume` stopped before a row needing a
person now releases its claim and waits for a person (`ImportView::$awaitingPerson`, a new trailing constructor
argument with a default); the resume command skips it and the page's "Continue" takes it at once. No migration: the
flag is a running import with an empty `claim_token`. An import paused by 0.2.0 still holds the resume command's
claim; after the upgrade it is taken once more when that claim goes stale, paused again and released, or a person's
"Continue" runs it at any time, as before.

## To 0.2.0

From **0.1.0**:

```bash
composer update nowo-tech/crm-kit-bundle
```

## To 0.2.0

Party roles, the company's primary contact and affiliation purposes; the UD-2 concurrency follow-ups.

**Database.** One new table and two new `crm_affiliation` columns, both `NOT NULL` with a default, so existing rows
need no backfill (every affiliation starts without purposes and without a primary contact). Generate the migration
with `php bin/console doctrine:migrations:diff --em=nowo_crm_kit` (the metadata listener adds the binary collations),
or apply this DDL (default prefix `nowo_`):

```sql
-- MySQL 8.0+ (MariaDB 10.6+: utf8mb4_nopad_bin instead of utf8mb4_0900_bin)
ALTER TABLE nowo_crm_affiliation
    ADD is_primary_contact TINYINT(1) DEFAULT 0 NOT NULL,
    ADD purpose_keys VARCHAR(1100) CHARACTER SET utf8mb4 DEFAULT '' NOT NULL COLLATE `utf8mb4_0900_bin`;
CREATE TABLE nowo_crm_role_assignment (
    id VARCHAR(36) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    tenant_id VARCHAR(128) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    subject_type VARCHAR(16) NOT NULL,
    subject_id VARCHAR(36) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    role_key VARCHAR(32) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    created_at DATETIME NOT NULL,
    created_by_id VARCHAR(128) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_bin`,
    UNIQUE INDEX nowo_crm_role_assignment_key (tenant_id, subject_id, role_key),
    INDEX nowo_crm_role_assignment_role (tenant_id, role_key, subject_type),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

-- PostgreSQL 9.5+
ALTER TABLE nowo_crm_affiliation ADD is_primary_contact BOOLEAN DEFAULT false NOT NULL;
ALTER TABLE nowo_crm_affiliation ADD purpose_keys VARCHAR(1100) DEFAULT '' NOT NULL;
CREATE TABLE nowo_crm_role_assignment (
    id VARCHAR(36) NOT NULL,
    tenant_id VARCHAR(128) NOT NULL,
    subject_type VARCHAR(16) NOT NULL,
    subject_id VARCHAR(36) NOT NULL,
    role_key VARCHAR(32) NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    created_by_id VARCHAR(128) DEFAULT NULL,
    PRIMARY KEY (id)
);
CREATE UNIQUE INDEX nowo_crm_role_assignment_key ON nowo_crm_role_assignment (tenant_id, subject_id, role_key);
CREATE INDEX nowo_crm_role_assignment_role ON nowo_crm_role_assignment (tenant_id, role_key, subject_type);
```

**Edit times to the microsecond.** `updated_at` of companies, contacts, notes and settings becomes `DATETIME(6)` /
`TIMESTAMP(6)` (type `nowo_crm_utc_datetime_micro`), so every write moves the record's optimistic version, also two
role-, tag- or affiliation-only changes in one second. Existing values keep their seconds (fraction zero):

```sql
-- MySQL 8.0+ / MariaDB 10.6+
ALTER TABLE nowo_crm_company MODIFY updated_at DATETIME(6) NOT NULL;
ALTER TABLE nowo_crm_contact MODIFY updated_at DATETIME(6) NOT NULL;
ALTER TABLE nowo_crm_note MODIFY updated_at DATETIME(6) NOT NULL;
ALTER TABLE nowo_crm_settings MODIFY updated_at DATETIME(6) NOT NULL;

-- PostgreSQL
ALTER TABLE nowo_crm_company ALTER COLUMN updated_at TYPE TIMESTAMP(6) WITHOUT TIME ZONE;
ALTER TABLE nowo_crm_contact ALTER COLUMN updated_at TYPE TIMESTAMP(6) WITHOUT TIME ZONE;
ALTER TABLE nowo_crm_note ALTER COLUMN updated_at TYPE TIMESTAMP(6) WITHOUT TIME ZONE;
ALTER TABLE nowo_crm_settings ALTER COLUMN updated_at TYPE TIMESTAMP(6) WITHOUT TIME ZONE;
```

Run these by hand or put them in a migration: on DBAL 4 `doctrine:migrations:diff` does **not** produce them (the
schema comparator ignores the column definition the metadata listener declares, so the existing whole-second column
looks unchanged); on DBAL 3 the diff emits them once. A fresh schema (`doctrine:schema:create`, a first migration)
gets the microsecond columns directly. On MySQL / MariaDB the
`MODIFY` copies the table and PostgreSQL may rewrite it: run it in a maintenance window on large tables.

Contract section 17 allows only nullable columns or indexes on an earlier table; the two new columns are `NOT NULL`
**with a default**, which needs no backfill. On MySQL 8.0+, MariaDB 10.6+ and PostgreSQL 11+ adding them is a metadata
change; **on PostgreSQL 9.5 – 10, `ADD COLUMN … NOT NULL DEFAULT` rewrites the whole table** under an exclusive lock:
run it in a maintenance window on a large `crm_affiliation`.

**Database versions.** MySQL 8.0, MariaDB 10.6 or PostgreSQL 9.5 at least (`FOR UPDATE SKIP LOCKED` in the purge
claim). MySQL 5.7 and MariaDB before 10.6 are no longer supported. Doctrine DBAL sets its own floor too: DBAL 3.9 has
platforms for PostgreSQL from 9.4, while **DBAL 4 starts at PostgreSQL 10** (its lowest platform, `PostgreSQLPlatform`,
is "for version 10.0 and above"; MySQL from 5.7.9, MariaDB from 10.4.3), so with DBAL 4 the minimum is PostgreSQL 10.

**Configuration** (optional): `roles.catalogue` (default `[customer, supplier, partner]`) and
`affiliations.purposes` (default `[billing, orders, technical, general]`), see
[CONFIGURATION.md](CONFIGURATION.md#roles-and-affiliation-purposes). Labels of your own keys go into
`translations/NowoCrmKitBundle.<locale>.yaml` (`role.<key>`, `purpose.<key>`).

**PHP API** (additive; no change needed in a host that only calls the services):

- `RecordInput` takes `roles` (named argument, last; `null` keeps the record's roles).
- `ListQuery` takes `role`, `purpose` and `primaryContactOnly` (named arguments, last).
- `RecordServiceInterface::affiliate()` takes `primaryContact` (`?bool`, null keeps) and `purposes` (`?list`, null
  keeps); new `setPrimaryContact()` and `setRoles()`. **A class of your own that implements
  `RecordServiceInterface`** (unusual: the bundle's service is the implementation) must add them.
- `RecordView::$roles`, `AffiliationView::$primaryContact` and `AffiliationView::$purposes` (constructor arguments
  with defaults, last).
- New `CrmCatalogueInterface`, `RoleKey`, `AffiliationPurpose`, event `RolesChanged`, timeline kinds
  `roles_changed` and `primary_contact`, audit keys `crm.roles.changed` and `crm.affiliation.primary_contact`
  (map them in your audit sink if it keeps a list).
- The CSV export has a new `roles` column after `owner`: a consumer that reads columns by position must move.

**Access (REQ-UI-002), breaking for the configuration.**

- `access.roles` is **removed**: map its lists onto the new `security` node.

  ```yaml
  # 0.1.0                              # 0.2.0
  access:                              security:
      roles:                               access_roles: [ROLE_USER]        # was view
          view: [ROLE_USER]                manage_roles: [ROLE_SALES]       # was edit (create, edit, trash, import)
          edit: [ROLE_SALES]               settings_roles: [ROLE_ADMIN]     # was manage (tags, fields, settings, purge)
          manage: [ROLE_ADMIN]             export_roles: [ROLE_ADMIN]       # was manage (CSV and GDPR export)
                                           erase_roles: [ROLE_ADMIN]        # was manage (GDPR erasure)
  ```

  The new lists default to `[ROLE_ADMIN]` (0.1.0 granted nothing by default). Every area also needs `access_roles`.
  **An empty list now means "any authenticated user" for that area (in 0.1.0 an empty list denied everything).**
  `settings_roles`, `export_roles` and `erase_roles` must name at least one role. `amounts_roles` and
  `campaigns_roles` (default `[ROLE_ADMIN]`) are ready for the later phases' amounts and circulars.
- `security.resource_scoping: true` limits lists, totals, search, exports, related records and record pages to
  ownerless records, the person's own and the owners a `nowo_crm_kit.record_visibility` listener adds; people with
  `settings_roles` (or the host checker's `canManageSettings`) see everything.
- `security.allow_unauthenticated` is refused in the `prod` environment and turns resource scoping off.
- With `ui.enabled: true` the build now **fails without SecurityBundle** unless `security.allow_unauthenticated: true`
  (demos only).
- The root key `access_checker` (a `CrmAccessCheckerInterface` of yours) still works in 0.2.0 and is **deprecated**
  (removed in 0.3.0): the bundle wraps it and asks the new permissions `create`, `delete`, `import` as `edit` and
  `export`, `erase` as `manage`, so a 0.1.0 implementation keeps working unchanged. Move to
  `security.access_checker` (`CrmKitAccessCheckerInterface`) plus `nowo_crm_kit.record_access_check` listeners for
  rules on one record ([USAGE.md](USAGE.md#access-checker)).
- `CrmPermission` has five new cases (`create`, `delete`, `import`, `export`, `erase`): a `match` on it in your code
  needs them; `CrmPermission::legacy()` gives the 0.1.0 equivalent.
- On the pages, nobody signed in now meets the firewall's entry point (login redirect or 401) instead of a 404; no
  tenant or the CRM off for the tenant are still 404, a refused permission 403.
- `RoleMapAccessChecker` is gone (replaced by `SecurityCrmAccessChecker` + `ConfigurableCrmKitAccessChecker`).

**Behaviour.**

- `affiliate()` updates an existing pair in place (the affiliation keeps its id) instead of deleting and re-inserting it;
  its `purposes` replace only the configured purposes (stored ones no longer configured are kept).
- `ListQuery` refuses a `role` or `purpose` that is not a valid key (`InvalidInputException`).
- An import with the `update` policy needs `edit` on each existing contact it updates (and `view` on a company it
  names; a company the importer may not see under resource scoping counts as unknown): a row the importer may not
  change ends `invalid` (`error.forbidden`).
- `nowo:crm-kit:import:resume` runs with nobody signed in: it settles the rows that need no decision on one record and
  stops before the first row that updates an existing contact or names a company, leaving it pending for the next
  person's "Continue" on the import page.
- More than 1 000 visible owners from `nowo_crm_kit.record_visibility` listeners is refused (`LogicException`): use
  `allowAll()` for such a person and decide single records with `nowo_crm_kit.record_access_check`.
- A merge needs `delete` on the merged record too.
- A second `nowo:crm-kit:purge` started while a batch of the first holds the claim row prints "Another purge is
  running" at once instead of failing with a lock conflict.
- An import chunk locks the existing contacts and the named companies of all its rows first (in id order), then settles
  the rows.
- A CRM write replayed after a deadlock clears your entity manager too (unchanged from 0.1.0, now documented):
  [INSTALLATION.md](INSTALLATION.md#transaction-bundle-required).

## To 0.1.0

First release. Install it with:

```bash
composer require nowo-tech/crm-kit-bundle
```

0.1.0 is the first release (phase A: companies and contacts). There is nothing to upgrade from; install it as
described in [INSTALLATION.md](INSTALLATION.md):

```bash
composer require nowo-tech/crm-kit-bundle
php bin/console doctrine:migrations:diff --em=nowo_crm_kit
php bin/console doctrine:migrations:migrate --em=nowo_crm_kit
```

- **Tables** (prefix `doctrine.table_prefix`, default `nowo_`): `crm_settings`, `crm_company`, `crm_contact`,
  `crm_affiliation`, `crm_tag`, `crm_tagging`, `crm_field`, `crm_note`, `crm_activity`, `crm_consent`,
  `crm_host_link`, `crm_import`, `crm_import_row`, `crm_merge`, `crm_counter`. Reference DDL for MySQL 8.4, MariaDB
  11.4 and PostgreSQL 16: [INSTALLATION.md](INSTALLATION.md#reference-ddl). Every `id` / `*_id` and key column has a
  binary NO PAD collation on MySQL / MariaDB.
- **Configuration**: the strict tree of [CONFIGURATION.md](CONFIGURATION.md). The switches of the later phases
  (`pipeline`, `quotes`, `invoices`, `campaigns`, `portal`, `reports`) exist and refuse `true` until their release.
- **Access**: the default checker denies everything until `access.roles` maps roles (or a host checker is
  configured).
- **Commands to schedule**: `nowo:crm-kit:purge` (daily), `nowo:crm-kit:import:resume` (every few minutes).

## Policy for later releases

- Each later phase (0.3.0 funnels … 0.10.0 circulars, then phase G; contract section 25) adds **new tables only**, in its own mapping group registered when the
  phase is enabled. A minor release never alters a table of an earlier phase except to add nullable columns or
  indexes; this file lists the DDL of every release.
- Any change of a column collation ships its detection queries here (REQ-SEC-006 rule 9).
- Breaking changes of public interfaces, events or value objects wait for a major version, or are listed here with the
  replacement.
