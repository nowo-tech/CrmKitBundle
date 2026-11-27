# Installation

## Table of contents

- [Composer](#composer)
- [Private entity manager](#private-entity-manager)
- [Transaction bundle (required)](#transaction-bundle-required)
- [Routes](#routes)
- [Schema and migrations](#schema-and-migrations)
- [Scheduled commands](#scheduled-commands)
- [Optional packages](#optional-packages)
- [Reference DDL](#reference-ddl)

## Composer

```bash
composer require nowo-tech/crm-kit-bundle
```

With Symfony Flex the recipe registers `Nowo\CrmKitBundle\NowoCrmKitBundle` and the required
`Nowo\TransactionDoctrine\Symfony\NowoTransactionDoctrineBundle`, writes
`config/packages/nowo_crm_kit.yaml` (`security.*_roles` at `ROLE_ADMIN`, UI off) and declares the **private entity manager**
`nowo_crm_kit` in `config/packages/nowo_crm_kit_doctrine.yaml`. Without Flex, register the bundle in
`config/bundles.php` (both bundles) and write both files yourself.

Requirements: PHP >= 8.2 < 8.7, Symfony 7.4 / 8.x, Doctrine DBAL 3.8+ / 4, ORM 3.7+ with DoctrineBundle,
`nowo-tech/ui-kit-bundle` ^2.55, `nowo-tech/form-kit-bundle` ^2.23, `nowo-tech/transaction-doctrine` ^1.8.1.

## Private entity manager

The bundle keeps its data on its own entity manager so it never flushes the host's unit of work. DoctrineBundle needs
the multi-entity-manager form:

```yaml
# config/packages/doctrine.yaml (move a single-form "orm:" block under entity_managers.default)
doctrine:
    orm:
        default_entity_manager: default
        entity_managers:
            default:
                connection: default
                auto_mapping: true
            nowo_crm_kit:
                connection: default
```

The bundle prepends its XML mapping (`NowoCrmKitBundle`, prefix `Nowo\CrmKitBundle\Domain\Model`) to that manager. When
`nowo_crm_kit.doctrine.entity_manager` names a manager that is not declared, the container build fails with the fix.

A host that must keep a single entity manager sets `nowo_crm_kit.doctrine.entity_manager: null`: the bundle then uses
the default manager in transactions it opens, flushes and commits itself; a refused write rolls back and detaches only
the bundle's entities, so the manager stays open and the host's entities stay managed. It refuses to write while the
host has pending changes of its own.

Call the CRM's write services outside your own transactions: a write inside an open transaction on the same
connection is refused (`LogicException`), because the CRM releases its events and audit entries after its own commit.
With Messenger, dispatch CRM writes from a bus without the `doctrine_transaction` middleware.

## Transaction bundle (required)

Register `Nowo\TransactionDoctrine\Symfony\NowoTransactionDoctrineBundle` (from `nowo-tech/transaction-doctrine`,
already required):

```php
// config/bundles.php
return [
    // ...
    Nowo\TransactionDoctrine\Symfony\NowoTransactionDoctrineBundle::class => ['all' => true],
    Nowo\CrmKitBundle\NowoCrmKitBundle::class => ['all' => true],
];
```

Its write unit replays deadlocks, its commit ledger and request watermark tell a conflict after a commit earlier in
the same request (`CONFLICT_002`), and its responder answers lock conflicts: 409 with `CONFLICT_001` + `Retry-After` +
`X-Conflict-Code`, or `CONFLICT_002` without `Retry-After`. Without the bundle the container build fails with this fix.
When you turn its `conflict_response` off, a fallback listener on the CRM's routes keeps the same status, codes and
headers ([CONCURRENCY.md](CONCURRENCY.md)).

**A replay clears your entity manager.** When a CRM write unit is replayed after a deadlock, a lock-wait timeout or a
serialization failure, transaction-doctrine (1.6) clears **every** open entity manager before the next attempt,
your default one included (private and shared mode alike). Entities of your own that you loaded before calling a CRM
write are detached afterwards: re-read them after the call, or call the CRM before you load them. A refused write
without a replay (stale version, limit, invalid input) leaves your manager as it was.

## Routes

The pages are opt-in. Turn them on and import the route loader with your prefix:

```yaml
# config/packages/nowo_crm_kit.yaml
nowo_crm_kit:
    ui:
        enabled: true
        path: /crm            # default
```

```yaml
# config/routes/nowo_crm_kit.yaml
nowo_crm_kit:
    resource: .
    type: nowo_crm_kit_ui
    prefix: /admin
```

The pages need SecurityBundle (the build fails without it unless `security.allow_unauthenticated: true`, demos only).
Set who may use them (REQ-UI-002, [CONFIGURATION.md](CONFIGURATION.md#security-req-ui-002)) and lock the path in your
firewall too:

```yaml
# config/packages/nowo_crm_kit.yaml
nowo_crm_kit:
    security:
        access_roles: [ROLE_USER]      # default [ROLE_ADMIN]
        manage_roles: [ROLE_USER]
        settings_roles: [ROLE_ADMIN]
        export_roles: [ROLE_ADMIN]
        erase_roles: [ROLE_ADMIN]

# config/packages/security.yaml
security:
    access_control:
        - { path: ^/admin/crm, roles: IS_AUTHENTICATED_FULLY }
```

The routes are then `/admin/crm`, `/admin/crm/companies`, `/admin/crm/contacts/{id}`, … (names `nowo_crm_kit_*`,
listed in [USAGE.md](USAGE.md#pages-and-routes)). Protect the prefix with your firewall: the bundle also checks every
call itself, but it needs a signed-in actor.

The pages use UiKit's page templates and FormKit forms under the `crm_kit` profile, which the bundle prepends when
FormKit is registered. For right-click / long-press menus on row actions set `nowo_ui_kit.theme.context_menus: true`,
and for the focus-mode toggle `nowo_ui_kit.theme.focus_mode.enabled: true`, in the host's UiKit configuration.

0.4.0: the lists open the import in a modal (the page action **Import**). Its script is a bundle asset: run
`php bin/console assets:install` after installing or updating (it lands in `public/bundles/nowocrmkit/js/` and is served
through the `nowo_crm_kit` asset package the bundle registers). Without the script the action is a link to the import
page. The package needs `framework.assets` enabled (the default once `symfony/asset` is installed; a host that sets
`framework.assets.enabled: false` has no `asset()` function and the lists fail to render).

## Schema and migrations

No migration classes are shipped: the tables carry your prefix and live on your connection. Generate them on the
bundle's entity manager:

```bash
php bin/console doctrine:migrations:diff --em=nowo_crm_kit
php bin/console doctrine:migrations:migrate --em=nowo_crm_kit
# or, in development: php bin/console doctrine:schema:update --force --em=nowo_crm_kit
```

The metadata listener prefixes the table, index and unique-constraint names (`doctrine.table_prefix`, default
`nowo_`) and maps every `id` / `*_id` column and the key columns (`email_key`, `name_key`, `tax_id_key`, `domain_key`,
`sort_key`, `field_key`, `purpose`, `purpose_keys`, `role_key`, `host_type`, `claim_token`, `resource`) with a **binary NO PAD collation** on MySQL
and MariaDB, so `diff` and `schema:update` keep it:

| Platform | Collation of those columns | Effect |
| --- | --- | --- |
| MySQL 8.0+ | `utf8mb4_0900_bin` | Byte comparison, NO PAD: `usera` ≠ `UserA`, `u1 ` ≠ `u1` |
| MariaDB 10.6+ | `utf8mb4_nopad_bin` | The same |
| PostgreSQL, SQLite | the default (exact already) | No collation is declared |

Minimum database versions (0.2.0): MySQL 8.0, MariaDB 10.6, PostgreSQL 9.5 (the purge claim is read with
`FOR UPDATE SKIP LOCKED`; with Doctrine DBAL 4, PostgreSQL 10, its lowest platform); SQLite for development and
tests.

The collation is chosen from the platform when the metadata loads, so the platform must be known at cache warm-up: set
`doctrine.dbal.server_version` (for example `8.4`, `11.4.2-MariaDB` or `16`), or keep the database reachable. The
bundle fails with that hint otherwise.

Every read keyed by such a value is also re-checked with `===` in PHP (REQ-SEC-006 rule 2).

## Scheduled commands

| Command | When | What |
| --- | --- | --- |
| `nowo:crm-kit:purge` | daily | Retention: trashed records after `trash_days`, timeline entries after `timeline_years`, import rows after `import_keep_days`, merge snapshots after `merge_keep_days` (per tenant settings). `--tenant=<id>`, `--dry-run` (counts trashed records only) |
| `nowo:crm-kit:import:resume` | every few minutes | Takes over running imports whose claim is older than `import.stale_claim_seconds` and finishes them |
| `nowo:crm-kit:counters:rebuild` | after manual SQL | Recounts the plan-limit counters of every tenant |

## Optional packages

- `nowo-tech/search-kit-bundle` ^1.9 (PHP >= 8.3): header search of companies, contacts and deals; nothing to wire
  beyond `ui.enabled` ([USAGE.md](USAGE.md#searchkit)).
- `symfony/security-bundle`: the default access checker and actor directory read the security token.

## Reference DDL

What `doctrine:migrations:diff --em=nowo_crm_kit` produces for phase A with the default prefix `nowo_` (the
authoritative source is the XML mapping in `src/Resources/config/doctrine`). Phase A creates 15 tables; 0.2.0 adds
`crm_role_assignment` and two columns of `crm_affiliation` (shown below; the upgrade DDL is in
[UPGRADING.md](UPGRADING.md#to-020)).

**MariaDB 11.4** is the MySQL DDL with `utf8mb4_nopad_bin` in place of `utf8mb4_0900_bin` and `LONGTEXT … CHECK
(json_valid(…))` in place of `JSON` (MariaDB's JSON alias).

<details>
<summary>MySQL 8.4</summary>

```sql
CREATE TABLE nowo_crm_settings (
    tenant_id VARCHAR(128) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    trash_days INT NOT NULL,
    timeline_years INT NOT NULL,
    import_keep_days INT NOT NULL,
    merge_keep_days INT NOT NULL,
    version INT NOT NULL,
    updated_by_id VARCHAR(128) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_bin`,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (tenant_id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

CREATE TABLE nowo_crm_company (
    id VARCHAR(36) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    tenant_id VARCHAR(128) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    email VARCHAR(254) DEFAULT NULL,
    phone VARCHAR(40) DEFAULT NULL,
    street VARCHAR(255) DEFAULT NULL,
    postal_code VARCHAR(20) DEFAULT NULL,
    city VARCHAR(120) DEFAULT NULL,
    region VARCHAR(120) DEFAULT NULL,
    country VARCHAR(2) DEFAULT NULL,
    owner_id VARCHAR(128) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_bin`,
    custom JSON NOT NULL,
    status VARCHAR(16) NOT NULL,
    merged_into_id VARCHAR(36) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_bin`,
    trashed_at DATETIME DEFAULT NULL,
    version INT NOT NULL,
    created_at DATETIME NOT NULL,
    created_by_id VARCHAR(128) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_bin`,
    updated_at DATETIME(6) NOT NULL,
    updated_by_id VARCHAR(128) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_bin`,
    name VARCHAR(200) NOT NULL,
    name_key VARCHAR(200) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    legal_name VARCHAR(200) DEFAULT NULL,
    tax_id VARCHAR(64) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_bin`,
    tax_id_key VARCHAR(64) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_bin`,
    website VARCHAR(255) DEFAULT NULL,
    domain_key VARCHAR(190) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_bin`,
    industry VARCHAR(80) DEFAULT NULL,
    description LONGTEXT DEFAULT NULL,
    INDEX nowo_crm_company_name (tenant_id, status, name_key),
    INDEX nowo_crm_company_domain (tenant_id, domain_key),
    INDEX nowo_crm_company_tax (tenant_id, tax_id_key),
    INDEX nowo_crm_company_owner (tenant_id, owner_id),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

CREATE TABLE nowo_crm_contact (
    id VARCHAR(36) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    tenant_id VARCHAR(128) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    email VARCHAR(254) DEFAULT NULL,
    phone VARCHAR(40) DEFAULT NULL,
    street VARCHAR(255) DEFAULT NULL,
    postal_code VARCHAR(20) DEFAULT NULL,
    city VARCHAR(120) DEFAULT NULL,
    region VARCHAR(120) DEFAULT NULL,
    country VARCHAR(2) DEFAULT NULL,
    owner_id VARCHAR(128) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_bin`,
    custom JSON NOT NULL,
    status VARCHAR(16) NOT NULL,
    merged_into_id VARCHAR(36) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_bin`,
    trashed_at DATETIME DEFAULT NULL,
    version INT NOT NULL,
    created_at DATETIME NOT NULL,
    created_by_id VARCHAR(128) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_bin`,
    updated_at DATETIME(6) NOT NULL,
    updated_by_id VARCHAR(128) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_bin`,
    first_name VARCHAR(120) DEFAULT NULL,
    last_name VARCHAR(120) DEFAULT NULL,
    display_name VARCHAR(241) NOT NULL,
    sort_key VARCHAR(241) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    email_key VARCHAR(254) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_bin`,
    mobile VARCHAR(40) DEFAULT NULL,
    job_title VARCHAR(120) DEFAULT NULL,
    erased_at DATETIME DEFAULT NULL,
    UNIQUE INDEX nowo_crm_contact_email (tenant_id, email_key),
    INDEX nowo_crm_contact_sort (tenant_id, status, sort_key),
    INDEX nowo_crm_contact_owner (tenant_id, owner_id),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

CREATE TABLE nowo_crm_affiliation (
    id VARCHAR(36) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    tenant_id VARCHAR(128) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    contact_id VARCHAR(36) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    company_id VARCHAR(36) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    role VARCHAR(120) DEFAULT NULL,
    is_primary TINYINT(1) NOT NULL,
    created_at DATETIME NOT NULL,
    is_primary_contact TINYINT(1) DEFAULT 0 NOT NULL,
    purpose_keys VARCHAR(1100) CHARACTER SET utf8mb4 DEFAULT '' NOT NULL COLLATE `utf8mb4_0900_bin`,
    UNIQUE INDEX nowo_crm_affiliation_pair (contact_id, company_id),
    INDEX nowo_crm_affiliation_company (tenant_id, company_id),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

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

CREATE TABLE nowo_crm_tag (
    id VARCHAR(36) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    tenant_id VARCHAR(128) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    name VARCHAR(60) NOT NULL,
    name_key VARCHAR(60) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    colour VARCHAR(16) NOT NULL,
    version INT NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE INDEX nowo_crm_tag_name (tenant_id, name_key),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

CREATE TABLE nowo_crm_tagging (
    id VARCHAR(36) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    tenant_id VARCHAR(128) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    tag_id VARCHAR(36) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    subject_type VARCHAR(16) NOT NULL,
    subject_id VARCHAR(36) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    created_at DATETIME NOT NULL,
    UNIQUE INDEX nowo_crm_tagging_pair (tag_id, subject_type, subject_id),
    INDEX nowo_crm_tagging_subject (tenant_id, subject_type, subject_id),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

CREATE TABLE nowo_crm_field (
    id VARCHAR(36) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    tenant_id VARCHAR(128) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    subject_type VARCHAR(16) NOT NULL,
    field_key VARCHAR(40) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    label VARCHAR(80) NOT NULL,
    type VARCHAR(16) NOT NULL,
    choices JSON NOT NULL,
    required TINYINT(1) NOT NULL,
    position INT NOT NULL,
    archived TINYINT(1) NOT NULL,
    version INT NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE INDEX nowo_crm_field_key (tenant_id, subject_type, field_key),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

CREATE TABLE nowo_crm_note (
    id VARCHAR(36) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    tenant_id VARCHAR(128) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    subject_type VARCHAR(16) NOT NULL,
    subject_id VARCHAR(36) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    body LONGTEXT NOT NULL,
    pinned TINYINT(1) NOT NULL,
    author_id VARCHAR(128) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_bin`,
    version INT NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    INDEX nowo_crm_note_subject (tenant_id, subject_type, subject_id, created_at),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

CREATE TABLE nowo_crm_activity (
    id VARCHAR(36) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    tenant_id VARCHAR(128) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    subject_type VARCHAR(16) NOT NULL,
    subject_id VARCHAR(36) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    kind VARCHAR(24) NOT NULL,
    actor_id VARCHAR(128) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_bin`,
    params JSON NOT NULL,
    occurred_at DATETIME NOT NULL,
    INDEX nowo_crm_activity_subject (tenant_id, subject_type, subject_id, occurred_at),
    INDEX nowo_crm_activity_time (tenant_id, occurred_at),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

CREATE TABLE nowo_crm_consent (
    id VARCHAR(36) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    tenant_id VARCHAR(128) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    contact_id VARCHAR(36) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    purpose VARCHAR(64) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    granted TINYINT(1) NOT NULL,
    source VARCHAR(16) NOT NULL,
    evidence VARCHAR(255) DEFAULT NULL,
    recorded_by_id VARCHAR(128) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_bin`,
    recorded_at DATETIME NOT NULL,
    INDEX nowo_crm_consent_contact (tenant_id, contact_id, purpose, recorded_at),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

CREATE TABLE nowo_crm_host_link (
    id VARCHAR(36) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    tenant_id VARCHAR(128) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    subject_type VARCHAR(16) NOT NULL,
    subject_id VARCHAR(36) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    host_type VARCHAR(64) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    host_id VARCHAR(128) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    label VARCHAR(200) DEFAULT NULL,
    created_by_id VARCHAR(128) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_bin`,
    created_at DATETIME NOT NULL,
    UNIQUE INDEX nowo_crm_host_link_key (tenant_id, subject_type, subject_id, host_type, host_id),
    INDEX nowo_crm_host_link_host (tenant_id, host_type, host_id),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

CREATE TABLE nowo_crm_import (
    id VARCHAR(36) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    tenant_id VARCHAR(128) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    subject_type VARCHAR(16) NOT NULL,
    format VARCHAR(16) NOT NULL,
    status VARCHAR(16) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    duplicate_policy VARCHAR(16) NOT NULL,
    columns JSON NOT NULL,
    mapping JSON NOT NULL,
    rows_total INT NOT NULL,
    rows_done INT NOT NULL,
    created_count INT NOT NULL,
    updated_count INT NOT NULL,
    duplicate_count INT NOT NULL,
    invalid_count INT NOT NULL,
    limit_count INT NOT NULL,
    claim_token VARCHAR(36) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_bin`,
    claimed_at DATETIME DEFAULT NULL,
    created_by_id VARCHAR(128) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_bin`,
    created_at DATETIME NOT NULL,
    finished_at DATETIME DEFAULT NULL,
    version INT NOT NULL,
    INDEX nowo_crm_import_tenant (tenant_id, created_at),
    INDEX nowo_crm_import_claim (status, claimed_at),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

CREATE TABLE nowo_crm_import_row (
    id VARCHAR(36) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    import_id VARCHAR(36) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    tenant_id VARCHAR(128) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    row_no INT NOT NULL,
    data JSON NOT NULL,
    outcome VARCHAR(16) NOT NULL,
    record_id VARCHAR(36) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_bin`,
    message VARCHAR(80) DEFAULT NULL,
    UNIQUE INDEX nowo_crm_import_row_no (import_id, row_no),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

CREATE TABLE nowo_crm_merge (
    id VARCHAR(36) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    tenant_id VARCHAR(128) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    subject_type VARCHAR(16) NOT NULL,
    survivor_id VARCHAR(36) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    merged_id VARCHAR(36) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    snapshot JSON NOT NULL,
    taken JSON NOT NULL,
    actor_id VARCHAR(128) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_bin`,
    merged_at DATETIME NOT NULL,
    INDEX nowo_crm_merge_survivor (tenant_id, survivor_id),
    INDEX nowo_crm_merge_merged (tenant_id, merged_id),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

CREATE TABLE nowo_crm_counter (
    tenant_id VARCHAR(128) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    resource VARCHAR(32) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_bin`,
    value INT NOT NULL,
    PRIMARY KEY (tenant_id, resource)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
```

</details>

<details>
<summary>PostgreSQL 16</summary>

```sql
CREATE TABLE nowo_crm_settings (
    tenant_id VARCHAR(128) NOT NULL,
    trash_days INT NOT NULL,
    timeline_years INT NOT NULL,
    import_keep_days INT NOT NULL,
    merge_keep_days INT NOT NULL,
    version INT NOT NULL,
    updated_by_id VARCHAR(128) DEFAULT NULL,
    updated_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL,
    PRIMARY KEY (tenant_id)
);

CREATE TABLE nowo_crm_company (
    id VARCHAR(36) NOT NULL,
    tenant_id VARCHAR(128) NOT NULL,
    email VARCHAR(254) DEFAULT NULL,
    phone VARCHAR(40) DEFAULT NULL,
    street VARCHAR(255) DEFAULT NULL,
    postal_code VARCHAR(20) DEFAULT NULL,
    city VARCHAR(120) DEFAULT NULL,
    region VARCHAR(120) DEFAULT NULL,
    country VARCHAR(2) DEFAULT NULL,
    owner_id VARCHAR(128) DEFAULT NULL,
    custom JSON NOT NULL,
    status VARCHAR(16) NOT NULL,
    merged_into_id VARCHAR(36) DEFAULT NULL,
    trashed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    version INT NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    created_by_id VARCHAR(128) DEFAULT NULL,
    updated_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL,
    updated_by_id VARCHAR(128) DEFAULT NULL,
    name VARCHAR(200) NOT NULL,
    name_key VARCHAR(200) NOT NULL,
    legal_name VARCHAR(200) DEFAULT NULL,
    tax_id VARCHAR(64) DEFAULT NULL,
    tax_id_key VARCHAR(64) DEFAULT NULL,
    website VARCHAR(255) DEFAULT NULL,
    domain_key VARCHAR(190) DEFAULT NULL,
    industry VARCHAR(80) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    PRIMARY KEY (id)
);
CREATE INDEX nowo_crm_company_name ON nowo_crm_company (tenant_id, status, name_key);
CREATE INDEX nowo_crm_company_domain ON nowo_crm_company (tenant_id, domain_key);
CREATE INDEX nowo_crm_company_tax ON nowo_crm_company (tenant_id, tax_id_key);
CREATE INDEX nowo_crm_company_owner ON nowo_crm_company (tenant_id, owner_id);

CREATE TABLE nowo_crm_contact (
    id VARCHAR(36) NOT NULL,
    tenant_id VARCHAR(128) NOT NULL,
    email VARCHAR(254) DEFAULT NULL,
    phone VARCHAR(40) DEFAULT NULL,
    street VARCHAR(255) DEFAULT NULL,
    postal_code VARCHAR(20) DEFAULT NULL,
    city VARCHAR(120) DEFAULT NULL,
    region VARCHAR(120) DEFAULT NULL,
    country VARCHAR(2) DEFAULT NULL,
    owner_id VARCHAR(128) DEFAULT NULL,
    custom JSON NOT NULL,
    status VARCHAR(16) NOT NULL,
    merged_into_id VARCHAR(36) DEFAULT NULL,
    trashed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    version INT NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    created_by_id VARCHAR(128) DEFAULT NULL,
    updated_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL,
    updated_by_id VARCHAR(128) DEFAULT NULL,
    first_name VARCHAR(120) DEFAULT NULL,
    last_name VARCHAR(120) DEFAULT NULL,
    display_name VARCHAR(241) NOT NULL,
    sort_key VARCHAR(241) NOT NULL,
    email_key VARCHAR(254) DEFAULT NULL,
    mobile VARCHAR(40) DEFAULT NULL,
    job_title VARCHAR(120) DEFAULT NULL,
    erased_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    PRIMARY KEY (id)
);
CREATE UNIQUE INDEX nowo_crm_contact_email ON nowo_crm_contact (tenant_id, email_key);
CREATE INDEX nowo_crm_contact_sort ON nowo_crm_contact (tenant_id, status, sort_key);
CREATE INDEX nowo_crm_contact_owner ON nowo_crm_contact (tenant_id, owner_id);

CREATE TABLE nowo_crm_affiliation (
    id VARCHAR(36) NOT NULL,
    tenant_id VARCHAR(128) NOT NULL,
    contact_id VARCHAR(36) NOT NULL,
    company_id VARCHAR(36) NOT NULL,
    role VARCHAR(120) DEFAULT NULL,
    is_primary BOOLEAN NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    is_primary_contact BOOLEAN DEFAULT false NOT NULL,
    purpose_keys VARCHAR(1100) DEFAULT '' NOT NULL,
    PRIMARY KEY (id)
);
CREATE UNIQUE INDEX nowo_crm_affiliation_pair ON nowo_crm_affiliation (contact_id, company_id);
CREATE INDEX nowo_crm_affiliation_company ON nowo_crm_affiliation (tenant_id, company_id);

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

CREATE TABLE nowo_crm_tag (
    id VARCHAR(36) NOT NULL,
    tenant_id VARCHAR(128) NOT NULL,
    name VARCHAR(60) NOT NULL,
    name_key VARCHAR(60) NOT NULL,
    colour VARCHAR(16) NOT NULL,
    version INT NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    PRIMARY KEY (id)
);
CREATE UNIQUE INDEX nowo_crm_tag_name ON nowo_crm_tag (tenant_id, name_key);

CREATE TABLE nowo_crm_tagging (
    id VARCHAR(36) NOT NULL,
    tenant_id VARCHAR(128) NOT NULL,
    tag_id VARCHAR(36) NOT NULL,
    subject_type VARCHAR(16) NOT NULL,
    subject_id VARCHAR(36) NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    PRIMARY KEY (id)
);
CREATE UNIQUE INDEX nowo_crm_tagging_pair ON nowo_crm_tagging (tag_id, subject_type, subject_id);
CREATE INDEX nowo_crm_tagging_subject ON nowo_crm_tagging (tenant_id, subject_type, subject_id);

CREATE TABLE nowo_crm_field (
    id VARCHAR(36) NOT NULL,
    tenant_id VARCHAR(128) NOT NULL,
    subject_type VARCHAR(16) NOT NULL,
    field_key VARCHAR(40) NOT NULL,
    label VARCHAR(80) NOT NULL,
    type VARCHAR(16) NOT NULL,
    choices JSON NOT NULL,
    required BOOLEAN NOT NULL,
    position INT NOT NULL,
    archived BOOLEAN NOT NULL,
    version INT NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    PRIMARY KEY (id)
);
CREATE UNIQUE INDEX nowo_crm_field_key ON nowo_crm_field (tenant_id, subject_type, field_key);

CREATE TABLE nowo_crm_note (
    id VARCHAR(36) NOT NULL,
    tenant_id VARCHAR(128) NOT NULL,
    subject_type VARCHAR(16) NOT NULL,
    subject_id VARCHAR(36) NOT NULL,
    body TEXT NOT NULL,
    pinned BOOLEAN NOT NULL,
    author_id VARCHAR(128) DEFAULT NULL,
    version INT NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    updated_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL,
    PRIMARY KEY (id)
);
CREATE INDEX nowo_crm_note_subject ON nowo_crm_note (tenant_id, subject_type, subject_id, created_at);

CREATE TABLE nowo_crm_activity (
    id VARCHAR(36) NOT NULL,
    tenant_id VARCHAR(128) NOT NULL,
    subject_type VARCHAR(16) NOT NULL,
    subject_id VARCHAR(36) NOT NULL,
    kind VARCHAR(24) NOT NULL,
    actor_id VARCHAR(128) DEFAULT NULL,
    params JSON NOT NULL,
    occurred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    PRIMARY KEY (id)
);
CREATE INDEX nowo_crm_activity_subject ON nowo_crm_activity (tenant_id, subject_type, subject_id, occurred_at);
CREATE INDEX nowo_crm_activity_time ON nowo_crm_activity (tenant_id, occurred_at);

CREATE TABLE nowo_crm_consent (
    id VARCHAR(36) NOT NULL,
    tenant_id VARCHAR(128) NOT NULL,
    contact_id VARCHAR(36) NOT NULL,
    purpose VARCHAR(64) NOT NULL,
    granted BOOLEAN NOT NULL,
    source VARCHAR(16) NOT NULL,
    evidence VARCHAR(255) DEFAULT NULL,
    recorded_by_id VARCHAR(128) DEFAULT NULL,
    recorded_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    PRIMARY KEY (id)
);
CREATE INDEX nowo_crm_consent_contact ON nowo_crm_consent (tenant_id, contact_id, purpose, recorded_at);

CREATE TABLE nowo_crm_host_link (
    id VARCHAR(36) NOT NULL,
    tenant_id VARCHAR(128) NOT NULL,
    subject_type VARCHAR(16) NOT NULL,
    subject_id VARCHAR(36) NOT NULL,
    host_type VARCHAR(64) NOT NULL,
    host_id VARCHAR(128) NOT NULL,
    label VARCHAR(200) DEFAULT NULL,
    created_by_id VARCHAR(128) DEFAULT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    PRIMARY KEY (id)
);
CREATE UNIQUE INDEX nowo_crm_host_link_key ON nowo_crm_host_link (tenant_id, subject_type, subject_id, host_type, host_id);
CREATE INDEX nowo_crm_host_link_host ON nowo_crm_host_link (tenant_id, host_type, host_id);

CREATE TABLE nowo_crm_import (
    id VARCHAR(36) NOT NULL,
    tenant_id VARCHAR(128) NOT NULL,
    subject_type VARCHAR(16) NOT NULL,
    format VARCHAR(16) NOT NULL,
    status VARCHAR(16) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    duplicate_policy VARCHAR(16) NOT NULL,
    columns JSON NOT NULL,
    mapping JSON NOT NULL,
    rows_total INT NOT NULL,
    rows_done INT NOT NULL,
    created_count INT NOT NULL,
    updated_count INT NOT NULL,
    duplicate_count INT NOT NULL,
    invalid_count INT NOT NULL,
    limit_count INT NOT NULL,
    claim_token VARCHAR(36) DEFAULT NULL,
    claimed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    created_by_id VARCHAR(128) DEFAULT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    version INT NOT NULL,
    PRIMARY KEY (id)
);
CREATE INDEX nowo_crm_import_tenant ON nowo_crm_import (tenant_id, created_at);
CREATE INDEX nowo_crm_import_claim ON nowo_crm_import (status, claimed_at);

CREATE TABLE nowo_crm_import_row (
    id VARCHAR(36) NOT NULL,
    import_id VARCHAR(36) NOT NULL,
    tenant_id VARCHAR(128) NOT NULL,
    row_no INT NOT NULL,
    data JSON NOT NULL,
    outcome VARCHAR(16) NOT NULL,
    record_id VARCHAR(36) DEFAULT NULL,
    message VARCHAR(80) DEFAULT NULL,
    PRIMARY KEY (id)
);
CREATE UNIQUE INDEX nowo_crm_import_row_no ON nowo_crm_import_row (import_id, row_no);

CREATE TABLE nowo_crm_merge (
    id VARCHAR(36) NOT NULL,
    tenant_id VARCHAR(128) NOT NULL,
    subject_type VARCHAR(16) NOT NULL,
    survivor_id VARCHAR(36) NOT NULL,
    merged_id VARCHAR(36) NOT NULL,
    snapshot JSON NOT NULL,
    taken JSON NOT NULL,
    actor_id VARCHAR(128) DEFAULT NULL,
    merged_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    PRIMARY KEY (id)
);
CREATE INDEX nowo_crm_merge_survivor ON nowo_crm_merge (tenant_id, survivor_id);
CREATE INDEX nowo_crm_merge_merged ON nowo_crm_merge (tenant_id, merged_id);

CREATE TABLE nowo_crm_counter (
    tenant_id VARCHAR(128) NOT NULL,
    resource VARCHAR(32) NOT NULL,
    value INT NOT NULL,
    PRIMARY KEY (tenant_id, resource)
);
```

</details>
