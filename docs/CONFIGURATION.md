# Configuration

## Table of contents

- [Full reference](#full-reference)
- [Ports](#ports)
- [Security (REQ-UI-002)](#security-req-ui-002)
- [Feature gate and plan limits](#feature-gate-and-plan-limits)
- [Doctrine](#doctrine)
- [Fields, import and GDPR](#fields-import-and-gdpr)
- [Roles and affiliation purposes](#roles-and-affiliation-purposes)
- [Search](#search)
- [UI](#ui)
- [Later phases](#later-phases)
- [Per-tenant settings](#per-tenant-settings)
- [Container parameters](#container-parameters)

The tree is strict (REQ-SF-006): an unknown key fails the container build and names its path. Source:
`src/Infrastructure/Symfony/DependencyInjection/ConfigurationTree.php`.

## Full reference

Defaults shown:

```yaml
nowo_crm_kit:
    tenant_resolver: null          # service id of CrmTenantResolverInterface; null = single tenant "default"
    access_checker: null           # DEPRECATED (0.2.0, removed in 0.3.0): a 0.1.0 CrmAccessCheckerInterface service id
    feature_gate: null             # service id of CrmFeatureGateInterface; null = ConfiguredFeatureGate
    plan_limits: null              # service id of CrmPlanLimitsInterface; null = ConfiguredPlanLimits
    actor_directory: null          # service id of CrmActorDirectoryInterface; null = the signed-in user only
    audit_sink: null               # service id of CrmAuditSinkInterface; null = one PSR log line per entry
    clock: null                    # service id of Psr\Clock\ClockInterface; null = FrameworkBundle's `clock`
    security:                      # REQ-UI-002 (0.2.0)
        access_roles: [ROLE_ADMIN]     # open the CRM (lists, 360° pages, search)
        access_checker: null           # service id of CrmKitAccessCheckerInterface; null = ConfigurableCrmKitAccessChecker
        allow_unauthenticated: false   # DEV/DEMO only
        manage_roles: [ROLE_ADMIN]     # create, edit, trash / restore, import
        settings_roles: [ROLE_ADMIN]   # tags, fields, settings, purge; every record with resource_scoping
        export_roles: [ROLE_ADMIN]     # CSV export of lists, GDPR export
        erase_roles: [ROLE_ADMIN]      # GDPR erasure
        amounts_roles: [ROLE_ADMIN]    # amounts and totals (phase B on)
        campaigns_roles: [ROLE_ADMIN]  # sending circulars (phase E2 on)
        resource_scoping: false        # true: records open to their owner (or ownerless) and settings_roles by default
    feature:
        default_enabled: true      # ConfiguredFeatureGate: the CRM is on for every tenant
    limits:                        # ConfiguredPlanLimits: maximum active records per tenant (>= 0); null = unlimited
        companies: null
        contacts: null
    doctrine:
        entity_manager: nowo_crm_kit   # the private entity manager (the Flex recipe declares it); null = the default one
        table_prefix: nowo_            # 1-16 characters of a-z, 0-9 or "_", starting with a letter
    fields:
        max_per_subject: 50        # 1..200 custom field definitions per type (archived ones count)
    import:
        max_rows: 5000             # 1..50000 rows per file
        max_bytes: 5242880         # 1 KiB..50 MiB per file
        sync_rows: 500             # 10..50000 rows run in the confirm request (then "Continue")
        chunk_rows: 100            # 10..1000 rows per transaction
        stale_claim_seconds: 600   # 60..86400: a running import without progress for this long may be taken over
    gdpr:
        purposes: [marketing_email, newsletter, tracking]   # distinct keys ^[a-z][a-z0-9_.]{0,63}$
    roles:
        catalogue: [customer, supplier, partner]   # party roles; ≤ 32 distinct keys ^[a-z][a-z0-9_]{0,31}$
    affiliations:
        purposes: [billing, orders, technical, general]   # what a contact is a company's contact for; same rule
    search:
        enabled: true              # SearchKit providers when SearchKit is installed and the UI is on
    ui:
        enabled: false             # routes are opt-in (REQ-UI-001)
        path: /crm                 # starts with "/"; base path of the routes (under the import prefix)
        page_size: 25              # 10..100, default rows per list page
        layout: null               # Twig layout of the pages; null = UiKit's admin layout
    pipeline:  { enabled: false }  # phase B, funnels (0.3.0)
    quotes:    { enabled: false }  # phase C, quotes (0.5.0)
    invoices:  { enabled: false }  # phase D, invoices (0.6.0)
    portal:    { enabled: false }  # phase F, customer portal (0.7.0)
    campaigns: { enabled: false }  # phase E2, informative circulars (0.10.0)
    reports:   { enabled: false }  # phase G, reports (later)
```

## Ports

Each port key takes the **service id** of the host's implementation; `null` keeps the bundle default. No bundle
service ever needs to be overridden. What each port does and how to implement it: [USAGE.md](USAGE.md#ports-to-implement).

| Key | Interface | Default |
| --- | --- | --- |
| `tenant_resolver` | `Domain\Port\CrmTenantResolverInterface` | `SingleTenantResolver`: tenant `default` |
| `access_checker` (deprecated) | `Domain\Port\CrmAccessCheckerInterface` | `SecurityCrmAccessChecker` (the `security` node below); a 0.1.0 host checker set here is still used, with `create` / `delete` / `import` asked as `edit` and `export` / `erase` as `manage` |
| `feature_gate` | `Domain\Port\CrmFeatureGateInterface` | `ConfiguredFeatureGate`: `feature.default_enabled` |
| `plan_limits` | `Domain\Port\CrmPlanLimitsInterface` | `ConfiguredPlanLimits`: `limits.*` |
| `actor_directory` | `Domain\Port\CrmActorDirectoryInterface` | `SecurityActorDirectory`: the signed-in user only |
| `audit_sink` | `Domain\Port\CrmAuditSinkInterface` | `PsrLogAuditSink`: one `info` line per entry |
| `clock` | `Psr\Clock\ClockInterface` | FrameworkBundle's `clock` service |

Panel providers (`CrmPanelProviderInterface`) and host object resolvers (`CrmHostObjectResolverInterface`) are not
configured: any service implementing them is autoconfigured with its tag (`nowo_crm_kit.panel`,
`nowo_crm_kit.host_object`).

## Security (REQ-UI-002)

The house access shape of the Nowo admin UIs. Two layers: the host's firewall (`access_control` on the CRM's path) and
the bundle's checkers.

| Key | Default | What |
| --- | --- | --- |
| `security.access_roles` | `[ROLE_ADMIN]` | Opening the CRM: lists, 360° pages, search (`canAccess`); every other area also needs it |
| `security.manage_roles` | `[ROLE_ADMIN]` | Create, edit, trash / restore, import (`canCreate`, `canEdit`, `canDelete`, `canImport`) |
| `security.settings_roles` | `[ROLE_ADMIN]` | Tags, custom fields, settings, purge from the trash, others' notes (`canManageSettings`); with `resource_scoping`, every record |
| `security.export_roles` | `[ROLE_ADMIN]` | CSV export of lists, GDPR export (`canExport`) |
| `security.erase_roles` | `[ROLE_ADMIN]` | GDPR erasure (`canErase`) |
| `security.amounts_roles` | `[ROLE_ADMIN]` | Deal amounts, quote and invoice totals, revenue figures, from phase B on (`canViewAmounts`) |
| `security.campaigns_roles` | `[ROLE_ADMIN]` | Sending informative circulars, from phase E2 on (`canSendCampaigns`) |
| `security.access_checker` | `null` | Service id of your `CrmKitAccessCheckerInterface` (or `TenantAwareCrmKitAccessCheckerInterface`); null = `ConfigurableCrmKitAccessChecker` on the lists above |
| `security.allow_unauthenticated` | `false` | **DEV/DEMO only, refused in `prod`**: the pages without SecurityBundle or without signing in; the actor is then the shared id `anonymous` (a real user with that identifier would be the same actor), resource scoping is off and, without SecurityBundle, every area is open |
| `security.resource_scoping` | `false` | `true`: lists and their totals, search, duplicates, CSV exports, a page's related records and record pages show only ownerless records, the person's own and the owners `nowo_crm_kit.record_visibility` listeners add (applied in SQL); whoever may manage the settings sees everything; `nowo_crm_kit.record_access_check` listeners decide each record |

A role list grants its area to **any** of its roles. **An empty list means any authenticated user** for that area
(`access_roles`, `manage_roles`, `amounts_roles`, `campaigns_roles`); `settings_roles`, `export_roles` and
`erase_roles` must name at least one role.
With `ui.enabled: true`, the container build fails without SecurityBundle unless `allow_unauthenticated: true`.
Decisions on one record go through the event `nowo_crm_kit.record_access_check`
([USAGE.md](USAGE.md#record-access-listeners)).

```yaml
nowo_crm_kit:
    security:
        access_roles: [ROLE_USER]
        manage_roles: [ROLE_SALES]
        settings_roles: [ROLE_ADMIN]
        export_roles: [ROLE_ADMIN]
        erase_roles: [ROLE_ADMIN]
        # access_checker: App\Crm\CrmAreaAccessChecker
```

Lock the path in the host's firewall too (the routes live under the import prefix and `ui.path`):

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/admin/crm, roles: IS_AUTHENTICATED_FULLY }
```

The 0.1.0 key `access.roles` is gone (see [UPGRADING.md](UPGRADING.md#to-020)); the root key `access_checker` still
works in 0.2.0 and is removed in 0.3.0.

## Feature gate and plan limits

- `feature.default_enabled: false` turns the CRM off for every tenant (every route and service answers 404) until a
  host gate says otherwise.
- `limits.companies` / `limits.contacts`: the maximum of active records per tenant. Creating or restoring past it
  answers 409 `crm_limit_reached`; an import row past it ends as `limit`.

## Doctrine

- `entity_manager: nowo_crm_kit` (default): the bundle's mapping is registered on that manager, which the host
  declares (the Flex recipe does). If it is not declared, the container build fails with the fix.
- `entity_manager: null`: the host's default entity manager, used only in explicit transactions; the bundle never
  flushes or clears the host's pending work (it refuses to write while the host has pending changes).
- `table_prefix`: prefix of every table, index and unique-constraint name (`nowo_crm_company`,
  `nowo_crm_contact_email`, …).

The bundle maps its reference columns with a binary collation chosen from the database platform, so the platform
must be known when the metadata loads: set `doctrine.dbal.server_version` (or keep the database reachable at cache
warm-up). See [INSTALLATION.md](INSTALLATION.md#schema-and-migrations).

## Fields, import and GDPR

- `fields.max_per_subject`: definitions per tenant and type (companies, contacts), archived ones included.
- `import.max_rows` / `import.max_bytes`: limits of one file; a larger file is refused at upload with a form error.
- `import.sync_rows` / `import.chunk_rows`: the confirm request runs `max(1, sync_rows / chunk_rows)` chunks; a larger
  import shows "Continue", which runs the same number again. Each chunk is one transaction.
- `nowo:crm-kit:import:resume` runs with nobody signed in, so it settles only rows that need no decision on one record
  and stops before the first update of an existing contact or the first named company; those wait for a person's
  "Continue" (their own access decides).
- `import.stale_claim_seconds`: when a running import has made no progress for this long, `nowo:crm-kit:import:resume`
  (run it every few minutes) takes it over, and anyone with `edit` may cancel it from its page.
- `gdpr.purposes`: the consent purposes the UI offers and the service accepts.

## Roles and affiliation purposes

Since 0.2.0. Both lists hold at most 32 distinct keys matching `^[a-z][a-z0-9_]{0,31}$` (anchored with `/D`: no
trailing newline); anything else fails the container build. Keys are compared byte for byte, never case folded.

- `roles.catalogue`: the party roles a company or contact may hold, several at once. Built in: `customer`,
  `supplier`, `partner`; `distributor` has translations but is opt-in (add it to the list). An empty list switches
  roles off (no form field, no filter). Prospect and former customer are lifecycle stages of the customer role
  (funnels, 0.3.0), and a competitor is a tag, so none of them is built in.
- `affiliations.purposes`: what a contact is a company's contact for (several per affiliation). Built in: `billing`,
  `orders`, `technical`, `general`.
- **Labels** are the translation keys `role.<key>` and `purpose.<key>` of the `NowoCrmKitBundle` domain; the built-in
  keys are translated in the 7 locales. For a key of your own, add the labels to
  `translations/NowoCrmKitBundle.<locale>.yaml` of the application:

  ```yaml
  role:
      wholesaler: Wholesaler
  purpose:
      logistics: Logistics
  ```
- **Removing a key** never breaks a stored row: its assignments stay in the database but are hidden from the record
  pages, lists, filters, forms and the CSV export (the GDPR export still lists them). A role edit only touches
  configured roles, so a hidden role is kept; adding the key back shows it again. To drop the rows, delete them with
  SQL (`DELETE FROM nowo_crm_role_assignment WHERE role_key = '…'`).

## Search

`search.enabled` registers two SearchKit providers, `nowo_crm_kit.search.company` and `nowo_crm_kit.search.contact`
(tag `nowo_search_kit.provider`, priorities 60 and 59), when SearchKit is installed **and** `ui.enabled` is true (the
results link to the CRM pages). SearchKit needs PHP >= 8.3. The number of results per group is SearchKit's query
limit.

## UI

- `ui.enabled: true` registers the controllers and makes the route loader `nowo_crm_kit_ui` return its routes; the
  host imports them (`{resource: ., type: nowo_crm_kit_ui, prefix: /admin}`). The routes live under the import prefix
  **and** `ui.path`: with the example, `/admin/crm/companies`.
- `ui.page_size`: default rows per list page (`?per_page=` may ask 1..100).
- `ui.layout`: a Twig layout of the host; null uses UiKit's admin layout.
- Two UiKit features are switched on by the host's UiKit configuration, not here: right-click / long-press context
  menus on row actions (`nowo_ui_kit.theme.context_menus: true`) and the focus-mode toggle
  (`nowo_ui_kit.theme.focus_mode.enabled: true`); without them the kebab menus still work and the toggle renders
  nothing.

## Later phases

`pipeline`, `quotes`, `invoices`, `campaigns`, `portal` and `reports` exist so a configuration can be written now.
`enabled: true` fails the container build with a message naming the release that brings the phase (funnels 0.3.0,
quotes 0.5.0, invoices 0.6.0, portal 0.7.0, circulars 0.10.0, reports later). See [ROADMAP.md](ROADMAP.md).

## Per-tenant settings

Retention values live per tenant in `crm_settings` and are edited on the settings page (`manage`) or through
`CrmSettingsInterface`; they are not container configuration:

| Setting | Range | Default |
| --- | --- | --- |
| `trash_days` | 7..365 | 30 |
| `timeline_years` | 1..10 | 5 |
| `import_keep_days` | 1..90 | 30 |
| `merge_keep_days` | 1..365 | 90 |

No phase-A value changes at runtime like an `.env` secret, so no runtime setting is registered in 0.1.0.

## Container parameters

Every value is published as `nowo_crm_kit.<path>` (for example `nowo_crm_kit.ui.enabled`,
`nowo_crm_kit.import.chunk_rows`, `nowo_crm_kit.security.access_roles`, `nowo_crm_kit.roles.catalogue`,
`nowo_crm_kit.affiliations.purposes`), plus `nowo_crm_kit.import.sync_chunks` (chunks run in
the confirm request). The alias `nowo_crm_kit.entity_manager` (public) points at the bundle's entity manager.
