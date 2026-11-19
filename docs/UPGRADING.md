# Upgrading

## Table of contents

- [Unreleased](#unreleased)
- [To 0.2.0](#to-020)
- [To 0.1.0](#to-010)
- [Policy for later releases](#policy-for-later-releases)

## Unreleased

**0.2.1 (additive, nothing to change).** `CrmBatchLookupInterface` is a new public service for backend jobs: it
checks no permission and no record visibility, so call it only from trusted server-side code with a tenant your job
already trusts (USAGE "Batch lookups for host jobs"). `CrmKitEvents` gains constants equal to the domain event class
names; listeners registered by class name keep working. No schema change.

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
  names): a row the importer may not change ends `invalid` (`error.forbidden`).
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
