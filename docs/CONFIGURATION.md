# Configuration

## Table of contents

- [Full reference](#full-reference)
- [Ports](#ports)
- [Security (REQ-UI-002)](#security-req-ui-002)
- [Feature gate and plan limits](#feature-gate-and-plan-limits)
- [Doctrine](#doctrine)
- [Fields, import and GDPR](#fields-import-and-gdpr)
- [HubSpot import (0.10.0)](#hubspot-import-0100)
  - [Timeouts](#timeouts)
- [Roles and affiliation purposes](#roles-and-affiliation-purposes)
- [Search](#search)
- [UI](#ui)
  - [Rows per page and the create dialog (0.8.0)](#rows-per-page-and-the-create-dialog-080)
  - [Filter bars (0.10.2)](#filter-bars-0102)
  - [Area tab icons](#area-tab-icons)
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
        hubspot:                   # 0.10.0
            enabled: false         # needs a secret box (secrets.*) and symfony/http-client
            page_size: 100         # 10..100 objects per HubSpot page
            pages_per_request: 2   # 1..20 pages a web request settles
            max_records: 50000     # 1..500000 objects per kind per run (beyond: failed, hubspot_too_many)
            outbound_url_guard: auto   # auto | required | off: pin api.hubapi.com through OutboundUrlGuard
            http: { timeout: 10, max_duration: 30, max_response_bytes: 8388608 }
            rate_limit: { max_attempts: 5, max_retry_after: 60 }
            connect_rate_limit: { limit: 5, interval: 600 }   # credential checks per tenant per window (seconds)
            browse_rate_limit: { limit: 60, interval: 600 }   # 0.10.1: HubSpot reads of the start / mapping pages per tenant
        messenger:                 # 0.10.0, any format
            enabled: false         # true: confirm / "Continue" dispatch RunCrmImport (symfony/messenger)
            units: 20              # 1..1000 chunks or pages per message
    secrets:                       # 0.10.0
        box: null                  # service id of CrmSecretBoxInterface; null = SodiumCrmSecretBox with keys below
        keys: {}                   # key id => '%env(base64:CRM_KIT_SECRET_KEY_2026)%' (32 bytes)
        current_key: null          # the key id that seals; the others only open
        doctrine_encrypt:          # 0.10.1: the DoctrineEncrypt box (secrets.box: DoctrineEncryptCrmSecretBox)
            profile: null          # DoctrineEncrypt profile that seals (null: its default profile); Halite or Defuse
            key_id: doctrine_encrypt   # key id stored with new seals; a new one with each new profile
            previous: {}           # key id => profile (~: the default one) of tokens sealed before a rotation
    actor_email_lookup: null       # 0.10.0: service id of CrmActorEmailLookupInterface (HubSpot owners by e-mail)
    gdpr:
        purposes: [marketing_email, newsletter, tracking]   # distinct keys ^[a-z][a-z0-9_.]{0,63}$
    roles:
        catalogue: [customer, supplier, partner]   # party roles; ≤ 32 distinct keys ^[a-z][a-z0-9_]{0,31}$
    affiliations:
        purposes: [billing, orders, technical, general]   # what a contact is a company's contact for; same rule
    search:
        enabled: true              # SearchKit groups (SearchKit >= 1.9) when it is installed and the UI is on
    ui:
        enabled: false             # routes are opt-in (REQ-UI-001)
        path: /crm                 # starts with "/"; base path of the routes (under the import prefix)
        page_size: 25              # 10..100, default rows per list page
        layout: null               # Twig layout of the pages; null = UiKit's admin layout
        row_actions: buttons       # 0.6.0: buttons | menu (Restore, Move to trash in the UiKit row-actions menu)
        header_actions: toolbar    # 0.6.0: toolbar | split (UiKit split header)
        record_actions: split      # 0.6.0: split (Edit icon, the rest in the more menu) | toolbar
        record_tabs: true          # 0.6.0: false hides the list tabs on record pages
        filters_fold: false        # 0.6.0: UiKit fold toggle on the filters card
        filters_slot: null         # 0.6.0: host Twig template wrapping the filters (page, form, filters); see USAGE
        list_tabs: true            # 0.6.1: false hides the area tabs on list, admin and form pages (record: record_tabs)
        admin_create: inline       # 0.6.1: inline | dialog (0.8.0: loaded on demand) | dialog_inline (deprecated, 0.6.1 in-page dialog)
        settings_width: form       # 0.6.1: form (UiKit form panel) | full (bundle card, full content width)
        tab_icons: {}              # 0.7.0: area => icon name, merged over the built-in Tabler set (see "Area tab icons")
        list_per_page: []          # 0.8.0: "Rows per page" choices, e.g. [10, 25, 50, 100]; [] = no select (see "Rows per page")
        record_create: page        # 0.8.0: page | dialog ("New company / contact" in a UiKit remote form dialog, on demand)
        pipeline_forms: inline     # 0.9.0: inline (deprecated) | dialog (the funnel pages' forms and confirms on demand)
        filters_more: { ... }      # 0.10.2: per list, the filters folded under "More filters" (see "Filter bars")
        per_page_position: filters # 0.10.2: filters (compact, before Filter / Clear) | pagination (UiKit compact pager)
        search_width: null         # 0.10.2: null = one grid column | xs | sm | md | lg (two columns) | full
        per_page_row_class: null   # 0.10.2: extra classes on the "N per page" row, e.g. issue-filters__pager
    pipeline:  { enabled: false }  # phase B, funnels (0.3.0)
    api:                           # 0.3.0: see API.md
        enabled: false             # the Api\Operation layer and the idempotency table (an MCP host needs only this)
        page_size: 25              # 10..100: default page of the keyset lists
        idempotency_ttl_seconds: 86400   # 3600..604800
        http:
            enabled: false         # the /crm/v1 routes (needs api.enabled)
            prefix: /api           # routes under <prefix>/crm/v1
            authenticator: null    # a CrmApiAuthenticatorInterface service id; null = the firewall user + default_grant
            tenant_resolver: null  # a CrmApiTenantResolverInterface service id; null = the configured tenant resolver
            default_grant: { level: view, amounts: false, consents: false, destructive: false }
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
| `actor_email_lookup` (0.10.0) | `Domain\Port\CrmActorEmailLookupInterface` | none: the actor directory's owners filtered by e-mail |

Panel providers (`CrmPanelProviderInterface`) and host object resolvers (`CrmHostObjectResolverInterface`) are not
configured: any service implementing them is autoconfigured with its tag (`nowo_crm_kit.panel`,
`nowo_crm_kit.host_object`).

The link picker (0.7.0) has no configuration key either: a host object resolver turns it on by also implementing
`CrmHostObjectSearchInterface`, and names its type by implementing `CrmHostObjectTypeLabelInterface` (USAGE, "Link
picker"). Its endpoint `nowo_crm_kit_link_search` is registered with the other UI routes (`ui.enabled`, under
`ui.path`) and needs the same firewall as the rest of the CRM.

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

## HubSpot import (0.10.0)

`import.hubspot.enabled: true` maps two more tables (`crm_hubspot_connection`, `crm_external_ref`, mapping group
`NowoCrmKitBundleHubSpot`), registers the HubSpot routes under `ui.path` (`/import/hubspot…`) and the services. The
container refuses it without a secret box:

```yaml
nowo_crm_kit:
    secrets:
        keys:
            k2026: '%env(base64:CRM_KIT_SECRET_KEY_2026)%'   # 32 random bytes: php -r "echo base64_encode(random_bytes(32));"
        current_key: k2026
    import:
        hubspot:
            enabled: true
```

Or the house option (Beacon), DoctrineEncryptBundle's default profile (or `Halite` / `Defuse`; MysqlAes is refused):

```yaml
nowo_crm_kit:
    secrets:
        box: Nowo\CrmKitBundle\Bridge\DoctrineEncrypt\DoctrineEncryptCrmSecretBox
```

### Key rotation

Rotation never loses a token (0.10.1). `nowo:crm-kit:secrets:rotate` re-seals each token under the current key in its
own unit under the connection's row lock, and writes the new seal only after it opened again to the same token. A
token that does not open, whose key is not available or that cannot be re-sealed is left exactly as it is, and the
command exits with 1: keep the old key until a run ends with `0 left unchanged`. A connection that is already invalid
and whose token does not open is reported on its own line and never fails the run: it waits for a new token whatever
the keys (disconnect it or let a manager paste a new token). `--dry-run` does the same in memory
(it also proves the new key seals) and writes nothing. Tokens sealed before the rotation keep working all along: every
configured key opens. Running imports are not stopped (an import that read the old value opens it with the old key).

If an import meets a token whose key is not configured (dropped too early, or a DoctrineEncrypt profile or key file
that fails), it refuses with `error.hubspot_key_unavailable` (a running import pauses with `hubspot_key_unavailable`).
Nothing is marked invalid and the sealed token is kept: configure the key again and press "Continue", or let a manager
paste the token again (that resumes the paused imports). The log says which key id and the class of the failure
(never its message). A key that is missing, unreadable, malformed or of the wrong length counts as "not available":
the DoctrineEncrypt box loads the key on its own before it decrypts (0.10.1). A wrong but well-formed key cannot be
told apart from tampering, though: the token does not open and the connection is marked invalid. So **change the key
id whenever the key or the DoctrineEncrypt profile behind it changes**: a token sealed under the old id then finds
its old key (or is reported as "not available") instead of being opened with the new one.

**Every rotation, both boxes:**

1. Deploy the new configuration and restart **every** process that runs the kit: FrankenPHP workers, Messenger
   consumers and cron hosts. A process still on the old container keeps sealing new tokens (connect, replace) under
   the old key id.
2. Run `nowo:crm-kit:secrets:rotate --dry-run`, then `nowo:crm-kit:secrets:rotate`, until it exits with 0.
3. Just before removing the old key, run `nowo:crm-kit:secrets:rotate` once more and expect `0 left unchanged` (it
   catches tokens a late process sealed meanwhile). Then remove the old key, and deploy and restart again.

**Sodium box.** Add the new key, make it `current_key` and keep the old one listed, then follow the steps above and
drop the old key at the end.

**DoctrineEncrypt box.** A DoctrineEncrypt profile holds one key, and `doctrine:encrypt:rotate-keys` re-encrypts only
`#[Encrypted]` columns, never the CRM's sealed tokens. So rotate through a second profile:

1. Add a DoctrineEncrypt profile with a new key (generate it with `doctrine:encrypt:generate-secret-key`):

   ```yaml
   nowo_doctrine_encrypt:
       profiles:
           default: { encryptor_class: Halite }               # what sealed the tokens until now
           crm_2026: { encryptor_class: Halite, secret_key_filename: .Halite.crm_2026.key }
   ```

2. Seal with it under a new key id and keep the old one opening:

   ```yaml
   nowo_crm_kit:
       secrets:
           box: Nowo\CrmKitBundle\Bridge\DoctrineEncrypt\DoctrineEncryptCrmSecretBox
           doctrine_encrypt:
               profile: crm_2026
               key_id: de2026
               previous:
                   doctrine_encrypt: default    # 0.10.0 sealed under the key id "doctrine_encrypt"
   ```

3. Follow the steps above (restart everything, rotate until it exits with 0, rotate once more).
4. Remove `previous.doctrine_encrypt`. Keep the old DoctrineEncrypt profile while other columns use it. Never point
   an existing key id at another profile: give the new profile a new `key_id`.

Name the profiles explicitly in `previous`: `~` means "the default profile", which moves if `default_profile` changes.
The container build fails when `profile` or a `previous` profile does not exist or does not use Halite or Defuse
(MysqlAes is never accepted). For another (authenticated) encryptor define your own service
`new DoctrineEncryptCrmSecretBox($registry, $profile, allowCustom: true, keyId: …, previous: […])` and name it in
`secrets.box` (`secrets.doctrine_encrypt` then does not apply; the box refuses another encryptor at its first use).

Keys come from the environment only (`%env(base64:…)%`): `secrets.keys` is never published as container parameters.
Never paste a production credential with the Symfony profiler or the debug toolbar on.

`import.hubspot.browse_rate_limit` (0.10.1, default 60 per 600 seconds) counts, per tenant, each view of the start
and mapping pages and each start: every one asks HubSpot (lists, counts, properties), and the portal's daily API quota
is shared with the tenant's other HubSpot apps. Beyond it the page answers `error.hubspot_browse_limited` without
asking HubSpot. The confirm step counts too, but only after the checks it can make without HubSpot (the import's
version, the mapping's keys and lists), so a stale or invalid retry costs nothing; the import's own pages are not
counted.

There is no base-URL key: the HubSpot host is the constant `https://api.hubapi.com`. In a demo or an end-to-end test,
decorate the kit's client service `nowo_crm_kit.hubspot.http_client` with a fake (a `MockHttpClient` or a local fake
server); never use a real credential in tests. `outbound_url_guard: auto` pins the resolved public address of the host
when nowo-tech/outbound-url-guard-bundle is installed (`required` fails the build without it; `off` never pins).
`import.messenger.*` and the scopes to tick: [USAGE.md](USAGE.md#hubspot-import-0100).

### Timeouts

REQ-RUNTIME-001. HubSpot is the kit's only outbound HTTP, and every call has two deadlines from
`import.hubspot.http`: `timeout` (idle, default 10 s) and `max_duration` (total, default 30 s). A call that stalls or
runs past them ends as `hubspot_unavailable`: a web request answers it, and a run pauses and retries later. A web request
never waits on HubSpot's `Retry-After` (only the resume command and the Messenger handler wait, up to
`rate_limit.max_attempts` × `rate_limit.max_retry_after`, and they run without `max_execution_time`).

A web request can make several calls in a row, so size the outer layers on the request that makes the most:

| Request | HubSpot calls (at most) |
| --- | --- |
| Connect / replace | 2 + one probe per required read scope for a service key (8 in all) |
| Start page / start | 1 / 5 |
| Mapping page / confirm | 3 / 3 |
| A run page ("Continue", the confirm's first pages) | 2 per HubSpot page × `pages_per_request` |

Keep the hierarchy, innermost first, and raise the outer layers in the same change when you raise an inner one:

1. Operation: `calls × max_duration` of the largest request above, for example 8 × 10 s = 80 s with
   `http.max_duration: 10` (the default 30 s gives 240 s for a connect: lower `max_duration` on hosts with a short
   PHP limit).
2. PHP `max_execution_time` (and `max_input_time`): strictly greater, for example 120 s.
3. Caddy / reverse proxy write timeout (`servers { timeouts { write … } }`): strictly greater than PHP, for example
   150 s.
4. FrankenPHP `max_wait_time` (worker mode): caps how long a request waits for a free thread, for example 30 s, so
   slow HubSpot calls cannot queue every request behind them.

Imports with `import.messenger.enabled` run in the Messenger worker, outside these web limits; the per-call deadlines
still apply there.

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

`search.enabled` registers the SearchKit groups `crm_companies`, `crm_contacts` and, with `pipeline.enabled`,
`crm_deals` (services `nowo_crm_kit.search.company`, `.contact`, `.deal`; tag `nowo_search_kit.entity_definition`,
priorities 60, 59 and 58) when SearchKit **>= 1.9** is installed **and** `ui.enabled` is true (the results link to the
CRM pages). SearchKit needs PHP >= 8.3. Per group, `nowo_search_kit.groups.<key>.enabled` and `.priority` (SearchKit's
configuration) switch it off or reorder it; the number of results per page and the candidate window are SearchKit's.

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

### Rows per page and the create dialog (0.8.0)

`ui.list_per_page` adds a **Rows per page** select (`select[name=per_page]`; 0.10.2: compact, see "Filter bars") to
the filter card of the company and contact lists, Tags and Custom fields (0.10.2: Deals too). Default `[]`: no
select, so the filter card of an existing host is unchanged (a new field moves the grid of a host that styles it); opt
in with the values you offer:

```yaml
nowo_crm_kit:
    ui:
        page_size: 25                    # must be one of list_per_page when that is set
        list_per_page: [10, 25, 50, 100] # 1-10 distinct integers from 5 to 100
        record_create: dialog
```

A `per_page` outside the list (or not an integer) falls back to `page_size`, and the select shows the value used.
Without `list_per_page` the lists accept UiKit's values (10, 25, 50, 100) and `page_size`. The UiKit pager with its
"Showing x–y of z" line renders under every list that has rows, on a single page too (page links from two pages on);
a page past the end shows the last page. The pages keep the filters, the sort and `per_page`.

`ui.record_create: dialog` opens *New company* / *New contact* in a UiKit remote form dialog that loads the form page
on demand (nothing of the form is in the list page); `page` (default) keeps the plain link. Since 0.8.0
`ui.admin_create: dialog` (Tags, Custom fields) loads its form on demand the same way; the 0.6.1 in-page dialog is
`dialog_inline`, deprecated. See USAGE, "Page layout
options".

`ui.pipeline_forms: dialog` (0.9.0, with `pipeline.enabled`) does the same for the funnel pages: *Move to a stage*, the
next action and lifecycle forms, *New funnel*, *New stage*, each stage's *Edit* and the trash / purge confirms of a deal
become their own pages, opened on demand into one remote form dialog per page; the pages render none of those forms.
Why a key of its own and not `record_create` / `admin_create`: those two name one action on one page each, while this
switches every form of the funnel pages together (the 360° page's other forms — notes, tags, links, affiliations,
consent — are not covered yet, so a general `ui.forms` would promise more than it does). `inline` (default) keeps the
0.8 pages and is deprecated (`dialog` becomes the default in 1.0). See UPGRADING, "To 0.9.0".

### Filter bars (0.10.2)

Every list (Companies, Contacts, Deals, Tags, Custom fields, Funnels) follows Beacon's filter standard (UI-PATTERNS
§7, §8, §11) through UiKit 2.72+: sortable headers, the pager with *Showing x–y of N*, the filter card. On Companies,
Contacts and Deals: the
search one grid column wide with a placeholder and an `aria-label`, the main filters in the bar, the rarer ones
folded under **More filters** (a `<details>` after the actions that shows how many of them are set and stays open
while one is), and with `list_per_page` a compact *N per page* select first in the actions, then Filter / Clear
filters.

```yaml
nowo_crm_kit:
    ui:
        list_per_page: [10, 25, 50, 100]
        per_page_position: filters          # or pagination: the select in UiKit's compact pager (Companies, Contacts)
        per_page_row_class: issue-filters__pager
        search_width: null                  # lg = two columns, as in 0.10.1
        filters_more:                       # [] for a list = no fold
            companies: [role, created_from, created_to, updated_from, updated_to]
            contacts: [role, purpose, primary_contact, company, created_from, created_to, updated_from, updated_to]
            deals: [stage, company, contact, stale, expected_close_from, expected_close_to]
```

The fields a list can fold: Companies `tag, role, owner, status, created_from, created_to, updated_from,
updated_to`; Contacts the same plus `purpose, primary_contact, company`; Deals `funnel, status, owner, stage,
company, contact, stale, expected_close_from, expected_close_to`. A name a list does not have, or a name twice, fails
the container build.

Filters: the owner select starts with **Mine** (`owner=me`, the person the actor resolver returns) and **No owner**
(`owner=none`); dates are native date fields (`YYYY-MM-DD`, whole UTC days, both ends included). Column headers sort
on the server: name, created and updated on Companies and Contacts; title, amount (with the amounts permission),
expected close and updated on Deals.

Empty states: on a list's defaults the message says why it is empty, with create and **Import** (the hint names
HubSpot when `import.hubspot.enabled`); with a filter set it says nothing matches and offers **Clear filters**. While
the list is empty and unfiltered the header shows no create button (one create button, UI-PATTERNS §11).

The look of the buttons is UiKit's host configuration: icon-only Filter / Clear filters with
`nowo_ui_kit.theme.pages.list.filters.display: icon` (an icon set other than `none`), and the phone bottom sheet with
`nowo_ui_kit.theme.pages.list.filters.sheet: true`.

### Area tab icons

Since 0.7.0 every area tab (the UiKit `AreaNav` of the pages, see `ui.list_tabs` / `ui.record_tabs`) carries an icon
(UiKit `AreaNavItem::$icon`), so the tab style the user picks on the host — `data-tab-style` `text`, `icon-text` or
`icon` on an ancestor, usually `<html>` — has something to show. The built-in set uses Tabler names, the scheme of the
bundle's SearchKit groups and of the other nowo-tech bundles' tab icons:

| Area | Tab | Default icon |
| --- | --- | --- |
| `companies` | Companies | `tabler:building` |
| `contacts` | Contacts | `tabler:users` |
| `deals` | Deals (with `pipeline.enabled`) | `tabler:briefcase` |
| `tags` | Tags | `tabler:tags` |
| `fields` | Custom fields | `tabler:forms` |
| `settings` | Settings | `tabler:settings` |
| `funnels` | Funnels (with `pipeline.enabled`) | `tabler:filter` |

UiKit draws a Tabler name with `nowo_ui_kit.icon_set: tabler-icons` (the Tabler webfont the host loads) or `ux_icon`
(Symfony UX Icons with the Tabler set available, e.g. on-demand Iconify or locally imported icons). Any other icon set
(`bootstrap-icons`, UiKit's default, `svg_inline`, `none`) cannot draw a `tabler:` name, and UiKit then renders the
tab's label only — never an empty glyph — so the tabs look as before.

`ui.tab_icons` overrides the icon per area, **merged over the built-in set**: name only the areas you change. The value
is an icon name `[prefix:]name` as UiKit takes it (lowercase letters, digits and single hyphens, e.g. `tabler:coin`,
`bi:building`); `""` or `null` removes that tab's icon. An unknown area or a malformed name fails the container build.

```yaml
nowo_crm_kit:
    ui:
        tab_icons:
            deals: tabler:currency-euro   # Companies, Contacts, Tags… keep their default icon
```

The icon is decoration only: it is `aria-hidden`, the tab keeps its translated label as its accessible name in every tab
style (in `icon` mode UiKit clips the label visually instead of removing it and shows it as a tooltip on hover and
keyboard focus), and which tabs a user sees is still decided by the access checks (`manage` for Tags, Custom fields,
Settings and Funnels; the access checker and the `*_ACCESS_CHECK` events). A tab without an icon (blanked here, or a
name the host's icon set cannot draw) always shows its label, also in `icon` mode. A name the icon set accepts but has
no glyph for (a typo under `tabler-icons`) renders an empty glyph with the label clipped: the tab stays named for
assistive technology and the tooltip, but check overrides against the icon set the host loads. Under `ux_icon` the
name goes to `ux_icon()` as written, so a missing icon follows `ux_icons.ignore_not_found` (an exception, or nothing
drawn): with Iconify `on_demand: false`, import the seven defaults (`bin/console ux:icons:import tabler:building
tabler:users tabler:briefcase tabler:tags tabler:forms tabler:settings tabler:filter`) and any override.

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
the confirm request). **0.3.0:** `nowo_crm_kit.api_prefix` (`/api`) and `nowo_crm_kit.api_path` (`/api/crm/v1`, for the
firewall pattern `^%nowo_crm_kit.api_path%/`). The alias `nowo_crm_kit.entity_manager` (public) points at the bundle's entity manager.
