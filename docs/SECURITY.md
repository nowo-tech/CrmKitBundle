# Security

## Table of contents

- [Status and scope](#status-and-scope)
- [Security model](#security-model)
- [Permission matrix](#permission-matrix)
- [Tenant isolation and exact identifiers](#tenant-isolation-and-exact-identifiers)
- [Forms, CSRF and output](#forms-csrf-and-output)
- [Content-Security-Policy](#content-security-policy)
- [Imports and exports](#imports-and-exports)
- [HubSpot credential](#hubspot-credential)
- [HubSpot import (0.10.0)](#hubspot-import-0100)
- [HubSpot activities import (0.12.0)](#hubspot-activities-import-0120)
- [GDPR](#gdpr)
- [Host extension points](#host-extension-points)
- [Shared entity manager](#shared-entity-manager)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)
- [Reporting](#reporting)
- [Activities and mentions (0.11.0)](#activities-and-mentions-0110)
- [E-mail templates and Write e-mail (0.13.0)](#e-mail-templates-and-write-e-mail-0130)

## Status and scope

0.1.0 ships phase A (companies and contacts); 0.2.0 adds party roles, the company's primary contact and affiliation
purposes (contract section 6.8) with the same model: no new permission, deny by default. The security model is contract sections 5 (value objects), 6.1
(exact identifiers), 11.3 (errors), 13 (permission matrix), 15 (concurrency, [CONCURRENCY.md](CONCURRENCY.md)) and 16
(GDPR).

| Input | Handling |
| --- | --- |
| Configuration | Strict tree (unknown keys refused); `security.*_roles` default to `ROLE_ADMIN` (REQ-UI-002); `allow_unauthenticated` false; later phases refuse `enabled: true` |
| Every service call | Tenant, feature gate, actor and permission checked in the application services, not only the controllers |
| Identifiers | Ids are canonical lowercase UUIDs; host references are 1–128 bytes, never trimmed or folded, compared with `===`; binary NO PAD collations |
| Form input | Trimmed, length- and kind-checked (e-mail, http(s) URL, phone, ISO country), control and format characters refused |
| Concurrent writes | One write unit per write; stale versions and lock conflicts answer 409 and store nothing ([CONCURRENCY.md](CONCURRENCY.md)) |
| Instants | Stored in UTC whatever the PHP default time zone (`nowo_crm_utc_datetime` DBAL type) |

## Security model

- **Admin UI exposure (REQ-UI-002).** The pages are opt-in (`ui.enabled`). Two layers: the host's firewall
  (`access_control` on the CRM's path, [CONFIGURATION.md](CONFIGURATION.md#security-req-ui-002)) and the bundle's
  checks: a `kernel.controller` listener requires `view` on every bundle route (nobody signed in ⇒ the firewall's
  entry point), each action asks its own permission through `CrmKitGuard` and the services ask again.
  **`security.allow_unauthenticated: true` is a footgun**: it opens the pages to anonymous visitors (actor
  `anonymous`, shared by everybody: a real user with that identifier would be the same actor, and resource scoping is
  turned off), and without SecurityBundle every area; demos only: it is refused when `kernel.environment` is `prod`.
  Without SecurityBundle and without it, the container build fails.
- **Deny by default.** The default area checker grants an area only to the roles of its `security.*_roles` list
  (`ROLE_ADMIN` by default); with `resource_scoping` a record is open by default only to its owner (or when it has
  none) and to `settings_roles`, and a `nowo_crm_kit.record_access_check` listener's deny always wins; without
  SecurityBundle it grants nothing at all. A host checker must return `false` for anything it does not grant.
- **Order of the checks, per call:** tenant (none ⇒ 404) → feature gate (off ⇒ 404) → actor (none ⇒ 404) →
  record lookup in the tenant (absent or another tenant's ⇒ 404) → permission (refused ⇒ 403). A user of another
  tenant can never learn whether an id exists: it is always 404, never 403.
- **Exactly the permission of the action.** `edit` and `manage` do not imply `view`; a record-level check passes the
  record (type, id, owner) so a host can grant "own records only".
- **No state between calls.** Tenant, actor and permissions are asked per call (FrankenPHP worker mode,
  [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md)).

## Permission matrix

| Action | Permission | Area (default `security.*_roles`) |
| --- | --- | --- |
| Lists, 360° pages, timeline, notes, links, panels, consents, duplicates, vCard of one contact, header search | `view` | `canAccess` (`access_roles`) |
| Create companies and contacts | `create` | `canCreate` (`manage_roles`) |
| Edit records, notes, tag assignment, roles, host links, affiliations with their purposes, consent records, merge (on both records), host timeline entries | `edit` | `canEdit` (`manage_roles`) |
| The company's primary contact: `edit` on the company (the contact needs `view`; an import row that changes it also needs `edit` on the company, else `invalid`) | `edit` | `canEdit` |
| Trash / restore | `delete` | `canDelete` (`manage_roles`) |
| Import | `import` | `canImport` (`manage_roles`) |
| CSV export of lists, GDPR export | `export` | `canExport` (`export_roles`) |
| Tag and field administration, settings, purge from the trash, another person's note | `manage` | `canManageSettings` (`settings_roles`) |
| GDPR erasure | `erase` | `canErase` (`erase_roles`) |
| (later phases) Amounts and totals / sending circulars | `amounts_view` / `campaigns_send` | `canViewAmounts` (`amounts_roles`) / `canSendCampaigns` (`campaigns_roles`) |

Every area also needs `canAccess`. A check on one record then goes through `CrmResourceAccessChecker` and the
`nowo_crm_kit.record_access_check` event (a listener's deny wins). With `security.resource_scoping`, every read that
returns several records (lists and totals, search, duplicates, CSV exports, related records, `subjectsLinkedTo()`)
is filtered in SQL to the same visible owners as the record decision (`nowo_crm_kit.record_visibility`). Lists follow
only `RECORD_VISIBILITY` (owner-based); `RECORD_ACCESS_CHECK` decides single-record pages and writes, so a record is
hidden from lists through its owner. The GDPR export lists every company the contact is affiliated to. A taken e-mail
names the other contact only to someone who may see it. Imports
need `edit` on each existing contact they update and `view` on each company they name; a merge needs `delete` on the
merged record. An empty role list means any authenticated user for that area. Notes: the author edits and deletes their own notes with `edit` on the
record; another person's note needs `edit` on the record and `manage`.

## Tenant isolation and exact identifiers

- Every table has `tenant_id`; every query filters by it and every row returned is re-checked in PHP with `===`
  (tenant, and owner when a list filters by owner) before it is used (REQ-SEC-006 rule 2).
- Every `id` / `*_id` column and the key columns (`email_key`, `name_key`, `tax_id_key`, `domain_key`, `sort_key`,
  `field_key`, `purpose`, `purpose_keys`, `role_key`, `host_type`, `claim_token`, `resource`) use `utf8mb4_0900_bin` (MySQL 8) or
  `utf8mb4_nopad_bin` (MariaDB): `bob` ≠ `Bob`, `bob ` ≠ `bob`, and NBSP or ZWSP look-alikes never match.
- `TenantRef`, `ActorRef` and host ids refuse invalid UTF-8, control and format characters (ZWSP, BOM, bidi marks),
  line / paragraph separators and edge whitespace or NBSP; they are never trimmed, truncated or case folded.
- Record, note, tag, field, link, affiliation and import ids must be canonical lowercase UUIDs, otherwise the call is
  404 before any query.
- Role and affiliation-purpose keys (0.2.0) match `^[a-z][a-z0-9_]{0,31}$` with `/D` (no trailing newline) in the
  configuration, and every input key must equal a configured key byte for byte (never trimmed in the service, never
  case folded); import cells are only trimmed of the separator's spaces. A key in a list URL that is not configured is
  ignored. The purpose filter is an exact `LIKE` on `,<key>,` with `%`, `_` and `!` escaped.
- Host object resolvers must return exactly the requested ids; a view whose id differs byte for byte is dropped.
- The actor directory must return exactly the requested actor; a profile for another ref is ignored.

## Forms, CSRF and output

- Every form is a FormKit form under the `crm_kit` profile with Symfony's CSRF token; every POST action (trash,
  restore, purge, notes, tags, links, affiliations, consent, erasure, import run / cancel, administration) checks it.
- Every value is rendered through Twig autoescaping. **Notes are plain text**: stored as typed (up to 10 000
  characters, control characters other than line breaks and tabs refused) and shown escaped with line breaks; no raw
  HTML is ever rendered from notes, record fields, custom values, panel items or timeline parameters.
- Panel item links are rendered only for local paths and http(s) URLs, the panel's "more" link only for local paths;
  host timeline URLs must be local paths (`/`, not `//`).
- The website field accepts only http(s) URLs; e-mail links are `mailto:` of a validated address.

## Content-Security-Policy

For integrators with a strict CSP (REQ-UX-001). The kit's only browser script is `nowo-crm-import.js`, the import
modal of the lists. It is a self-hosted file served by `assets:install` through the `nowo_crm_kit` asset package and
loaded with `<script src defer>`, so `script-src 'self'` is enough: no inline script, no inline event handler, no
`eval` or `new Function`, no third-party origin. It sets no inline style and assigns no HTML strings: step fragments
are parsed with `DOMParser`, cleaned (only same-origin URL attributes are kept) and moved as nodes, and every other
string goes through `textContent`. Its `fetch` calls go only to the kit's same-origin import routes (`connect-src
'self'`). On close it aborts its pending request and removes its listeners. It exposes one global, `NowoCrmImport`
(the IIFE's exports), and logs one "script loaded" line (USAGE, "Browser console"). The kit's pages use UiKit's
stylesheet and `nowo-crm-kit.css`, both self-hosted (`style-src 'self'`).

## Imports and exports

- Uploads are parsed in memory before any transaction: UTF-8 only, at most `import.max_bytes` bytes and
  `import.max_rows` rows; the file itself is not stored, only its parsed rows (`crm_import_row`), which the retention
  purge deletes `import_keep_days` after the import finished.
- CSV cells are read as text: nothing is evaluated; every value goes through the same validation as the form.
- **CSV injection**: the CSV export prefixes every cell that starts with `=`, `+`, `-`, `@`, a tab or a carriage return
  with `'` and quotes every cell (RFC 4180). The export needs `manage` (bulk personal data).
- vCard output escapes `\`, `,`, `;` and line breaks and drops NUL bytes.
- Downloads (CSV, vCard, GDPR JSON) are sent with `Cache-Control: no-store, private`.

## HubSpot credential

- One credential per tenant (a HubSpot service key or a legacy private app token), connected, replaced or removed
  only with `import` + `manage`; every HubSpot route answers **404** on any denial (tenant, feature, actor,
  permission), and the routes do not exist while `import.hubspot.enabled` is false.
- **At rest**: sealed by `CrmSecretBoxInterface` with authenticated encryption bound to `nowo-crm-kit|v1|hubspot.token|
  <tenant>|<connection id>` (the additional data): a copied value does not open for another tenant, row or purpose.
  Default `SodiumCrmSecretBox` (XChaCha20-Poly1305, random 24-byte nonce, keys from the environment, key ids for
  rotation); house option `DoctrineEncryptCrmSecretBox` (context inside the authenticated payload; only the Halite and
  Defuse encryptors, checked at container build for the profiles the kit wires (0.10.1), another class only with an
  explicit opt-in, MysqlAes never). Only the last four characters are stored in clear. A value that does not open
  marks the connection invalid, only while the connection still holds that sealed value (a token replaced meanwhile
  stays active). A key that is not available (a key id no longer configured, an unknown DoctrineEncrypt profile, a
  missing, malformed or wrong-length key, which the DoctrineEncrypt box loads in its own step) is never taken for a bad
  value (0.10.1; a wrong but well-formed key is: change the key id with the key): the import refuses with `hubspot_key_unavailable` and the
  sealed token is kept. Rotation (`nowo:crm-kit:secrets:rotate`, both boxes since 0.10.1) writes a new seal only after
  it opened again to the same token, and leaves every token it cannot re-seal unchanged. The sodium keys come from the
  environment only: they are never container parameters (only the box's own service definition receives them).
- **In use**: unsealed for one HubSpot call, sent only as `Authorization: Bearer` to `https://api.hubapi.com` by the
  kit's own HttpClient (not the framework's `http_client`, so no profiler or HTTP-client log sees it). It is never put
  in a URL, a property, a log, an exception, an audit entry or a page; the connect field is a password input, never
  pre-filled or re-rendered. `HubSpotToken` keeps its plaintext out of its own properties (in the local frame of a
  suspended generator that only the object holds, collected with it), so `var_dump()`, `print_r()`, Symfony's
  VarDumper (`dump()`, the profiler, Twig's `dump`), `(array)` casts and traces show no value; it refuses cloning and
  serialisation; its parameters are `#[\SensitiveParameter]`. Still, never paste a production credential into a page
  served with the Symfony profiler or the debug toolbar on (the request's form data is collected there).
- **Scopes**: read scopes only. Write scopes are refused when HubSpot lists the credential's scopes (private app
  tokens); the check fails closed: an entry the kit cannot read, or one past the 200th, counts as a write scope, and
  only HubSpot's "unknown token" answer (400 / 404) leads to probing, any other failure refuses the credential. For a
  service key HubSpot offers no scope listing, so the required read scopes are probed (a 403 is a missing scope) and
  write scopes cannot be seen: the connection card says "write scopes not verified" and the manager must tick read
  scopes only (known limit, documented in USAGE). Sensitive and highly sensitive properties are never offered or requested.
- **Checks**: counted per tenant (`connect_rate_limit`), valid or not; answers are translation keys, never HubSpot's
  text. The HubSpot reads of the start and mapping pages are counted per tenant too (`browse_rate_limit`, 0.10.1), so
  reloads cannot burn the portal's shared daily API quota.

## HubSpot import (0.10.0)

- **SSRF**: one constant host; paths built from enums and validated ids, URL-encoded; `paging.next.link` ignored (only
  the `after` token, validated); no redirects (`max_redirects: 0`, a 3xx is an error); peer and host verification on;
  idle and total timeouts on every call (`http.timeout`, `http.max_duration`; a stalled HubSpot cannot pin a PHP
  worker, and the PHP, proxy and `max_wait_time` limits are layered above them, CONFIGURATION "Timeouts"); bodies
  capped at `max_response_bytes` while streaming; JSON depth 32; unknown fields dropped.
  Optional OutboundUrlGuard bridge: pins the resolved public address (private addresses never allowed, DNS always
  resolved).
- **Data**: only the mapped properties are requested; import rows keep the object kind and the HubSpot id only;
  values go through the form's validation (too long ⇒ refused, never truncated); labels and list names are shown
  escaped; logs (one `info` line per page) carry the import id, stage, page, result and duration only.
- **GDPR**: an erased contact's references become tombstones (`erased`, no personal data), so a HubSpot re-run of the
  same account does not bring that HubSpot contact back (Q-H6). Another HubSpot account, a new HubSpot contact for the
  same person, a CSV / vCard import or a manual entry can still create a record; a purge deletes the references of
  purged records (tombstones stay).
- **Authorisation** (audit ZAM-1): the confirm needs `import` and `edit` (whatever the policy: a re-run updates the
  records an earlier run linked); the person who confirms is stored (`confirmed_by_id`) and unattended pages (the
  resume command, the Messenger handler) run as that person. When a person runs the pages ("Continue"), every
  existing record needs `edit` on it, every company of an affiliation `view` and every list member `edit` (else no tag).
  Unattended, nobody's access can be asked, so under `security.resource_scoping` a page that would write a record
  (update, affiliation, tag) owned by someone else than the confirming person rolls back and the import waits for a
  person's "Continue" (`hubspot_needs_person`), as CSV imports do (UP-M-2 / UP2-1); ownerless and own records are
  written. An unattended run never changes the owner of an existing record. Hosts that scope records in their own
  access checker set `security.resource_scoping: true` to get this rule.

## HubSpot activities import (0.12.0)

Notes, calls, meetings and tasks (Beacon spec 254 T004; audit ZDE, PASS with fixes).

- **Scopes and data**: no new scope (`crm.objects.contacts.read`, read-only); each kind asks for a fixed list of
  properties (USAGE); association paging is not followed and at most 20 associated ids of each kind are kept per item
  (ZDE-L1); engagement pages hold at most 25 items (ZDE-L2). Import rows hold the kind and the HubSpot id only.
- **Output**: HTML bodies become plain text (scripts and styles dropped, tags stripped, entities decoded, control and
  format characters removed; past PCRE's backtracking limit only the tags are stripped, ZDE-I1) and are rendered
  escaped; a meeting place is text or an https link (`MeetingPlace`, `javascript:` stays text).
- **Tenant and account**: an association resolves to a record only through a reference of the same tenant and HubSpot
  account; references, dedupe keys and every lookup carry the tenant.
- **Authorisation** (ZDE-M1, as the records import, ZAM-1): the primary record is the first associated record the run
  may write on: `edit` when a person runs the page, the owner rule unattended under `security.resource_scoping` (none
  ⇒ the row is `error.forbidden` attended, the page waits for a person unattended, `hubspot_needs_person`). Every other
  associated record (an activity's subject rows, a meeting's attendees) needs `view` attended and the owner rule
  unattended; any other is left out and counted in `associations_skipped`.
- **GDPR** (ZDE-M2, Q-H6): an item associated with a contact whose reference is a tombstone is skipped
  (`error.record_erased`), on every record it was associated with (0.12.1, ZDE-v-1: every contact id HubSpot gives
  inline is checked, not only the 20 used as records; association paging is not followed); erasing a contact also turns the references of its
  deleted notes, activities and next actions into tombstones, so a re-run never recreates them, even when HubSpot no
  longer associates them with the person.
- **Re-runs** (ZDE-I4): HubSpot wins: a non-empty HubSpot value overwrites a CRM-side edit of an imported note, call,
  meeting or next action (as for records); an item a person deleted is made again (ZDE-I3: its reference is kept, with
  HubSpot ids only).

## GDPR

The tenant is the controller; the bundle is the tool.

- **Consent** per contact and purpose (`gdpr.purposes`), append-only, with source, evidence, actor and time; the
  current state is the latest row per purpose.
- **Export** (`manage`): one JSON document with the contact's fields and custom values, every stored party role
  (also roles no longer configured), companies with the affiliation purposes and flags, tags, notes, consent
  history, timeline entries, host links (type + id) and merges where it was the merged record. The export itself is
  audited (`crm.contact.exported`) and leaves an `exported` timeline entry.
- **Erasure** (`manage`, idempotent): under the contact's row lock, the name becomes "Erased contact", every other
  personal column, the custom values and the owner are emptied, the e-mail key is released, the status becomes
  `erased`; notes, taggings, role assignments, host links, affiliations, timeline entries and merge snapshots of the
  contact are deleted; consent rows are kept **without their evidence** as proof that consent was given or withdrawn; the plan
  counter is released when the contact was active. An erased contact cannot be edited, restored or merged.
- **HubSpot tombstones need the import switched on** (0.12.1, audit ZDE-v-2). An erasure turns the contact's HubSpot
  references (and those of its imported notes, activities and next actions) into tombstones only while
  `import.hubspot.enabled` is on: the reference table is mapped, and its hooks registered, only then, and the kit does
  not write to a table it has not mapped. A contact erased while the import is off keeps plain references; if the
  import is switched on again later, a HubSpot re-run of the same account can bring the person and their activities
  back. Hosts that ever imported from HubSpot keep `import.hubspot.enabled: true` (no import runs unless someone starts
  one), or erase such a contact again after switching it back on (the erasure is idempotent and tombstones the
  references then).
- **Retention** (`nowo:crm-kit:purge`): trashed records after `trash_days` with every child row, timeline entries
  after `timeline_years`, import rows after `import_keep_days`, merge snapshots after `merge_keep_days`; one audit
  entry per tenant with counts only. **(0.2.1)** Every committed batch of deleted records (trashed records and merge
  tombstones) is announced after its commit with `RecordsPurged` (tenant and the records' type + id only), so a host
  that derived rows from CRM ids can delete them; a manual `purge()` announces `RecordPurged` per record, an erasure
  `ContactErased`. `CrmBatchLookupInterface::existing()` lets a host reconcile ids it missed.
- **Audit and logs** carry ids, counts and codes only, never names, e-mails or note text: role edits are audited as
  `crm.roles.changed` with the added and removed keys, the company's primary contact as
  `crm.affiliation.primary_contact` with the contact id, affiliation saves with the flags and purpose keys, after the
  commit. A failing panel provider is
  logged with class names only.

## Host extension points

- Panel providers and host object resolvers run with the tenant and the actor of the call; they must apply the host's
  own permissions and return only what the actor may see.
- A host link URL returned by a resolver is rendered as given (escaped): return local paths or http(s) URLs only.
- A provider exception is contained (the panel is skipped); a transient storage failure is rethrown so the write unit
  can replay.
- **`CrmActorPersonalDataInterface` (`exportActor()` / `anonymiseActor()`) is a system API (0.2.1)** without any
  tenant scope: it reads or rewrites a host person's references in every tenant and checks no permission. It is a
  separate service so that a host GDPR controller bound to the tenant-scoped `CrmPersonalDataInterface` cannot reach
  it. Call it only from trusted server-side code; never expose it to end users or agent tools (MCP).
- **The placeholder `anonymised` is reserved** (`ActorRef::ANONYMISED`): `CrmAccess` treats it as no actor whatever
  the checker returns, the default checker and directory never derive it (nor `anonymous`) for a signed-in person,
  `RecordService` refuses it as an owner, `CrmAccess::visibleOwners()` drops it whatever the visibility service (the
  bundle's or a host's) returns, it is never sent to the host resolver or directory for a name, and an import whose
  uploader was anonymised resumes as the system actor, so nobody can inherit the records of anonymised people (audits
  VD-1, VD2).
- **Actor ids.** The actor id stored everywhere comes from `CrmActorResolverInterface::idOf()` (default the
  identifier). A host whose identifiers can change or be reused (e-mails) should return a stable id, or a former
  address's new holder inherits the old owner's records under `resource_scoping`.
- **`CrmBatchLookupInterface` is a system API (0.2.1).** It is scoped to the `TenantRef` it is given (every query names
  the tenant, every row is re-checked with `===`), but it resolves no tenant, actor or feature gate and checks no
  permission and no record visibility (`security.resource_scoping` does not apply). Only trusted server-side code
  (queue consumers, scheduled jobs) may call it, with a tenant taken from its own trusted data. A host must not expose
  it, or show its results, to end users without its own permission checks; per-user reads go through
  `CrmReaderInterface` and `HostLinkServiceInterface`, which check everything.

## Shared entity manager

The same applies when `doctrine.entity_manager` names the registry's default manager (for example `default`).

With the private entity manager (default) the bundle clears it after each unit of work. With the host's default entity
manager the bundle never flushes or clears the host's work: before a write it refuses (`LogicException`) when the host
has pending inserts, updates or deletions, and afterwards it detaches only the bundle's own entities. A write that
fails closes the shared entity manager, as Doctrine does for any failed flush; the bundle resets it through the
registry before its next unit.

## Release security checklist (12.4.1)

Before each 0.x tag and 1.0.0 (REQ-SEC-004):

1. An independent AI security audit of the phase is recorded with a passing grade; every blocking finding is fixed and
   checked again. Permissions, GDPR erasure and merge (data-loss paths) are audited before the tag.
2. Permission tests cover every route × profile (no tenant, feature off, no actor, view, edit, manage, another
   tenant's id) with the expected 404 / 403 / 200 / 303 (contract section 21).
3. The export ↔ erasure parity test passes (`GdprTest::testExportThenEraseLeavesNothingPersonal`: everything the
   export returns is gone or blank after the erasure).
4. Collation tests on MySQL 8.4 and MariaDB 11.4 show that `bob` vs `Bob`, `bob ` and NBSP / ZWSP look-alikes never
   match (contract section 21).
5. Igor and the phpstan-frankenphp rules pass; the worker test is green.

## Reporting

Report vulnerabilities privately through GitHub Security Advisories of `nowo-tech/CrmKitBundle` or by e-mail to
**hectorfranco@nowo.tech**. Please do not open a public issue.

## Activities and mentions (0.11.0)

- An activity is shown (feed, calendar, search, `.ics`) only while the viewer may view its primary record; fan-out
  rows are filtered the same way.
- Mention candidates: members of the same tenant whose name contains the query or whose e-mail is exactly the query,
  limited to those `CrmRecordAudienceInterface` lets view the record, at most 10. Both mention endpoints (candidates,
  names) count against `activities.mentions.rate_limit` per person (429 with `Retry-After`). The names endpoint
  resolves handles of any member of the tenant for anyone with `view` on the record: a handle only appears in notes
  that person can read, and the page shows the same names.
- Handles are HMACs of the tenant and the actor id with `kernel.secret`; anonymising a member rewrites their handles
  in note text. Rotating the secret leaves old handles as plain text.
- A host with `nowo_crm_kit.record_access_check` listeners implements `activities.audience`; without it the container
  build logs a warning.
- Mention notifications carry no record name and no note text.

## E-mail templates and Write e-mail (0.13.0)

- **Closed placeholders, no evaluation.** Templates are MailKit's rows; `{{ ns.name }}` is pure substitution by
  MailKit's registry (never Twig, never re-expanded, unknown placeholders stay as text). The CRM's providers return
  plain text; MailKit escapes it in HTML bodies and refuses placeholders in link and image addresses. The CRM's form and
  *Write e-mail* handle text only: the sent message is `text/plain`.
- **Actor binding.** MailKit takes a caller-passed `TemplateActor(tenant, owner)`. The CRM builds it from its own gate
  and its providers and permission port accept only an actor that is exactly the call's CRM tenant and person (another
  tenant or person resolves nothing and is refused), so a host bug passing another actor cannot read CRM data.
- **What a placeholder shows.** Only records the person may view (tenant, `view`, the host's
  `nowo_crm_kit.record_access_check` decisions), only active ones, only the core fields the record page shows; deal
  amounts only with `amounts_view`; `me.email` only when the actor directory shares it. No ids are offered as
  placeholders.
- **Permissions.** Personal templates: their owner only (MailKit, no override). With
  `nowo_crm_kit.template_permission`: `edit` to use templates and write, `manage` for the organisation's templates.
  *Write e-mail* needs `edit` on the contact, checked under the contact's lock.
- **Consent (GDPR, spec 248 FR-007).** A commercial template carries a consent purpose; the send is refused unless the
  contact's latest decision for it is a grant, read under the same row lock a consent change takes. Templates without a
  CRM classification (written through MailKit's API) cannot be sent (deny by default). The classification names the
  MailKit template version it was decided for (audit ZDI-L1): it is written only over an older version's, and a
  template whose current version differs (edited elsewhere, two saves racing, a crash between MailKit's commit and the
  CRM's) counts as unclassified until it is saved again in the CRM. A message written by hand is a service message: the
  person, not the CRM, decides its nature. *Write e-mail* fills `{{ deal.* }}` only from a deal of the contact.
- **Abuse limits.** Per-person send window (`templates.send_rate_limit`), one send per form (a random send key claimed
  in the write unit), CSRF tokens on every form (`nowo_crm_kit_template`, `nowo_crm_kit_mail`) and the delete action.
- **Data.** `crm_template_purpose` holds template ids, tenant and purpose only (no personal data). The counter rows of
  the send window are keyed by a hash of the person's id; send keys are random. `CrmMailSent` carries ids only. The
  sent message's subject and first 500 characters are stored as the `email` activity (A-1's GDPR paths cover it).
- **MailKit's sent-mail log (audit ZDI-M1).** Every *Write e-mail* sent through Symfony Mailer also writes a row of
  MailKit's sent-mail log (the contact's address and the subject; the body too with MailKit's `log.store_bodies`).
  With `templates.enabled`, `CrmPersonalDataInterface::erase()` erases them in the same unit (MailKit's
  `SentMailPersonalDataEraserInterface`: rows of the address deleted, or unlinked and masked when they have other
  recipients), for the contact's current address and those its merge traces keep of the contacts merged into it, by
  exact (normalised) address; a
  missing log table fails the erasure (the CRM's unit rolls back) instead of reporting it complete. MailKit's log has
  no tenant: the erasure removes every row of the address, also mail other tenants or the host sent to it. For the
  same reason `export()` does **not** list the log (a tenant would see other tenants' mail): the host's data-subject
  export covers it (MailKit's `SentMailPersonalDataExporterInterface`). Rows logged
  under an address the contact no longer has (changed before the erasure, or kept only by a purged merge trace) are not
  found by it: the host erases them
  with `nowo:mail-kit:log:forget <address>` and keeps `nowo:mail-kit:log:purge` scheduled. A host sender
  (`templates.mail_sender`) that does not go through Symfony Mailer keeps its own records: their export and erasure
  are the host's.
