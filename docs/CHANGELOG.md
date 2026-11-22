# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
- [[0.5.0] - 2026-11-22](#050---2026-11-22)
- [[0.4.2] - 2026-11-21](#042---2026-11-21)
- [[0.4.1] - 2026-11-21](#041---2026-11-21)
- [[0.4.0] - 2026-11-21](#040---2026-11-21)
- [[0.3.0] - 2026-11-20](#030---2026-11-20)
- [[0.2.1] - 2026-11-19](#021---2026-11-19)
- [[0.2.0] - 2026-11-19](#020---2026-11-19)
- [[0.1.0] - 2026-11-19](#010---2026-11-19)

## [Unreleased]

### Added

- Beacon UX review 1.171, item 1 (UX-002, 003, 005, 006, 010, 026 – 031). New `ui` options (see USAGE "Page layout
  options"): `row_actions: menu` (*Restore* and *Move to trash* in the UiKit row-actions menu, *Delete* last, through a
  bundle `row_actions` partial registered in the UiKit theme only with that option), `header_actions: split`,
  `record_actions: toolbar` (opt out of the split record header), `record_tabs: false` (no list tabs on record pages),
  `filters_fold` and `filters_slot` (a host template that wraps the filters region: `page`, `form`, `filters`). The
  header actions stay `View\Action` objects in `page.header.actions`; the bundle never renders a second page-actions
  menu.
- `css/nowo-crm-kit.css` (optional): tag swatches and the row-actions cluster, re-skinnable with
  `--crm-kit-colour-<name>`.
- `TranslationCatalogueTest`: no untranslated English value in any locale (loanwords and codes are allow-listed); new
  strings are translated in en, es, fr, de, pt, it and nl.

### Changed

- Record page header: *Edit* is an icon button and *Merge*, *vCard*, *Export personal data*, *Move to trash* and *Erase
  personal data* sit in the more menu, destructive last (`ui.record_actions: toolbar` keeps 0.5.0).
- Filters: every field has a label, a placeholder or an empty option that names it (*Any tag*, *Any role*, *Any
  purpose*, *Any owner*; search placeholder and `aria-label`).
- *Remove* (unlink a company, remove a host link) is a secondary icon `×`, not a red trash button; the tag colour is a
  swatch (list and form); the empty blocks of the record page use UiKit's `empty` partial.

### Fixed

- Checkboxes of the CRM forms rendered as a tall empty box: the text input class from the FormKit profile is no longer
  applied to them (UX-030).
- Audit YN-L1: the text searches (records, notes, deals, the actor-reference scan) lower the column and the parameter
  in SQL, `LOWER(col) LIKE LOWER(:q) ESCAPE '!'`, so a search with a capital outside ASCII ("ÉCOLE" for "École") finds
  the text on MySQL, MariaDB and PostgreSQL; the precomputed `*Key` columns keep the PHP-lowered text. SQLite's
  `LOWER()` folds ASCII only, so there a lower-case "é" still does not meet a stored "É" in the free-text columns (as
  before).
- DoctrineBundle 3.4: the bundle's ORM mappings never use the deprecated `alias` key and no class name uses the
  `Alias:Entity` shorthand (a test guards both).

## [0.5.0] - 2026-11-22

### Added

- `CrmReaderInterface::searchNotes(string $query, int $limit = 20)` (Beacon spec 251): notes of the tenant's active
  companies and contacts that contain the text (case-insensitive portable `LIKE`, `%` `_` `\` literal), newest first,
  as `NoteHit` (the record's type and id, the `NoteView`, an excerpt around the match). Each record passes the same
  per-record view check as `notes()` and the lists' owner scoping before a row counts towards the limit; the text is
  cut to 200 characters (under 2: no search) and the limit clamped to 1–50.
- `CrmReaderInterface::notesOf(list<SubjectRef>, int $perSubject = 5)`: the latest notes of several records keyed by
  `SubjectRef::key()`, in a fixed number of queries per 100 records (no query per record); unknown, erased, merged,
  hidden or other-tenant records get no key.
- `CrmStoreInterface::searchNotes()` and `notesOfMany()` (internal port) behind them.

## [0.4.2] - 2026-11-21

### Changed

- `nowo-tech/ui-kit-bundle` ^2.63.1 is required (was ^2.63): UiKit 2.63.1 fixes the contrast of the upcoming step labels in
  the import modal's header steps (4.39:1 on the tinted header). The demo e2e axe check no longer leaves those labels out;
  both demo locks resolve UiKit 2.63.1.

## [0.4.1] - 2026-11-21

### Changed (0.4.1: import modal on UiKit 2.63)

- The import modal fills the screen on phones (UiKit 2.63 `phone: 'sheet'`): the header and the footer stay in view and
  only the body scrolls; desktop keeps the wide size.
- The steps (file, columns, preview, result) move from the top of the body into the dialog header (`header_steps`,
  compact on phones: only the current step shows its label); each step's fragment carries them in
  `[data-part="steps"]`.
- The body's sections are separated by UiKit's `nowo-ui-section` dividers.
- Requires `nowo-tech/ui-kit-bundle` ^2.63.

## [0.4.0] - 2026-11-21

### Added (0.4.0: import modal)

- **Import modal** on the companies and contacts lists (the kit dialog rule of UiKit's PAGE_CONTRACT, "Dialog sizes and anatomy"): the page action **Import** opens UiKit's
  `confirm_dialog` with its anatomy (`anatomy: true`, wide size `--nowo-dialog-width-wide`, the content's height up to
  the viewport, UiKit's tinted header and footer). The steps run inside it (file → mapping → preview → result) with
  Cancel / Close first and one primary per step (Next, Preview, **Import**, Continue for a running import); no cards in
  the body; errors in place (form errors, conflicts); closing aborts the pending request and wipes the step; focus
  stays in the modal and returns to the opener; a finished import reloads the list when the modal closes.
- The import routes answer the modal's calls (`X-Nowo-Crm-Dialog: import`, `Infrastructure\Symfony\Http\ImportDialog`)
  with step fragments (`X-Nowo-Crm-Fragment: import-step`), and with 204 + `X-Nowo-Crm-Dialog-Location` instead of a
  redirect. Same routes, access checks (`import`, the area check, `nowo_crm_kit` access events) and CSRF tokens as the
  pages; lock conflicts keep REQ-RUNTIME-003 rule 6 (409, `CONFLICT_001` + `Retry-After` / `CONFLICT_002`,
  `X-Conflict-Code`), shown in place.
- Nobody signed in on a modal call: 401 `{"error": "session_expired"}` instead of the firewall's entry point; the modal
  shows a reload notice (also for a redirect to a login page) and never the login page.
- The page action is part of the bundle's list header (`View\Action` kind `import`, `data-crm-import-open`), shown only
  to someone who may import; its href is the import page, the no-JS fallback (`/crm/import?subject=contact|company`
  preselects what to import).
- `ImportServiceInterface::previewMapping(id, mapping, rows = 20)`: the first rows checked against another mapping,
  stored nowhere (the modal's preview step; also `do=preview` on the import page).
- Unfinished imports stay reachable once the modal is closed (audit XU-M1): the result step of a running import and the
  `CONFLICT_002` / session-expired notices link the import page, and the lists show someone who may import a UiKit
  notice linking their running imports in the tenant (`ImportServiceInterface::unfinished(limit = 5)`,
  `CrmStoreInterface::unfinishedImports()`).
- Browser asset `js/nowo-crm-import.js` (TypeScript in `src/Resources/assets`, Vite IIFE, Vitest) under the new
  `nowo_crm_kit` asset package (`/bundles/nowocrmkit`, REQ-ASSETS-004); the list loads it itself. No inline style, no
  inline script, no CDN.

### Changed (0.4.0: import modal)

- The area navigation no longer has an **Import** tab (the list's page action opens the modal; the import page stays
  as the no-JS fallback). The `nav.import` translation is removed.
- Requires `nowo-tech/ui-kit-bundle` ^2.62 (steps partial, dialog anatomy) and `symfony/asset`.

## [0.3.0] - 2026-11-20

### Added (0.3.0: funnels)

- **Funnels** (`pipeline.enabled`, off by default; tables and pages only while on): deal funnels and party lifecycle
  funnels of companies or contacts with ordered stages (open, won, lost; default probability; age limit that flags
  stale items), defined by people with `manage` (`FunnelService`, `/admin/funnels`).
- **Deals** (`DealService`, `DealReader`): a title, a company and / or a contact the actor may view, an owner, an
  amount (`Money`, decimal + ISO 4217) and a probability override behind `amounts_view`, an expected close; stage
  moves with a required lost reason (`pipeline.lost_reasons`) for a lost stage, won / lost close the deal, an open
  stage reopens it; insert-only stage history with the seconds spent in the stage left; trash, restore, purge after
  `trash_days`; plan limit `limits.deals`. Owner-based visibility and `nowo_crm_kit.record_access_check` with subject
  type `deal`; keyset lists ordered by last change, expected close or amount.
- **Statistics per funnel** (`FunnelStatistics`): count and stale count per stage now, counts at a past instant,
  value and weighted value per currency with `amounts_view`, conversion to the next stage, win rate, average time in
  stage, deals closed in a period with their lost reasons; owner, role and tag filters; same visibility as the lists;
  CSV with formula cells neutralised.
- **Next actions** (`NextActionService`) on companies, contacts and deals through the new port `CrmTaskPort`
  (`pipeline.task_port`): the built-in list by default, or TaskBoard tasks through `TaskBoardCrmTaskPort`
  (`pipeline.taskboard.board_id`, `pipeline.taskboard.column`, a host `CrmTaskBoardUserResolverInterface`). The port
  runs before the CRM's write unit with the caller's dedupe key; a deal's `next_action_at` follows the earliest due
  open action and an overdue one marks the deal stale.
- **Lifecycle stages** of companies and contacts (`PartyStageService`) and the pages: deal list and board, deal page
  (stage move, history, next actions, trash / restore / purge), deal forms, statistics, funnels administration, and
  deals, lifecycle and next actions on the 360° page. 7 locales.
- GDPR: the export of a contact lists its deals, lifecycle stages, stage history and next actions; erasing it unlinks
  its deals and deletes the rest. Merges point deals and funnel rows at the survivor; a purged record's deals are
  unlinked. `nowo:crm-kit:counters:rebuild` and `nowo:crm-kit:purge` know deals.

### Added (0.3.0: the API)

- **`Api\Operation` layer** (`api.enabled`, off by default): the operations an in-process caller (an MCP host's
  `crm_*` tools) and the JSON API share. It covers search, company / contact get, timeline, notes, consents
  (read-only), tags, links, affiliations, identity, creates with the duplicate check first, updates with a required
  base version, trash, affiliation set / remove, note add, tag add / remove, next actions, deals (search, get, create,
  update, stage move, trash) and funnels with statistics. `CrmApiPrincipal` carries the security user, the CRM actor, a
  grant (`view` / `edit` / `manage` + amounts, consents and destructive ticks), the reach (tenants) and the token. The
  checks run in a fixed order: out of reach 404 `ACCESS_001`, then the grant 403 `TOKEN_001`, then the person 403
  `ACCESS_002`. Codes are `ABC_123`-shaped, and read models keep untrusted text under `text`. See
  [API.md](API.md).
- **Idempotency**: every write needs an `Idempotency-Key`. The claim is the first statement of the write unit and the
  outcome its last (table `crm_api_idempotency`, ids only, 24 h by default), so a concurrent duplicate waits and
  replays. A key reused with another input is 409 `IDEMP_002`. `nowo:crm-kit:api:purge-idempotency` and the daily
  purge delete expired keys.
- **Sealed keyset cursors** (`ext-sodium`, key derived from `kernel.secret`) on every API list. There is no offset and
  no total, and a cursor of another query is 400 `INPUT_003`.
- **Audit with the token**: `AuditEntry::$origin` (`AuditOrigin`: channel, token id, token name). API writes add `via` and
  `token` to their timeline entries, and the 360° page shows "via agent ‹token›" (7 locales).
- **`/crm/v1` JSON API** (`api.http.enabled`, off by default; route type `nowo_crm_kit_api` under `api.http.prefix`;
  parameters `nowo_crm_kit.api_prefix` and `nowo_crm_kit.api_path`). It has host hooks `CrmApiAuthenticatorInterface`
  and `CrmApiTenantResolverInterface`, sends `ETag`, `X-Error-Code` and `Idempotency-Replayed`, and answers lock
  conflicts per REQ-RUNTIME-003 rule 6. The OpenAPI 3.1 document is `docs/openapi/crm-v1.yaml`.
- **Shipped contract**: `tests/Contract/crm-api-v1.json` (the operation catalogue) and `tests/Support/CrmApiConformance`
  (the grant matrix a host can run against its own kernel).
- `RecordAccessCheckEvent` and `RecordVisibilityEvent` gain `getChannel()` and `getTokenId()`.
  `ScopedRecordVisibility::visibleOwnersFor()`, `CrmResourceAccessChecker::decide()`, `CrmAccess::bind()` and
  `as($caller)` on the Application services run a call as a given caller. New keyset indexes on companies, contacts and
  notes.

### Changed (0.3.0: audit VS)

- **transaction-doctrine ^1.8.1** (was ^1.6; 1.8.1 rather than 1.8.0, whose replay could answer 409 when new entities referenced a host-managed entity): it fixes GHSA-9wh3-m3m5-jffx and GHSA-65w2-9r4h-848g, which the bundle's
  write units reach; a replay now keeps the host's entity manager instead of clearing it.

- **Behaviour change for hosts:** the 0.2.x web lists showed records a `nowo_crm_kit.record_access_check` listener
  denied (only `security.resource_scoping` filtered them); 0.3.0 hides them in every list, export, label and
  timeline. Totals become the SQL count minus the page's denied rows (see UPGRADING).

- **Every list hides records a listener denies** (owner decision Q2): the 0.2 web lists, the CSV export, related labels
  (a company's contacts, a contact's companies), the company timeline, duplicate hints, the merge preview, the API
  search, affiliations and timelines pass each row through `nowo_crm_kit.record_access_check`, one batch per page
  (`CrmBatchAccessCheckerInterface`, implemented by the default checker). See UPGRADING.
- **API company creates behave like the pages** (owner decision Q1): a match the principal may not view no longer
  answers `CRM_006`; the company is created. `CRM_006` stays for a contact's e-mail (a unique key).
- A contact created with `company` is affiliated in the create's own write unit; a refused affiliation writes nothing,
  and a replay writes nothing.
- The deal list's cursor is sealed and keyed on the last visible deal; pages scan past denied deals (bounded).
- `crm_contacts` is a sensitive SearchKit group (personal data).
- The API's error listener leaves the firewall's 401 / 403 and HTTP exceptions to Symfony.
- Next-action task states are read with the API token's host context; a dedupe key that names another subject is
  refused (`IDEMP_002` through the API).

### Removed (0.3.0)

- The root configuration key `access_checker` (a 0.1.0 `CrmAccessCheckerInterface`, deprecated since 0.2.0),
  `LegacyCrmAccessChecker` and `CrmPermission::legacy()`.

### Changed (0.3.0: header search)

- **SearchKit groups on SearchKit's provider base (SearchKit ^1.9, PHP >= 8.3)**: `crm_companies` and `crm_contacts`
  are now `EntitySearchDefinition`s (`Bridge\SearchKit\CrmRecordSearchDefinition`) instead of `CrmSearchProvider`, and
  a new group `crm_deals` (`CrmDealSearchDefinition`, with `pipeline.enabled`) finds deals by title, company and stage,
  with the amount shown only to people with `amounts_view`. Every candidate passes `nowo_crm_kit.record_access_check`
  after the SQL visibility filter; SearchKit's `nowo_search_kit.item_visibility` event, group switches, paged results and
  revalidated recents apply. SearchKit stays optional and out of `composer.json` (`.github/ci/search-kit.sh` adds it to
  the PHP >= 8.3 CI jobs).

## [0.2.1] - 2026-11-19

### Added

- **Batch lookups for host jobs**: `CrmBatchLookupInterface` (public alias), a system API for the host's
  backend jobs, tenant-scoped and without access checks: `contactsByEmails()`, `companiesByDomains()`,
  `subjectsLinkedToMany()` (`<type>:<id>` keys), `affiliationsOf()` (primary first), `rolesAndTagsOf()` (read model
  `RolesAndTags`) and `existing()` (the given records whose row still exists, any status: reconciliation sweeps). One
  read query per call (per chunk of 500 inputs), every usable input answered, active records only.
  Key helpers `EmailAddress::lookupKey()`, `CompanyDomain::key()` / `fromWebsite()` (the stored domain key now comes
  from it), `HostObjectRef::key()`, `SubjectRef::key()`; persistence port `CrmBatchLookupStoreInterface` with
  `DoctrineCrmBatchLookupStore` (plain SQL, no entity hydrated).
- **`RecordsPurged`**: the scheduled retention purge announces every committed batch of deleted records
  (trashed records and merge tombstones, at most 200 of one type) after its commit, with the tenant and the records'
  type + id only (`CrmKitEvents::RECORDS_PURGED`); a rolled-back batch announces nothing. A manual `purge()` keeps
  dispatching `RecordPurged` per record.
- **Stable actor ids**: `CrmActorResolverInterface` (`security.actor_resolver`; default `IdentifierActorResolver`,
  `getUserIdentifier()` as before) gives the actor id the access checker and the default directory use, so a host can
  store user UUIDs instead of e-mails; `resolve()` (Domain port `CrmActorProfilesInterface`) names the actors of a page
  (owners in lists, note authors, timeline and consent actors) in one call, the directory naming the rest.
- **A person's references across tenants** (system API without tenant scope or permission check, its own interface
  `CrmActorPersonalDataInterface` so the tenant-scoped `CrmPersonalDataInterface` stays safe for GDPR pages):
  `exportActor()` and `anonymiseActor()` over owner, note author, created_by / updated_by, timeline, consent, link, role, import and
  merge actors and the owner inside timeline entries and merge snapshots; batches of 200 rows, one write unit each,
  versions moved, idempotent; event `ActorAnonymised` (`CrmKitEvents::ACTOR_ANONYMISED`, tenant and count) and audit
  `crm.actor.anonymised` per tenant after the commits, also for the batches committed before a failure. Each page is
  read without a lock and only its matched rows are locked by primary key, so a batch never blocks other tenants'
  writes. The placeholder `anonymised` (`ActorRef::ANONYMISED`) is reserved: never an actor (whatever the checker or
  resolver returns), an owner input, a visible owner (whatever the visibility service returns), a name to resolve or
  the actor of a resumed import; a signed-in person is never the demo's shared actor `anonymous` either. A failing
  audit or listener for one tenant never stops the others from being released.
- `CrmKitEvents` constants for the domain events dispatched after the commit (`CONTACT_ERASED`, `RECORDS_MERGED`,
  `HOST_LINK_REMOVED`, `RECORD_PURGED`, … : their class names), and the host's view of link, merge, erasure and purge
  changes in USAGE "Events".

### Fixed

- **Paused unattended import resume** (follow-up of audit UP2-1): when the resume command stops before a row that
  needs a person's decision, it now releases its claim in the same transaction. The import waits for a person
  (`ImportView::$awaitingPerson`); later resume runs skip it instead of taking it again after `stale_claim_seconds`
  and pausing again (with a fresh claim in between); a person's "Continue" claims it with its own token (one winner),
  and "Cancel" ends it at once instead of waiting for the claim to go stale. Cancel decides in one conditional UPDATE,
  so it never ends an import a "Continue" or the resume command has just claimed (`crm_import_running`). No schema
  change.

## [0.2.0] - 2026-11-19

### Added

- **Party roles**: a company or a contact holds any number of roles from `roles.catalogue` (built in: `customer`,
  `supplier`, `partner`; `distributor` translated and opt-in), stored in the new table `crm_role_assignment` (unique
  per tenant, record and role; binary NO PAD key). Roles are edited in the record form, through
  `RecordInput::$roles` and `RecordServiceInterface::setRoles()` (set semantics, the record locked first), shown on
  the 360° page and in the lists, filtered by `ListQuery::$role` and the list pages, imported (`roles` column, keys
  separated by `,` or `;`) and exported (`roles` CSV column). Event `RolesChanged`; audit `crm.roles.changed` (keys
  only) after the commit. A role removed from the configuration keeps its rows, hidden everywhere except the GDPR
  export.
- **The company's primary contact** (`crm_affiliation.is_primary_contact`, at most one per company, under the
  company's row lock with the contact → company lock order): `affiliate(…, primaryContact:)`,
  `RecordServiceInterface::setPrimaryContact()`, a "Make primary contact" action on the company page
  (`/companies/{id}/primary-contact/{contact}`), an import column `primary_contact` and the filter
  `ListQuery::$primaryContactOnly`. Audit `crm.affiliation.primary_contact`.
- **Affiliation purposes** from `affiliations.purposes` (built in: `billing`, `orders`, `technical`, `general`; stored
  as `crm_affiliation.purpose_keys`): `affiliate(…, purposes:)`, the affiliation form, the import column `purposes`,
  the filter `ListQuery::$purpose`.
- `CrmCatalogueInterface` (`roles()`, `purposes()`), value objects `RoleKey` and `AffiliationPurpose`, timeline kinds
  `roles_changed` and `primary_contact`, translations of every new key in the 7 locales.
- Merges move the roles (no duplicate), unite the purposes of a shared affiliation and keep one primary company per
  contact and one primary contact per company; purge and GDPR erasure delete the roles; the GDPR export lists every
  stored role and every affiliation with its purposes and flags.
- Two-connection race tests (MySQL 8.4, MariaDB 11.4, PostgreSQL 16): concurrent role edits, two "set primary
  contact" calls on one company, an import chunk waiting on a locked parent before it settles any row.
- **Access in the house shape (REQ-UI-002)**: the `security` node (`access_roles`, `access_checker`,
  `allow_unauthenticated`, `manage_roles`, `settings_roles`, `export_roles`, `erase_roles`, `resource_scoping`), the
  area checker `CrmKitAccessCheckerInterface` (and `TenantAwareCrmKitAccessCheckerInterface`) with
  `ConfigurableCrmKitAccessChecker` and `AllowAllCrmKitAccessChecker`, the record checker `CrmResourceAccessChecker`
  with the event `CrmKitEvents::RECORD_ACCESS_CHECK` (`RecordAccessCheckEvent`: grant / deny, a deny wins),
  `CrmKitGuard` (`requireGranted`, `isGranted`, `grants`), a `kernel.controller` listener that requires `view` on every
  bundle route (nobody signed in ⇒ the firewall's entry point), and a compile-time failure when the pages are on
  without SecurityBundle (unless `allow_unauthenticated`). `CrmPermission` gains `create`, `delete`, `import`,
  `export` and `erase`. The area checker also has `canViewAmounts()` and `canSendCampaigns()` (`security.amounts_roles`,
  `security.campaigns_roles`, default `[ROLE_ADMIN]`) for the later phases' `amounts_view` and `campaigns_send`.
- **Resource scoping in every read** (audit UP-M-1): with `security.resource_scoping`, lists and their totals, search,
  duplicates, CSV exports, a page's related records (affiliations, a company's timeline), `subjectsLinkedTo()` and the
  affiliation picker show only ownerless records, the person's own and the owners a
  `CrmKitEvents::RECORD_VISIBILITY` listener (`RecordVisibilityEvent`: `addOwner()`, `allowAll()`) adds, filtered in
  SQL (port `CrmRecordVisibilityInterface`, default `ScopedRecordVisibility`); the same set is the default of a
  decision on one record. Whoever may manage the settings (the area checker's `canManageSettings`, the host's when
  configured) sees everything.

### Changed

- **Breaking (configuration):** `access.roles` is replaced by `security.*_roles` (defaults `[ROLE_ADMIN]`); the root
  `access_checker` key is deprecated (a 0.1.0 checker set there is wrapped and asked the new permissions as the old
  ones) and removed in 0.3.0; `RoleMapAccessChecker` is removed.
- **UD2-L-1**: an import chunk locks, before it settles any row, the existing contacts of its e-mails in id order and
  then the companies its rows name in id order (the lock order of every other path), instead of row by row.
- **UD2-L-3**: a second `nowo:crm-kit:purge` that starts while a batch of the first holds the claim row prints
  "Another purge is running" at once (the claim row is read `FOR UPDATE SKIP LOCKED`) instead of a lock conflict.
- **UD2-L-2** (documented): a write unit replayed after a deadlock clears every open entity manager, the host's
  included (inherited from transaction-doctrine); see CONCURRENCY and INSTALLATION.
- The phase plan of 2026-11-19: funnels 0.3.0, tasks and agenda 0.4.0, quotes 0.5.0, invoices 0.6.0, customer portal
  0.7.0, scoring 0.8.0, lists and segments 0.9.0, circulars 0.10.0, reports later; the switches of the later phases
  name their new release.
- `RecordView` gains `roles`, `AffiliationView` gains `primaryContact` and `purposes` (new trailing constructor
  arguments with defaults); `RecordServiceInterface` gains `setRoles()` and `setPrimaryContact()`, and `affiliate()`
  two optional arguments; `CrmStoreInterface` (internal) gains `contactIdsByEmailKeys()`, `affiliatedCompanyIds()`
  and `roleAssignments()`.
- The CSV export has a `roles` column after `owner`.
- The minimum database versions are MySQL 8.0, MariaDB 10.6 and PostgreSQL 9.5 (`SKIP LOCKED`; PostgreSQL 10 with
  DBAL 4).
- `updated_at` of companies, contacts, notes and settings is kept to the microsecond (`DATETIME(6)` /
  `TIMESTAMP(6)`, type `nowo_crm_utc_datetime_micro`): every write of a company or contact moves its optimistic
  version, also two role-, tag- or affiliation-only changes in one second, so a stale form is refused (the
  whole-second column hid them from Doctrine's change detection).
- An empty `access_roles`, `manage_roles`, `amounts_roles` or `campaigns_roles` list means **any authenticated
  user** for that area (0.1.0's empty lists denied); `settings_roles`, `export_roles` and `erase_roles` must name at
  least one role.
- `security.allow_unauthenticated` is refused when `kernel.environment` is `prod`, and turns `resource_scoping` off
  (one shared actor `anonymous`).

### Fixed (audit UL, before the release)

- An affiliation edit replaced every purpose: purposes no longer configured are now kept (only the configured ones
  are replaced).
- Re-sending an affiliation with "primary contact" unticked cleared the company's primary contact (403 without `edit`
  on the company): unticked now keeps it; clearing is a "Remove as primary contact" action on the company page
  (`/companies/{id}/primary-contact/clear`), and each linked company has its own form, filled with the stored role,
  flags and purposes (the add form lists only companies not linked yet).
- `crm_affiliation.purpose_keys` is `VARCHAR(1100)` (32 keys of 32 characters fit).
- An import row that changes a company's primary contact needs `edit` on that company, else it is `invalid`
  (`error.forbidden`); the ambiguous column alias `primary` is gone (`primary_contact` stays).
- Imports announce role changes (`RolesChanged`) and audit them (`crm.roles.changed`).
- The GDPR export lists affiliations to trashed companies too, with `company_status`.
- `ListQuery` refuses a role or purpose that is not a valid key.
- A contact merge moves the primary-company flag of a company both contacts are linked to when the survivor had none.

### Fixed (audit UP, before the release)

- A merge also needs `delete` on the merged record (it becomes a tombstone); the merge action is shown only with it.
- Removing the affiliation of a company's primary contact needs `edit` on the company, locks it and records the
  `primary_contact` timeline entry.
- The scoping bypass asks the area checker (`canManageSettings`), so a host checker decides it too.

### Fixed (audit UP-2, before the release)

- `nowo:crm-kit:import:resume` (nobody signed in) settled every row needing a decision on one record as
  `error.forbidden`: it now stops before such a row (an update of an existing contact, a named company) and leaves it
  pending for a person's "Continue".
- A taken e-mail (form error, import preview) names the other contact only to someone who may see it.
- An import resolves a named company among the companies the importer may see (an invisible one is unknown), once,
  and links exactly the company it checked.
- More than 1 000 visible owners is refused with a clear `LogicException` (one SQL parameter each).
- Docs: `RECORD_VISIBILITY` runs per access check; lists follow it, single records `RECORD_ACCESS_CHECK`; the
  microsecond `ALTER`s are run by hand on DBAL 4; the GDPR export lists every affiliated company.

### Security

- An import with the `update` duplicate policy rewrote existing contacts (values, custom values, tags, roles,
  affiliations) with the tenant-wide import permission only; 0.1.0 is affected (no advisory, owner decision
  2026-11-19). Each matched contact now needs `edit` on that contact, and a named company `view` on it, before the row
  writes anything; otherwise the row is `invalid` (`error.forbidden`) and echoes nothing (a skipped duplicate shows
  the existing id only to someone who may see it).

## [0.1.0] - 2026-11-19

### Added

- Single-run claim of the retention purge, counter row locked before `counters:rebuild` recounts, one custom-field
  define at a time per tenant and type, audit entries for notes, tag assignments, links, affiliations and host
  timeline entries, the route × profile permission-matrix test, and a dependency-floor CI job
  (`nowo-tech/transaction-*` at their lowest allowed versions, demo locks checked by `.scripts/check-demo-lock.py`).
- Audit UD of phase A, before the first tag:
  - GDPR: a merge tombstone keeps no personal value (only id, tenant, status and survivor; the `crm_merge` snapshot is
    the audit copy); an erasure locks and blanks the tombstones that point at the contact, recursively, and deletes
    their merge traces; the retention purge deletes tombstones after `merge_keep_days`.
  - Child writes lock their parent first: notes, consents, host links, host timeline entries and import updates lock
    the record `FOR UPDATE`; an affiliation locks the contact, then the company (imports too); a child of a merged or
    non-active record answers 409.
  - Shared entity manager (`entity_manager: null`): the runner begins, flushes and commits itself; a refused write
    rolls back and detaches only the bundle's entities, so the host's manager stays open and its entities managed.
  - A write inside a transaction that is already open is refused (`LogicException`): the events and audit entries are
    released after the bundle's own commit.
  - `NowoTransactionDoctrineBundle` is required (the container build fails with the fix; the Flex recipe registers
    it): its commit ledger, DBAL middleware and request watermark produce `CONFLICT_002`.
  - A unique key refused twice is the domain conflict `crm_write_conflict` (409 without `X-Conflict-Code`); on an
    edit the e-mail form error appears only when another record holds the e-mail.
  - Counter rows and the purge claim are locked with `SELECT … FOR UPDATE` before any insert (no MySQL deadlock); the
    purge claim is refreshed in every batch and released only while it is still the run's; batch deletes count the
    rows really deleted; the purge also covers tenants that only have imports, merges or timeline entries.
  - An edit that keeps the owner skips the directory check; a field archive uses the version the page showed; the
    import "Continue" attributes its rows to the person who clicked; uploads over `import.max_bytes` are refused
    before they are read (`File(maxSize)` on the form); the GDPR export is a POST with the action token; a host link
    of a type with a resolver is stored only when the resolver confirms the object; URLs starting with `/\` are not
    links; list pages are clamped to 1..10 000.
  - Tests: two-connection races for child writes, field defines, counter rebuild against a create, two purges and a
    `CONFLICT_002` after a commit in the same request; the permission matrix covers the POST actions; erasure is
    checked on the database rows.
- **Phase A: companies and contacts** (0.1.0, contract sections 5 to 18), always on when the bundle is enabled.
- Companies and contacts on one service, `RecordServiceInterface` (`create`, `update`, `trash`, `restore`, `purge`,
  `affiliate`, `unaffiliate`) with `RecordInput` (type, core values, owner, custom values, tag ids, `setOwner`):
  server-side validation of every core field (lengths, e-mail, http(s) URL, phone, ISO country), a contact needs a
  first or a last name, the owner must be one of `CrmActorDirectoryInterface::owners()`, optimistic `version` on every
  edit (409 `crm_record_stale`), trash with restore (plan limit checked again) and purge from the trash (`manage`).
- Contact e-mails unique per tenant (case-insensitive key `email_key`); a second contact with the same e-mail is a form
  error naming the existing one.
- Affiliations: a contact links to any number of companies with a role; at most one is primary; adding an existing
  pair updates it.
- Tags (`TagServiceInterface`: create, change, delete, assign with set semantics; eight UiKit palette colours), custom
  fields (`FieldServiceInterface`: define, change, archive; text, textarea, number, date, boolean, choice, url, email;
  up to `fields.max_per_subject` per type; archived fields keep their values), notes (`NoteServiceInterface`: plain
  text up to 10 000 characters, pinned first; the author edits and deletes own notes with `edit`, others' need
  `manage`).
- Activity timeline (`crm_activity`, insert only): created, updated (field names only), owner changed, trashed,
  restored, merged, notes, tags, links, affiliations, consents, imported, exported, and host entries through
  `TimelineInterface::record()`; a company's timeline includes the entries of up to 200 of its contacts.
- Host links (`HostLinkServiceInterface`: `link` (idempotent), `unlink`, `subjectsLinkedTo` for the reverse lookup) and
  host object resolvers (`CrmHostObjectResolverInterface`, tag `nowo_crm_kit.host_object`): a link of a type with a
  resolver is stored only when the resolver confirms the object and shows the resolver's label and URL (hidden when
  the resolver no longer returns it); a type without a resolver shows the stored label without a URL.
- Host panels on the 360° page (`CrmPanelProviderInterface`, tag `nowo_crm_kit.panel`): the provider gets the tenant,
  the record, the actor and the record's host links; a failing provider is logged (class names only) and skipped, a
  transient storage failure is rethrown.
- Reads (`CrmReaderInterface`): record, list (text, tag, owner, status, sort, page), timeline, duplicates, notes,
  links, panels, consents, tags, fields, owners. A merged id is answered with its tombstone, whose page redirects to
  the survivor.
- Duplicate hints (read only): companies with the same name key, website domain or tax id key; contacts with the same
  sort key ("last first").
- Merge (`MergeServiceInterface`: preview, merge): both records locked `FOR UPDATE` in id order, versions re-checked,
  field-by-field choice, custom values united, children moved by primary key (unique children de-duplicated first),
  the other record becomes a `merged` tombstone without personal values, a snapshot kept in `crm_merge`.
- CSV and vCard 3.0 / 4.0 import (`ImportServiceInterface`: upload, preview, confirm, run, cancel): UTF-8, BOM
  stripped, `,` or `;`, header row, at most `import.max_rows` rows and `import.max_bytes` bytes; detected and editable
  column mapping (core fields, `custom.<key>`, `tags`, and `company` for contacts); duplicate policy `skip` or
  `update` by contact e-mail; outcomes `created`, `updated`, `duplicate`, `invalid`, `limit`. The confirm claims the
  import with a conditional UPDATE and runs chunks of `import.chunk_rows` in the request up to `import.sync_rows`; the
  page then offers "Continue", and `nowo:crm-kit:import:resume` finishes imports whose claim went stale.
- Exports (`CrmExporterInterface`): CSV of every record matching a list's filters (keyset batches, streamed, cells
  starting with `=`, `+`, `-`, `@`, tab or CR prefixed with `'`; `manage`) and a vCard 4.0 of one contact (`view`).
- GDPR: consent per contact and purpose (`ConsentServiceInterface`, append-only, purposes from `gdpr.purposes`),
  JSON export of a contact (a POST with the action token) and erasure (`CrmPersonalDataInterface`, `manage`;
  idempotent; consent rows kept without evidence; the merge tombstones behind the contact blanked), retention purge
  `nowo:crm-kit:purge` (single run; trash after `trash_days`, timeline after `timeline_years`, import rows after
  `import_keep_days`, merge snapshots and tombstones after `merge_keep_days`; `--tenant`, `--dry-run`).
- Per-tenant settings (`CrmSettingsInterface`, `manage`, versioned: `crm_settings_stale`).
- Plan-limit counters (`crm_counter`): atomic `UPDATE … WHERE value < :limit` in the record's transaction, created with
  `INSERT IGNORE` / `ON CONFLICT DO NOTHING`; `nowo:crm-kit:counters:rebuild`.
- Ports with safe defaults: `CrmTenantResolverInterface` (single tenant `default`), `CrmAccessCheckerInterface`
  (role map, every list empty = denied), `CrmFeatureGateInterface` (`feature.default_enabled`), `CrmPlanLimitsInterface`
  (`limits.*`), `CrmActorDirectoryInterface` (the signed-in user only), `CrmAuditSinkInterface` (one PSR log line),
  `IdGeneratorInterface` (UUIDv7), PSR-20 clock.
- Events after the commit (ids and VOs only): `RecordCreated`, `RecordUpdated`, `RecordTrashed`, `RecordRestored`,
  `RecordPurged`, `RecordsMerged`, `ContactErased`, `NoteAdded`, `ConsentRecorded`, `ImportCompleted`,
  `TagsChanged`, `HostLinkAdded`, `HostLinkRemoved`; audit entries `crm.*` with ids and counts only.
- UI (opt-in, `ui.enabled`; route loader `nowo_crm_kit_ui`): UiKit list, record, form and settings pages with FormKit
  forms (profile `crm_kit`), phone card layout, row actions in a kebab, the focus-mode toggle on lists and record
  pages, CSRF on every POST; tag, field and settings administration under `/admin`.
- SearchKit bridge: providers `nowo_crm_kit.search.company` and `nowo_crm_kit.search.contact` (groups `crm_companies`,
  `crm_contacts`), registered when SearchKit is installed, `ui.enabled` and `search.enabled`.
- Concurrency (REQ-RUNTIME-003): write units through `nowo-tech/transaction-doctrine` ^1.6 (its bundle required), lock
  conflicts answered 409 with `CONFLICT_001` + `Retry-After` + `X-Conflict-Code` (or `CONFLICT_002`), a fallback
  listener when the host turns its conflict responder off; two-connection race tests on MySQL 8.4, MariaDB 11.4 and
  PostgreSQL 16 ([CONCURRENCY.md](CONCURRENCY.md)).
- Worker mode (REQ-RUNTIME-002): stateless services, the private entity manager cleared after every unit and at the
  end of every request and command ([FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md)).
- Binary NO PAD collations on every id, reference and key column (MySQL 8 `utf8mb4_0900_bin`, MariaDB
  `utf8mb4_nopad_bin`) and `===` re-checks in PHP (REQ-SEC-006).
- Translations in en, es, it, fr, pt, de and nl.
- Scaffold: Composer, Docker (MySQL 8.4, MariaDB 11.4, PostgreSQL 16, SQLite), Makefile with `release-check`, CI,
  PHPStan with phpstan-frankenphp, Igor, Rector, CS, 100 % coverage gate, Spec Kit baseline, Flex recipe (private
  entity manager), demos for Symfony 7.4 and 8.1, strict configuration tree with the switches of the later phases.
