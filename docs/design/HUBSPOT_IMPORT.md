# HubSpot import source: design

Status 2026-11-23: **H1 built for 0.10.0** (see the revision at the end of CONTRACT §26: service keys, scope probing, `run_cursor`); H2 and H3 not built. Original status: design, not built. For symfony-beacon spec `253-crm-hubspot-import` (block A, tasks T001–T003,
T020) and owner decision D-20261123-04. Nothing here is code yet; each slice gets its contract revision from this page
(CONTRACT §26) before it is built. The open questions are in [section 15](#15-questions-for-the-owner-q-h); the build
follows the recommended answers until the owner replies.

## Table of contents

- [1. Summary](#1-summary)
- [2. Slices and versions](#2-slices-and-versions)
- [3. Fixed points (owner, 2026-11-23)](#3-fixed-points-owner-2026-11-23)
- [4. Shape](#4-shape)
- [5. Public services, host ports and value objects](#5-public-services-host-ports-and-value-objects)
- [6. Configuration](#6-configuration)
- [7. Tables and columns](#7-tables-and-columns)
- [8. Encryption of the token](#8-encryption-of-the-token)
- [9. HubSpot API: endpoints, scopes, client rules](#9-hubspot-api-endpoints-scopes-client-rules)
- [10. Run model: stages, paging, backoff, resume](#10-run-model-stages-paging-backoff-resume)
- [11. Mapping of objects and fields](#11-mapping-of-objects-and-fields)
- [12. Matching, duplicate policy, idempotency and atomicity](#12-matching-duplicate-policy-idempotency-and-atomicity)
- [13. UI and routes](#13-ui-and-routes)
- [14. Threat model](#14-threat-model)
- [15. Questions for the owner (Q-H)](#15-questions-for-the-owner-q-h)
- [16. Test plan](#16-test-plan)
- [17. Beacon side (block B, for reference)](#17-beacon-side-block-b-for-reference)

## 1. Summary

HubSpot becomes a third import **format** next to CSV and vCard: `ImportFormat::HubSpot`. It uses the same
`crm_import` queue (claim, counters, `unfinished()`, resume command, cancel), the same plan limits, the same duplicate
policy and the same import modal. What is new:

- one **connection** per tenant (a private app token, later OAuth), validated against HubSpot before it is saved,
  sealed with an AEAD key held outside the database, shown only as the account label and the token's last 4 characters;
- a **reader** that pages HubSpot's CRM API from a fixed base URL, outside any database transaction;
- an **external reference** table that keeps the HubSpot object id of every record, funnel, stage and tag the import
  created or matched, so a re-run updates instead of duplicating;
- a **stage cursor** on the import, so a run resumes at the page where it stopped (429, crash, cancel, deploy).

## 2. Slices and versions

0.8.0 is taken by PR #19 (rows per page, list pager, create dialogs). Each slice is one PR, one minor, one REQ-SEC-004
audit before its tag. If another release lands first, the numbers move up by one; the order does not change.

| Slice | Version | Content | Needs |
| --- | --- | --- | --- |
| **H1** connection, companies, contacts | **0.9.0** | Private app token connection (validate, seal, replace, disconnect); companies; contacts; contact ↔ company associations (primary flag); owners → members by e-mail; lists → tags; property mapping incl. custom fields; external refs; re-run upsert; stage cursor, 429 backoff, resume; optional Messenger run (§10.5) | — |
| **H2** deals | **0.10.0** | Pipelines → deal funnels, stages → stages (created on confirmation), deals with amount, currency, close date, status open / won / lost, deal ↔ company / contact | `pipeline.enabled` (phase B, **released in 0.3.0**: `crm_funnel`, `crm_stage`, `crm_deal` exist) |
| **H3** OAuth | **0.11.0** | "Connect to HubSpot" (public app, state + PKCE S256), refresh token sealed, access token refreshed per run, revoke on disconnect; the token path keeps working | H1 |

The funnels the owner made a precondition for deals already exist (0.3.0), so H2 is not blocked by a phase: it is a
separate slice only to keep each PR and audit small (Q-H1).

## 3. Fixed points (owner, 2026-11-23)

- One more source next to CSV / vCard: same queue, mapping step, duplicate policy, limits, progress, unfinished list.
- Private app token first (paste-in, read-only scopes, validated before saving: account id + scopes, sealed, shown as
  last 4 + account label, one connection per tenant); OAuth later (state + PKCE, sealed refresh token, revoke on
  disconnect).
- v1 objects: companies, contacts, associations, owners → members by e-mail, lists → tags, deals (H2).
- One-off, repeatable: records keep the HubSpot object id; a re-run matches by that id first, then by the duplicate
  policy. No scheduled sync, no webhooks, no export, no write to HubSpot.
- Security: import permission through the host port, **404** otherwise; fixed HubSpot base URL; 429 backoff and
  resumable; token and response bodies never logged nor stored in `crm_import_row`; a failed import leaves no half
  association.

## 4. Shape

```
Presentation   HubSpotImportController (connect, disconnect, start, mapping fragments; the import modal protocol)
Application    HubSpotConnectionService · HubSpotImportService (start, schema, confirm) · HubSpotRunner (one stage
               page per unit, called by ImportService::run() for format hubspot) · HubSpotMapper (pure)
               Port (internal): HubSpotReaderInterface (typed pages; no HTTP types) · ExternalRefStoreInterface
Domain         Enum HubSpotObject, ConnectionKind, ConnectionStatus; VOs (§5.3); events HubSpotConnected /
               HubSpotDisconnected; ports CrmSecretBoxInterface, CrmActorEmailLookupInterface (optional)
Infrastructure HubSpot\HttpHubSpotReader (own HttpClient, fixed base URL) · Doctrine stores · SodiumCrmSecretBox
               Messenger\RunCrmImportHandler (optional)
Bridge         DoctrineEncrypt\DoctrineEncryptCrmSecretBox · OutboundUrlGuard\GuardedHubSpotTransport (optional)
```

REQ-ARCH-001: every new port takes and returns bundle VOs and scalars only; the reader port returns bundle read models
(`HubSpotPage`, `HubSpotRecord`), never `ResponseInterface` or arrays of raw JSON. Entities are plain classes with XML
mapping in a new mapping group `doctrine-hubspot`, registered only when `import.hubspot.enabled`; all writes go through
the private EM and transaction-doctrine write units (REQ-RUNTIME-003); no service property holds a tenant, actor, token
or record (REQ-RUNTIME-002).

## 5. Public services, host ports and value objects

### 5.1 Services (`Domain\Service`, what a host or the UI calls)

```php
interface HubSpotConnectionServiceInterface
{
    /** The tenant's connection (no secret in the view); null = not connected. `import`. */
    public function status(): ?HubSpotConnectionView;

    /**
     * Validates the token against HubSpot (account id, scopes), then seals and stores it in one unit, replacing the
     * tenant's connection under its version. `import` + `manage` (Q-H3).
     *
     * @throws InvalidInputException `token` (error.hubspot_token_invalid | _scope_missing | _scope_not_read_only |
     *                               _account_changed (needs $confirmAccountChange)), never with HubSpot's text
     * @throws ConflictException     crm_record_stale, crm_hubspot_rate_limited (connect attempts)
     */
    public function connect(#[\SensitiveParameter] string $token, ?string $label, ?int $expectedVersion, bool $confirmAccountChange = false): HubSpotConnectionView;

    /** Removes the sealed token (H3: revokes the refresh token first, best effort); running HubSpot imports stop at their next unit. */
    public function disconnect(int $expectedVersion): void;
}

interface HubSpotImportServiceInterface
{
    /** Creates a `hubspot` import in `preview` for the chosen objects and lists (counts read from HubSpot); its id. `import`. */
    public function start(HubSpotImportScope $scope): string;

    /** What the mapping step offers: properties per object, pipelines / stages (H2), lists, current mapping. `import`. */
    public function schema(string $importId): HubSpotSchemaView;

    /** Claims the import with the mapping (same claim as ImportServiceInterface::confirm()); funnels, stages and tags
     *  marked "create" are created in the claim unit. */
    public function confirm(string $importId, HubSpotMapping $mapping, DuplicatePolicy $policy, int $expectedVersion): void;
}
```

`ImportServiceInterface::run()`, `cancel()`, `unfinished()` and `preview()` serve `hubspot` imports unchanged (run
dispatches on the format).

### 5.2 Host ports (`Domain\Port`)

| Port | Default | Signature | Notes |
| --- | --- | --- | --- |
| `CrmAccessCheckerInterface` (existing) | `SecurityCrmAccessChecker` | `isGranted(CrmPermission::Import, TenantRef, null)` | Area `canImport`; Beacon maps its `crm.import`. HubSpot routes answer **404** on a denial (§13) |
| `CrmSecretBoxInterface` **(new, required when HubSpot is on)** | `SodiumCrmSecretBox` when `secrets.keys` is set; DoctrineEncrypt bridge on request; else the container refuses `import.hubspot.enabled: true` | `seal(#[\SensitiveParameter] string $plaintext, SecretContext $context): SealedSecret`; `unseal(SealedSecret $sealed, SecretContext $context): string` (throws `SecretUnsealException` on a wrong key, tampering or another context) | §8 |
| `CrmActorEmailLookupInterface` **(new, optional)** | falls back to `CrmActorDirectoryInterface::owners()` filtered by `EmailAddress::key()` | `actorsByEmails(TenantRef $tenant, list<EmailAddress> $emails): array<string, ActorRef>` keyed by `EmailAddress::key()` | Only people who may own records in the tenant; the kit re-checks each returned key with `===` against the requested ones and drops any other (REQ-SEC-006 rule 4) |
| `CrmPlanLimitsInterface`, `CrmTenantResolverInterface`, `CrmFeatureGateInterface`, `CrmAuditSinkInterface`, `ClockInterface`, `IdGeneratorInterface` | existing | unchanged | Same per-call rules |
| (H3) `CrmHubSpotOAuthAppInterface` **(new)** | `ConfiguredHubSpotOAuthApp` (`import.hubspot.oauth.*`, runtime settings env / DB) | `clientId(): string`, `clientSecret(): string` (`#[\SensitiveParameter]` on use), `redirectUri(): string` | App credentials are a runtime setting (`RuntimeSettingDefinition`), per house rule |

### 5.3 Value objects and enums (`Domain\Value`, `Domain\Enum`; `final readonly`, validated in the constructor)

| VO / enum | Rule |
| --- | --- |
| `ImportFormat::HubSpot` (`hubspot`) | New case |
| `HubSpotObject` enum | `Company` (`companies`), `Contact` (`contacts`), `Deal` (`deals`, H2) |
| `HubSpotAccountId` | `\A[1-9][0-9]{0,19}\z` with `/D` (the portal / hub id) |
| `HubSpotObjectId` | `\A[1-9][0-9]{0,19}\z` `/D` (objects, owners, lists, pipelines and stages that are numeric) |
| `HubSpotKey` | `\A[A-Za-z0-9_.:-]{1,100}\z` `/D` (property names, pipeline / stage ids such as `default`, `appointmentscheduled`) |
| `HubSpotCursor` | `\A[A-Za-z0-9_=-]{1,256}\z` `/D`: the opaque `after` token; never a URL (`paging.next.link` is ignored) |
| `HubSpotImportScope` | `list<HubSpotObject>` (≥ 1, contacts and deals imply companies are read first), `list<HubSpotObjectId>` lists (≤ 50) |
| `HubSpotMapping` | per object `array<HubSpotKey, target>` with targets as CSV (`name`, `custom.<key>`, `owner`, `''` skip); `pipelines: array<HubSpotKey, FunnelChoice>` (existing funnel id or `create`), `stages: array<HubSpotKey, StageChoice>`, `lists: array<HubSpotObjectId, TagChoice>` (existing tag id or `create`) |
| `SecretContext` | purpose (`hubspot.token`, `hubspot.refresh_token`), `TenantRef`, connection id; `aad(): string` = `nowo-crm-kit|v1|<purpose>|<tenant>|<connection id>` |
| `SealedSecret` | key id (`\A[a-z0-9_-]{1,16}\z` `/D`) + ciphertext (base64, ≤ 4 KiB) |
| `HubSpotConnectionView` | kind, status (`active`, `invalid`), account id, label, `tokenLast4`, scopes, validated at, version |
| `HubSpotSchemaView`, `HubSpotPropertyView` | name, label (shown escaped), type, `targets` it may map to |

Events (after commit, ids only): `HubSpotConnected` (tenant, actor, account id, replaced: bool), `HubSpotDisconnected`
(tenant, actor, account id). Audit keys `crm.hubspot.connected`, `crm.hubspot.replaced`, `crm.hubspot.disconnected`,
`crm.hubspot.token_invalid` (counts / ids only), plus the existing `crm.import.*`.

## 6. Configuration

```yaml
nowo_crm_kit:
    secrets:                         # new; used by every sealed value of the kit
        box: null                    # service id of CrmSecretBoxInterface; null = SodiumCrmSecretBox with the keys below
        keys: {}                     # key id => '%env(base64:CRM_KIT_SECRET_KEY_2026)%' (32 bytes each)
        current_key: null            # key id used to seal; the others only unseal (rotation)
    import:
        hubspot:
            enabled: false           # needs a secret box; registers the mapping group, routes and services
            deals: false             # H2; needs pipeline.enabled
            page_size: 100           # 10..100 (HubSpot's maximum for list endpoints)
            pages_per_request: 2     # 1..20 pages a web request settles before "Continue" / the background run
            max_records: 50000       # 1..500000 per object per run; beyond it the run stops with error.hubspot_too_many
            lost_reason: null        # H2: pipeline.lost_reasons key for lost deals (Q-H8); null = first configured key
            http:
                timeout: 10          # seconds idle
                max_duration: 30     # seconds per request
                max_response_bytes: 8388608
            rate_limit:
                max_attempts: 5      # per request on 429 / 502 / 503 / 504
                max_retry_after: 60  # seconds; a longer wait (daily limit) pauses the import until resume_after
            connect_rate_limit: { limit: 5, interval: '10 minutes' }   # token validations per tenant
            oauth:                   # H3
                enabled: false
                state_ttl: 600
        messenger:                   # new, H1 (REQ-MSG-001), any format
            enabled: false           # true: confirm and "Continue" dispatch RunCrmImport instead of looping in the request
```

No base-URL key exists: the HubSpot host is a constant (§9.3). Container validation: `hubspot.enabled` needs a secret
box (`secrets.box` or `secrets.keys` + `current_key`); `hubspot.deals` needs `pipeline.enabled`; `oauth.enabled` needs
`hubspot.enabled`. No tenant-level setting is added to `crm_settings`.

## 7. Tables and columns

All new tables are in the mapping group `doctrine-hubspot`. Binary NO PAD collation (`utf8mb4_0900_bin` /
`utf8mb4_nopad_bin`) through `CrmMetadataListener` on every `id` / `*_id` column and on the key columns, which are
added to `EXACT_COLUMNS`: `source`, `object_type`, `target_type`, `token_key_id`. Every lookup re-checks `===` in PHP.

| Table | Columns (beyond `id` string(36) UUIDv7) | Keys | Write rule |
| --- | --- | --- | --- |
| `<p>crm_hubspot_connection` | `tenant_id` (128), `kind` (16: `private_app` \| `oauth`), `status` (16: `active` \| `invalid`), `account_id` (20), `label` (80), `token_last4` (4), `token_key_id` (16), `token_sealed` (text), `scopes` (json list ≤ 40), `refresh_key_id` (16) NULL, `refresh_sealed` (text) NULL, `access_expires_at` NULL (H3), `validated_at`, `created_by_id` NULL, `created_at`, `updated_by_id` NULL, `updated_at` (micro), `version` | **unique `(tenant_id)`** | Upsert under version; disconnect deletes the row |
| `<p>crm_external_ref` | `tenant_id` (128), `source` (16: `hubspot`), `account_id` (20), `object_type` (16: `company`, `contact`, `deal`, `pipeline`, `stage`, `list`), `external_id` (100), `target_type` (16: `company`, `contact`, `deal`, `funnel`, `stage`, `tag`), `target_id` (36), `erased` (bool, default false), `created_at`, `updated_at` | **unique `(tenant_id, source, account_id, object_type, external_id)`**; idx `(tenant_id, target_type, target_id)` | Insert in the unit that creates / matches the target; a racing insert ⇒ the chunk replays once and the row becomes `updated`; moved by merge; deleted with the target on purge; on erasure kept with `erased = true` (Q-H6) |
| `<p>crm_import` (existing) | **nullable, added**: `connection_id` (36), `cursor` (json: stage, `after`, page no, owner / list / stage maps as ids only), `resume_after` (datetime), `error_code` (40) | idx `(status, resume_after)` | `cursor` advanced in the same unit that settles the page, under the claim |
| `<p>crm_import_row` (existing) | **nullable, added**: `subject_type` (16; null = the import's type) | — | HubSpot rows store `data = {"object": "contact", "id": "<HubSpot id>"}` only: no property value, no response body |

For a `hubspot` import, `crm_import.subject_type` is the first object of the scope, `file_name` is the account label
(no secret), `columns` is the scope (objects, list ids), `mapping` the `HubSpotMapping`, and `rows_total` the counts
HubSpot reported at start (progress only; the real total is `rows_done` at the end). Only nullable columns and new
tables are added (CONTRACT §17); UPGRADING carries the DDL for MySQL 8.4, MariaDB 11.4, PostgreSQL 16 and SQLite.

## 8. Encryption of the token

- **Port + default.** `CrmSecretBoxInterface` with `SodiumCrmSecretBox`: `sodium_crypto_aead_xchacha20poly1305_ietf`
  with a random 24-byte nonce per seal, additional data = `SecretContext::aad()` (purpose, tenant, connection id), so a
  ciphertext copied to another tenant or column fails to open. Keys come from `secrets.keys` (env, base64, 32 bytes,
  checked at container build), `current_key` seals, the others only open (rotation:
  `nowo:crm-kit:secrets:rotate --dry-run` re-seals rows under a lock, one unit per connection). Plaintext and key
  buffers are wiped with `sodium_memzero` after use.
- **House alignment.** A host that already runs DoctrineEncryptBundle sets `secrets.box` to the bridge
  `DoctrineEncryptCrmSecretBox`, which binds the context the way InstanceSettings' `enc:v2` sealer does (context
  inside the authenticated payload, checked on open). Beacon is expected to use the bridge (Q-H4). AuthKit's
  `#[Encrypted]` column attribute is not used: the kit's entities are plain classes with XML mapping and the context
  binding is needed.
- **Where the plaintext lives.** Only inside `HttpHubSpotReader` for the duration of one page fetch (unsealed per unit,
  passed as `#[\SensitiveParameter]`, set as an `Authorization: Bearer` header, never in a URL, never in an exception
  message, never in a property). The connect form field is a password input, never re-rendered or pre-filled.
- **What is shown.** The label, the account id, `••••` + `token_last4`, the scopes and `validated_at`.

## 9. HubSpot API: endpoints, scopes, client rules

### 9.1 Endpoints (CRM v3 / v4; to be re-checked against HubSpot's reference in the first build task)

| Purpose | Call | Slice |
| --- | --- | --- |
| Validate a private app token (account id, scopes) | `POST /oauth/v2/private-apps/get/access-token-info` `{"tokenKey": …}` | H1 |
| Account details (currency, data location) | `GET /account-info/v3/details` | H1 |
| Counts for the start step | `POST /crm/v3/objects/{type}/search` with `limit: 1` (reads `total`) | H1 |
| Properties for the mapping step | `GET /crm/v3/properties/{companies\|contacts\|deals}` | H1 / H2 |
| Records | `GET /crm/v3/objects/{type}?limit=100&after=…&properties=<mapped only>&archived=false` | H1 / H2 |
| Associations | `POST /crm/v4/associations/{from}/{to}/batch/read` (≤ 100 ids per call, per page; follows per-input paging) | H1 / H2 |
| Owners | `GET /crm/v3/owners?limit=100&after=…` | H1 |
| Lists and memberships | `POST /crm/v3/lists/search`, `GET /crm/v3/lists/{listId}/memberships?limit=250&after=…` | H1 |
| Pipelines and stages | `GET /crm/v3/pipelines/deals` | H2 |
| OAuth | `GET https://app.hubspot.com/oauth/authorize` (user agent), `POST /oauth/v1/token` (code + PKCE verifier, refresh), `GET /oauth/v1/access-tokens/{token}` (hub id, scopes), `DELETE /oauth/v1/refresh-tokens/{token}` (revoke) | H3 |

Only mapped properties are requested (data minimisation); `archived=true` objects are never read.

### 9.2 Scopes

| Slice | Required (the token must hold every scope of the chosen objects) |
| --- | --- |
| H1 | `crm.objects.companies.read`, `crm.objects.contacts.read`, `crm.objects.owners.read`, `crm.schemas.companies.read`, `crm.schemas.contacts.read`, `crm.lists.read` (only when lists are chosen) |
| H2 | + `crm.objects.deals.read`, `crm.schemas.deals.read` |
| H3 | the same, requested in the authorize URL; `oauth` |

A token holding **any non-read scope** (anything not ending in `.read`, except `oauth`) is refused with
`error.hubspot_scope_not_read_only` (Q-H2): a stolen sealed token must not be able to write to the customer's HubSpot.

### 9.3 Client rules (SSRF and leaks)

- Base URL is the constant `https://api.hubapi.com` (H3 adds the constant `https://app.hubspot.com` for the authorize
  redirect only). No configuration key, no host value from HubSpot responses or users is ever used to build a URL;
  path segments are VOs (§5.3) and are URL-encoded. `paging.next.link` is ignored; only `paging.next.after`
  (`HubSpotCursor`) is used.
- The kit builds **its own** `HttpClient` (not the framework's `http_client` service), so the profiler and the HTTP
  client logger never see the `Authorization` header. Options: `max_redirects: 0` (a 3xx is an error),
  `timeout` / `max_duration` from config, HTTPS only, `verify_peer` / `verify_host` on, `buffer` with
  `max_response_bytes` enforced while streaming.
- With OutboundUrlGuardBundle installed, the bridge `GuardedHubSpotTransport` asks
  `OutboundUrlGuard::httpClientOptions()` for the constant host and pins the resolved public address (defence in depth
  against DNS tampering pointing `api.hubapi.com` at an internal address) (Q-H5).
- Responses are decoded with `JSON_THROW_ON_ERROR` and depth 32 and mapped field by field into read models; unknown
  fields are dropped; a response that does not match the expected shape is `error.hubspot_bad_response`. Exceptions
  are mapped to bundle exceptions carrying a **code and the HTTP status only**; HubSpot's message, the body and the
  URL query are never logged, stored, shown or put in an exception.
- Logs (REQ-OBS-001): one line per page at `info` with import id, stage, page no, status and duration; never token,
  body, property values or e-mails.

## 10. Run model: stages, paging, backoff, resume

### 10.1 Stages

A run walks fixed stages; each stage pages one HubSpot collection. The cursor stores the stage, the `after` token and
the maps built so far (HubSpot owner id → actor id, list id → tag id, pipeline / stage id → funnel / stage id), ids only.

```
owners  → companies → contacts (+ contact↔company) → [H2: deals (+ deal↔company/contact)] → lists (memberships → tags) → done
```

Companies come first so that a contact's associations and a deal's company resolve against already imported
records. Owners are read once at the start (a few pages) and turned into actor ids through the e-mail lookup.

### 10.2 One page, one unit

```
for each step (up to pages_per_request in a web request, unbounded in the command / handler):
  1. read the import (no lock): status running, my claim, resume_after <= now, connection present and active
  2. HTTP, outside any transaction: fetch one page of the stage (+ its association batch read)
  3. unit (write unit, replayed on deadlock):
       SELECT import FOR UPDATE; still running, my claim, cursor unchanged since step 1? else stop
       lock existing targets in id order (refs → records, then e-mail / domain matches), then the associated companies
       per record: match (§12) → plan counter → create / update → external ref → associations / taggings of this record
       insert crm_import_row per record (outcome, record id, message code)
       UPDATE import SET cursor = next, rows_done += n, counters += …, claimed_at = now
  4. after commit: nothing per page; ImportCompleted + audit after the last unit
```

A page that fails in step 2 or 3 commits nothing; the cursor still points at it, so it is fetched again. Re-fetching
is safe because settling is an upsert keyed by the external ref.

### 10.3 Rate limits and failures

| HubSpot answer | Behaviour |
| --- | --- |
| 429 with a wait ≤ `max_retry_after` (10-second window) | In the command / handler: wait `Retry-After` (or 1, 2, 4, 8 s with jitter) and retry, up to `max_attempts`. In a web request: never sleep; stop the step and set `resume_after = now + wait` |
| 429 daily limit, or a wait > `max_retry_after` | Pause: `resume_after` = next window, claim released (the import "waits", CONTRACT §15.4), `error_code = hubspot_rate_limited` |
| 502 / 503 / 504, timeout | Same backoff; after `max_attempts` pause with `hubspot_unavailable` |
| 401 | Connection `status = invalid` (own unit), import paused with `hubspot_token_invalid`; a new token resumes it |
| 403 | Paused with `hubspot_scope_missing` |
| 3xx, oversized body, bad JSON / shape | Paused with `hubspot_bad_response` (no retry) |

The kit keeps under HubSpot's per-10-second limit by spacing its own calls in the command / handler (at most 80
requests per 10 s per connection, a token bucket in the import's cursor, so no shared state between workers is needed
for one import; two imports of the same tenant cannot run at once, see §12.4).

### 10.4 Resume

`nowo:crm-kit:import:resume` (existing) also takes `hubspot` imports whose claim is stale **or** whose `resume_after`
has passed and whose claim was released. It runs unattended: no decision needs a person in a HubSpot run (the mapping
was confirmed), so it never pauses for one. "Continue" on the import page claims a waiting import at once, as for CSV.

### 10.5 Background run (Messenger, REQ-MSG-001)

With `import.messenger.enabled`, confirm and "Continue" dispatch `RunCrmImport(importId, tenantId)` (immutable,
`#[AsMessageHandler]`, any format). The handler takes the claim like the resume command, runs up to N units, and
re-dispatches itself (with `DelayStamp` = `resume_after` when paused). Redelivery is idempotent (claim + cursor).
Transport, retries (none needed: the handler pauses instead of throwing) and the failure transport are documented in
CONFIGURATION / USAGE. Without Messenger the modal polls the progress and the resume command (cron, every minute)
carries the run; a web request settles at most `pages_per_request` pages.

## 11. Mapping of objects and fields

The mapping step lists HubSpot's properties per object (label shown escaped, internal name, type) with a target
picker: the same targets as CSV (core fields, `custom.<key>` of a compatible type, skip). Defaults below are
preselected; custom HubSpot properties default to "skip". Values go through the same validation as the form
(`RecordFields`); an empty HubSpot value never clears a CRM value on update (Q-H7).

| HubSpot | CrmKit | Rule |
| --- | --- | --- |
| company `name` | `name` | required; empty ⇒ row `invalid` |
| `domain` / `website` | `website` (+ `domain_key`) | `domain` alone becomes `https://<domain>` when it is a valid host |
| `phone`, `address`, `city`, `state`, `zip`, `industry`, `description` | `phone`, `street`, `city`, `region`, `postal_code`, `industry`, `description` | Truncation is refused (row `invalid` with the field), as in CSV |
| `country` | `country` | ISO 3166 alpha-2 as is; an English country name through `symfony/intl` when installed; else not set (row note `country_unknown`) |
| contact `firstname`, `lastname`, `email`, `phone`, `mobilephone`, `jobtitle`, address fields | `first_name`, `last_name`, `email`, `phone`, `mobile`, `job_title`, address | `email` through `EmailAddress` (invalid ⇒ row `invalid`) |
| `hubspot_owner_id` | `owner` | owner id → owner e-mail (owners stage) → `CrmActorEmailLookupInterface` → actor; unmatched ⇒ no owner on create, owner kept on update |
| contact ↔ company association | affiliation | Only to a company with a ref of the same account; HubSpot's primary label (type 1) ⇒ `is_primary` when the contact has no primary company yet; others plain; role / purposes not set |
| list (contacts or companies) | tag | Existing tag (exact `TagName::key()`) or created on confirmation (name ≤ 60, invalid names refused at mapping); membership ⇒ tagging (add only) |
| H2 pipeline | deal funnel | Existing funnel or created on confirmation with the stages in HubSpot's order |
| H2 stage | stage | `metadata.isClosed` false ⇒ `open`; closed with probability 1.0 ⇒ `won`; closed with 0.0 ⇒ `lost`; probability × 100 as the default probability |
| H2 `dealname`, `amount`, `deal_currency_code`, `closedate`, `dealstage`, `pipeline`, `hubspot_owner_id`, `closed_lost_reason` | `title`, `amount` (decimal string, `Decimal`), `currency` (ISO 4217; missing ⇒ the account's `companyCurrency`), `expected_close` (date in UTC), stage / status from the stage kind, owner, lost reason = `import.hubspot.lost_reason` with the HubSpot text in `lost_note` (≤ 500) (Q-H8) |
| H2 deal ↔ company / contact | `company_id` / `contact_id` | CrmKit keeps one of each: the primary company (type 5) else the lowest HubSpot id; the lowest contact id (Q-H9) |

0.12.0 (Beacon spec 254 FR-100 A): notes, calls, meetings and tasks are imported (USAGE "Activities: notes, calls,
meetings, tasks"). Not imported in v1: e-mail engagements (block B), tickets, files, quotes, line items,
products, `lifecyclestage` (party funnels; Q-H10), consent / subscription status, archived objects.

## 12. Matching, duplicate policy, idempotency and atomicity

### 12.1 Match order per record

1. **External ref** `(tenant, hubspot, account, object, id)`: an active target is **updated** whatever the duplicate
   policy (that is the re-run); a trashed target is left alone (`duplicate`, `error.record_trashed`); a merged target
   follows `merged_into_id`; an erased one is skipped (`duplicate`, `error.record_erased`, Q-H6).
2. **Duplicate key**, only without a ref: contacts by `email_key`; companies by `domain_key` when exactly one active
   company has it (several ⇒ `duplicate`, `error.ambiguous_domain`; none ⇒ create). Policy `update` ⇒ update it and
   write the ref; `skip` ⇒ `duplicate`, nothing written, no ref (Q-H7).
3. **Create**, counting against the plan limit (`limit` when reached), and write the ref.

A ref-matched contact whose new e-mail belongs to another contact ⇒ `invalid` (`error.email_taken`), nothing changed.

### 12.2 Idempotency

- The unique key on `crm_external_ref` makes "one HubSpot object ⇒ one CRM record" a database fact: two runners (or a
  replayed unit) that both try to create the same object lose on the insert; the chunk replays once and the row
  becomes `updated`.
- Taggings and affiliations are set-insert (existing unique keys), so a re-run adds nothing twice.
- Funnels, stages and tags created on confirmation get their refs in the claim unit; a second confirm loses the claim.
- `rows_done` and the counters move only in the unit that settles the page, together with the cursor.

### 12.3 Atomicity ("no half association")

- A record, its ref, its associations and its taggings are written in **one unit**, so a failure leaves either all or
  nothing of that record. An association is written only when both sides exist (the other side by ref, locked in id
  order); otherwise it is counted (`associations_skipped` in the cursor's counts) and not written.
- Units never span pages, so a cancel, a crash or a pause between two units leaves only complete records with
  complete associations; the import page shows how far it got. Cancel = `status cancelled` under the claim; nothing
  is rolled back across units (the same rule as CSV).
- Deals (H2): the deal, its ref, its stage / funnel ids and its company / contact ids in one unit; a funnel or stage
  that disappeared since confirmation ⇒ `invalid`, never a deal pointing at a missing stage.

### 12.4 Concurrency (REQ-RUNTIME-003)

| Write | Control |
| --- | --- |
| connect / replace | unique `(tenant_id)` + `version`; a racing first connect loses on the insert ⇒ 409 `crm_record_stale` |
| disconnect during a run | the run re-reads the connection at each step and stops with `hubspot_disconnected`; the connection row is not locked by runs |
| two HubSpot imports of a tenant | refused at `start()` while one is `preview` / `running` (`crm_import_running`), checked under a lock on the connection row |
| claim, cursor, counters | as CSV (§15.4 of CONTRACT), plus the cursor compare-and-set |
| user edit vs run | records locked `FOR UPDATE` in id order inside the unit; the user's next save meets a new `version` (409 stale) |
| merge vs run | merge moves refs in its own unit under the same record locks |

Race tests on MySQL 8.4, MariaDB 11.4 and PostgreSQL 16: double confirm; two runners on one import; re-run against a
user editing the same contact; merge during a run; disconnect during a run; racing first connect.

## 13. UI and routes

Opt-in with `ui.enabled` and `import.hubspot.enabled`. Every page is a step fragment of the existing import modal,
loaded on demand through the modal protocol (`ImportDialog`, `X-Nowo-Crm-Dialog: import`), and the same URL works as a
full page without JavaScript (D-20261123-01): the source picker renders only a link per source, never a hidden form.

| Route (under `ui.path`) | Methods | Permission | Content |
| --- | --- | --- | --- |
| `/import` (existing) | GET | `import` | Source picker: CSV, vCard, **HubSpot** (only when enabled and granted) |
| `/import/hubspot` | GET | `import` | Connection card (label, account id, `••••1234`, scopes, validated at) with Replace / Disconnect / Start, or "Connect" |
| `/import/hubspot/connect` | GET, POST | `import` + `manage` | Password field (no autocomplete, never re-rendered), optional label; 422 in place; account change asks for confirmation |
| `/import/hubspot/disconnect` | POST (UiKit confirm, action token) | `import` + `manage` | Deletes the sealed token |
| `/import/hubspot/new` | GET, POST | `import` | Objects (with HubSpot's counts), lists, duplicate policy ⇒ creates the import in preview |
| `/import/{id}` (existing) | GET, POST | `import` | For `hubspot`: mapping per object (+ pipelines / stages in H2, lists → tags), confirm; then the progress (rows, counts, waiting until, error code) |
| (H3) `/import/hubspot/oauth/start`, `/import/hubspot/oauth/callback` | GET | `import` + `manage` | State (signed, single use, `state_ttl`, bound to tenant + actor + session) + PKCE S256 |

Every denial on these routes answers **404** (tenant, feature, actor, `import`, HubSpot off), not 403: no profile learns
that the source exists (FR-001). POSTs carry CSRF tokens; write controllers run in write units; error texts are
translation keys (7 locales), never HubSpot text.

## 14. Threat model

| # | Threat | Mitigation | Test |
| --- | --- | --- | --- |
| T1 | Token read from a DB dump or backup | AEAD seal, key outside the DB (env), context-bound AAD; only `token_last4` in clear | Dump grep: fixture token absent; AAD swap fails to open |
| T2 | Token in logs, profiler, exceptions, Sentry | Own HttpClient (not traced), header never in URL, `#[\SensitiveParameter]`, exceptions with code + status only | `TestLogger` + exception messages scanned for the fixture token and body markers |
| T3 | SSRF through the HubSpot integration | Constant base URL, VO path segments, `next.link` ignored, no redirects, optional DNS pin (OutboundUrlGuard) | Fake answers a 302 and a `next.link` to `169.254.169.254`: neither followed |
| T4 | Another tenant's connection or records | Tenant on every row and key, `===` re-checks, refs keyed by tenant + account, 404 across tenants | Two-tenant functional and worker tests |
| T5 | A profile without the import permission | `canImport` through the host port, 404 on every HubSpot route, link not rendered | One denial test per route and profile |
| T6 | Write-capable or foreign token | Validation before save: account id, required scopes, read-only scopes only; account change needs confirmation | Fake tokens with write scopes, missing scopes, revoked |
| T7 | Token-validation oracle / abuse | `connect_rate_limit` per tenant, generic error keys | Sixth attempt refused |
| T8 | Hostile HubSpot data (XSS, CSV injection, huge values, control characters) | Escaped rendering, form validation, length limits refused not truncated, CSV export already neutralises formulas, JSON depth / size caps | Fixtures with `<script>`, `=cmd`, 10 MB body, ZWSP ids |
| T9 | Resource exhaustion (memory, time, rate) | 100 per page, EM cleared per unit, `max_records`, timeouts, backoff, no sleep in web requests | Memory flat over 200 pages; 429 storm test |
| T10 | Half-written state | One unit per page with cursor CAS; associations only with both sides | Failure injected mid-page; cancel between pages |
| T11 | Duplicates on re-run or race | Unique ref key, chunk replay | Second run creates 0; two runners |
| T12 | GDPR: erased person re-imported, over-collection | Erased refs kept as tombstones (Q-H6), only mapped properties requested, rows hold ids only, import rows purged after `import_keep_days` | Erase then re-run ⇒ skipped |
| T13 | OAuth CSRF / code interception (H3) | Signed single-use state bound to tenant + actor + session, PKCE S256, exact redirect URI, refresh token sealed, revoke on disconnect | State replay, other session, missing verifier |
| T14 | Key loss or rotation | Key ids, rotation command, unseal failure ⇒ connection `invalid` (asks for a new token), never a crash loop | Rotation and wrong-key tests |

## 15. Questions for the owner (Q-H)

| # | Question | Recommended answer |
| --- | --- | --- |
| Q-H1 | Funnels already exist (0.3.0). Ship deals with H1 or as their own minor? | **Own minor (H2 = 0.10.0) right after H1**: smaller PRs and audits; no feature waits on another phase |
| Q-H2 | Refuse a token that also holds write scopes? | **Yes**: only `*.read` (+ `oauth`) scopes are accepted |
| Q-H3 | Who connects / replaces / disconnects? | **`import` + `manage`** (a CRM manager); running an import needs `import` only |
| Q-H4 | Which encryption in Beacon? | **DoctrineEncrypt bridge** (house standard, key already managed); the built-in sodium box stays the default for hosts without DoctrineEncrypt |
| Q-H5 | Use OutboundUrlGuard for the constant host? | **Optional bridge, recommended in Beacon**: the URL is constant, so the guard only adds the DNS pin |
| Q-H6 | After a GDPR erasure, keep the HubSpot id so a re-run does not bring the person back? | **Yes**: keep the ref as a tombstone (`erased = true`, no personal data) and skip it on every re-run; document it in the GDPR section |
| Q-H7 | Duplicate policy on records without a ref: `skip` writes nothing; `update` updates and links the ref. Empty HubSpot values never clear CRM values. Associations and tags only add. OK? | **Yes** to all three |
| Q-H8 | HubSpot's lost reason is free text; CrmKit needs a configured key. | Configured key `import.hubspot.lost_reason` (default: the first `pipeline.lost_reasons` key) + HubSpot text in `lost_note` (≤ 500) |
| Q-H9 | A HubSpot deal can have several companies / contacts; CrmKit keeps one of each. | **Primary company, else the lowest id; lowest contact id**; the others are counted as skipped associations |
| Q-H10 | Map `lifecyclestage` to the party lifecycle funnel? | **Not in v1**; a later slice if asked |
| Q-H11 | Background runs: add Messenger now (any format) or keep "Continue" + the resume command? | **Add the optional Messenger handler in H1** (`import.messenger.enabled`, off by default); Beacon turns it on |
| Q-H12 | Account name: private app token info gives the account id only. | Label = the optional name the manager types, else "HubSpot <account id>"; H3 uses OAuth's hub domain |
| Q-H13 | EU-hosted HubSpot accounts: is `api.hubapi.com` enough? | **Yes for v1** (HubSpot routes it); if a customer needs the EU host, add it as a second constant, never a config value |

## 16. Test plan

- **No real token ever** (secrets rule): fixtures build fake tokens at runtime (`pat-test-` + random hex) and no
  fixture file contains anything shaped like a HubSpot token; the secret scanner of the CI keeps the
  `pat-(na|eu)[0-9]-` pattern; the demo never asks for a real account.
- **Fake HubSpot.** `tests/Fixtures/HubSpot/FakeHubSpot` (a `MockHttpClient` callback over an in-memory account:
  companies, contacts, deals, owners, lists, pipelines, associations, scopes) with switches for paging, 429 with and
  without `Retry-After`, the daily limit, 5xx, 401, 403, 302, oversized and malformed bodies, `next.link` to internal
  addresses, look-alike ids. The demo and e2e use the same fake as a tiny PHP server container
  (`demo/fake-hubspot/`), wired by decorating the kit's HubSpot client service in the demo's `when@dev` / `when@test`
  config (no base-URL option exists in the bundle).
- **Unit:** VOs (regexes with `/D`, ZWSP, trailing newline), mapper per field, stage-kind rules, secret box (seal,
  open, AAD mismatch, wrong key, rotation), backoff schedule, cursor transitions.
- **Functional (per host kind: standard, DDD, single-EM Flex):** connect / replace / disconnect, every denial per
  route and profile (guest, member, CRM user, CRM manager, admin, other tenant) answering 404, CSRF, rate-limited
  connect, start → mapping → confirm → run → done, re-run creates 0 and updates the changed ones, erase then re-run,
  plan limit, cancel, pause / resume after 429 and 401, deals (H2) with funnel / stage creation on confirmation.
- **Leak tests:** captured logs, exception messages, rendered pages, `crm_import_row.data` and a full table dump
  contain neither the fixture token nor any fixture property value outside the record tables.
- **Concurrency:** the race tests of §12.4 with two connections on MySQL 8.4, MariaDB 11.4, PostgreSQL 16.
- **Worker (REQ-RUNTIME-002):** one kept-alive kernel alternating two tenants through connect and run pages; no
  crossed data, no token left in any service, flat memory.
- **Scale (slow group):** fake account with 5 000 companies, 20 000 contacts, 2 000 deals: second run creates 0
  (SC-001); query budget per page; memory flat per page (SC-003).
- **Messenger:** handler success, redelivery, pause re-dispatch with `DelayStamp`.
- 100 % PHP coverage (REQ-TEST-003); Vitest for any modal script change.

## 17. Beacon side (block B, for reference)

Beacon only wires: `import.hubspot.enabled: true`, `secrets.box` → DoctrineEncrypt bridge, its `crm.import`
permission in the host area checker (`canImport`), the CRM-manager mapping for `manage`, the
`CrmActorEmailLookupInterface` adapter over organization members, Messenger transport for `RunCrmImport`, the
migration generated from the kit's mapping (exact-key collations come from the kit's listener), the manual section
and e2e against the fake HubSpot. Nothing in Beacon talks to HubSpot directly.
