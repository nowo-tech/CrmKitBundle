# Configuration

## Table of contents

- [Full reference](#full-reference)
- [Ports](#ports)
- [Access (default checker)](#access-default-checker)
- [Feature gate and plan limits](#feature-gate-and-plan-limits)
- [Doctrine](#doctrine)
- [Fields, import and GDPR](#fields-import-and-gdpr)
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
    access_checker: null           # service id of CrmAccessCheckerInterface; null = RoleMapAccessChecker
    feature_gate: null             # service id of CrmFeatureGateInterface; null = ConfiguredFeatureGate
    plan_limits: null              # service id of CrmPlanLimitsInterface; null = ConfiguredPlanLimits
    actor_directory: null          # service id of CrmActorDirectoryInterface; null = the signed-in user only
    audit_sink: null               # service id of CrmAuditSinkInterface; null = one PSR log line per entry
    clock: null                    # service id of Psr\Clock\ClockInterface; null = FrameworkBundle's `clock`
    access:
        roles:                     # RoleMapAccessChecker only: permission => security roles; every list empty = denied
            view: []
            edit: []
            manage: []
            amounts_view: []       # used from phase B
            campaigns_send: []     # used from phase E
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
    search:
        enabled: true              # SearchKit providers when SearchKit is installed and the UI is on
    ui:
        enabled: false             # routes are opt-in (REQ-UI-001)
        path: /crm                 # starts with "/"; base path of the routes (under the import prefix)
        page_size: 25              # 10..100, default rows per list page
        layout: null               # Twig layout of the pages; null = UiKit's admin layout
    pipeline:  { enabled: false }  # phase B (0.2.0)
    quotes:    { enabled: false }  # phase C (0.3.0)
    invoices:  { enabled: false }  # phase D (0.4.0)
    campaigns: { enabled: false }  # phase E (0.5.0)
    portal:    { enabled: false }  # phase F (0.6.0)
    reports:   { enabled: false }  # phase G (0.7.0)
```

## Ports

Each port key takes the **service id** of the host's implementation; `null` keeps the bundle default. No bundle
service ever needs to be overridden. What each port does and how to implement it: [USAGE.md](USAGE.md#ports-to-implement).

| Key | Interface | Default |
| --- | --- | --- |
| `tenant_resolver` | `Domain\Port\CrmTenantResolverInterface` | `SingleTenantResolver`: tenant `default` |
| `access_checker` | `Domain\Port\CrmAccessCheckerInterface` | `RoleMapAccessChecker`: `access.roles`, deny by default |
| `feature_gate` | `Domain\Port\CrmFeatureGateInterface` | `ConfiguredFeatureGate`: `feature.default_enabled` |
| `plan_limits` | `Domain\Port\CrmPlanLimitsInterface` | `ConfiguredPlanLimits`: `limits.*` |
| `actor_directory` | `Domain\Port\CrmActorDirectoryInterface` | `SecurityActorDirectory`: the signed-in user only |
| `audit_sink` | `Domain\Port\CrmAuditSinkInterface` | `PsrLogAuditSink`: one `info` line per entry |
| `clock` | `Psr\Clock\ClockInterface` | FrameworkBundle's `clock` service |

Panel providers (`CrmPanelProviderInterface`) and host object resolvers (`CrmHostObjectResolverInterface`) are not
configured: any service implementing them is autoconfigured with its tag (`nowo_crm_kit.panel`,
`nowo_crm_kit.host_object`).

## Access (default checker)

`access.roles` maps each `CrmPermission` to Symfony security roles. A role grants the permission tenant-wide. With
every list empty nothing is granted, and without SecurityBundle nothing is granted either. The actor is the security
user's identifier (an identifier that is not a usable exact id acts as nobody, so the CRM answers 404).

```yaml
nowo_crm_kit:
    access:
        roles:
            view: [ROLE_USER]
            edit: [ROLE_USER]
            manage: [ROLE_ADMIN]
```

`edit` and `manage` do not imply `view`: grant what each role needs. The permission matrix is in
[SECURITY.md](SECURITY.md#permission-matrix).

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
- `import.stale_claim_seconds`: when a running import has made no progress for this long, `nowo:crm-kit:import:resume`
  (run it every few minutes) takes it over, and anyone with `edit` may cancel it from its page.
- `gdpr.purposes`: the consent purposes the UI offers and the service accepts.

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
`enabled: true` fails the container build with a message naming the release that brings the phase. See
[ROADMAP.md](ROADMAP.md).

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
`nowo_crm_kit.import.chunk_rows`, `nowo_crm_kit.access.roles`), plus `nowo_crm_kit.import.sync_chunks` (chunks run in
the confirm request). The alias `nowo_crm_kit.entity_manager` (public) points at the bundle's entity manager.
