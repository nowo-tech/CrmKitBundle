# Upgrading

## Table of contents

- [Unreleased (0.1.0, first release)](#unreleased-010-first-release)
- [To 0.1.0](#to-010)
- [Policy for later releases](#policy-for-later-releases)

## Unreleased (0.1.0, first release)

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

- Each phase (0.2.0 pipeline … 0.7.0 reports) adds **new tables only**, in its own mapping group registered when the
  phase is enabled. A minor release never alters a table of an earlier phase except to add nullable columns or
  indexes; this file lists the DDL of every release.
- Any change of a column collation ships its detection queries here (REQ-SEC-006 rule 9).
- Breaking changes of public interfaces, events or value objects wait for a major version, or are listed here with the
  replacement.
