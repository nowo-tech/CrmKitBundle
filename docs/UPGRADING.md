# Upgrading

## Table of contents

- [Unreleased](#unreleased)
- [To 0.2.0](#to-020)
- [To 0.1.0](#to-010)
- [Policy for later releases](#policy-for-later-releases)

## Unreleased

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
    ADD purpose_keys VARCHAR(600) CHARACTER SET utf8mb4 DEFAULT '' NOT NULL COLLATE `utf8mb4_0900_bin`;
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
ALTER TABLE nowo_crm_affiliation ADD purpose_keys VARCHAR(600) DEFAULT '' NOT NULL;
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
**with a default**, which needs no data change and no backfill on any supported database.

**Database versions.** MySQL 8.0, MariaDB 10.6 or PostgreSQL 9.5 at least (`FOR UPDATE SKIP LOCKED` in the purge
claim). MySQL 5.7 and MariaDB before 10.6 are no longer supported.

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

**Behaviour.**

- `affiliate()` updates an existing pair in place (the affiliation keeps its id) instead of deleting and re-inserting it.
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
