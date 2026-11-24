# Feature Specification: CrmKitBundle baseline

**Feature Branch**: `001-baseline` (phase A on `feat/phase-a-companies-contacts`)
**Created**: 2026-11-18
**Status**: Baseline (phase A, 0.1.0), extended through 0.10.1 (inventory refreshed 2026-11-24)
**Input**: Contract v0.1 for symfony-beacon spec 245 ([`docs/CONTRACT.md`](../../docs/CONTRACT.md), adjusted to the
phase A code), owner decision D-20261118-05, every Q-O and Q-B question as recommended until answered.

Related: [`docs/SPEC-DRIVEN-DEVELOPMENT.md`](../../docs/SPEC-DRIVEN-DEVELOPMENT.md),
[`docs/USAGE.md`](../../docs/USAGE.md), [`docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md),
[`docs/CONCURRENCY.md`](../../docs/CONCURRENCY.md), [`docs/ROADMAP.md`](../../docs/ROADMAP.md),
[`code-inventory.md`](code-inventory.md).

## Summary

A reusable CRM for Symfony applications, built phase by phase (A–G). Phase A gives each tenant companies and contacts
with owners, tags, custom fields, notes, an activity timeline, affiliations, host links and host panels on a 360°
page, CSV / vCard import, duplicate hints and merging, CSV / vCard export, GDPR consent / export / erasure /
retention, trash with restore, plan-limit counters, SearchKit providers and an opt-in UiKit + FormKit UI. The host
owns tenants, people, permissions, the plan and its own modules and plugs them in through ports; the bundle owns its
tables on a private entity manager and never flushes the host's work.

## User scenarios

### US-01 - Install and configure

- **Given** a host with the multi-entity-manager Doctrine form and the Flex recipe, **when** the bundle is registered,
  **then** the container builds with the contract defaults, the mapping is on `nowo_crm_kit` and the alias
  `nowo_crm_kit.entity_manager` points at it.
- **Given** an unknown key, an out-of-range value, a `ui.path` without a leading `/`, an undeclared entity manager or a
  later phase switched on, **when** the container builds, **then** it fails and names the path or the fix.
- **Given** `doctrine.entity_manager: null`, **then** the default manager is used in explicit transactions and the
  host's pending changes are never flushed.

### US-02 - Ports and the gate

- **Given** no host ports, **then** the tenant is `default`, nothing is granted until `access.roles` maps roles, and
  the only owner is the signed-in user.
- **Given** no tenant, the feature gate off or nobody signed in, **when** any page or service is called, **then** it
  answers 404; **given** a missing permission, **then** 403; **given** an id of another tenant, **then** 404.

### US-03 - Companies and contacts

- **Given** `edit`, **when** a user creates a company (name required) or a contact (first or last name required) with
  owner, tags and custom values, **then** it is stored, a `created` timeline entry and a `RecordCreated` event follow
  the commit, and the plan counter moves.
- **Given** two users editing the same version, **then** one save wins and the other sees the form again with the
  current values and status 409 (`crm_record_stale`).
- **Given** a contact e-mail already used in the tenant (any case), **then** the save is a form error naming the
  existing contact.
- **Given** a record in the trash, **then** it is hidden from lists and search, can be restored (plan limit checked
  again) or purged with its children (`manage`).
- **Given** a contact and a company, **when** they are affiliated with a role and marked primary, **then** the other
  affiliations of the contact lose the primary flag.

### US-04 - The 360° page

- **Given** a record, **then** its page shows details, custom values, owner, tags, contacts / companies, host links,
  host panels, notes (pinned first), the timeline (a company's includes its contacts'), possible duplicates and, for a
  contact, consents.
- **Given** a link whose type has a resolver that does not return it, **then** the link is hidden; **given** a type
  without a resolver, **then** its stored label shows without a URL.
- **Given** a panel provider that throws, **then** its panel is skipped and the page renders.
- **Given** a merged record id, **then** its page redirects to the survivor.

### US-05 - Import, duplicates and merge

- **Given** a CSV or vCard file within the limits, **when** it is uploaded, **then** a preview shows the detected
  mapping, the first rows with their errors and the existing contact with the same e-mail.
- **Given** two confirms of one import at the same moment, **then** one runs and the other answers
  `crm_import_running`; no row is stored twice.
- **Given** an import larger than `import.sync_rows`, **then** the page offers "Continue"; **given** a stopped worker,
  **then** `nowo:crm-kit:import:resume` finishes it after `import.stale_claim_seconds`.
- **(0.4.0) Given** someone who may import, **when** they choose **Import** in a list's page actions, **then** the import
  opens in a modal (file → mapping → preview → result) with errors in place; **given** an expired session, **then** the
  modal shows a reload notice, never the login page; **given** no JavaScript, **then** the action opens the import page.
  Someone who may not import sees no action and gets 403 from the modal's calls.
- **Given** two records of one type, **when** a user merges one into the other choosing fields, **then** every child
  row moves to the survivor, the other becomes a tombstone and a snapshot is kept; two crossed merges never both
  succeed.

### US-06 - GDPR

- **Given** `manage`, **when** a contact is exported, **then** one JSON document holds everything the CRM keeps about
  them; **when** it is erased, **then** everything the export returned is gone or blank, and consent rows remain
  without their evidence.
- **Given** retention settings, **when** `nowo:crm-kit:purge` runs, **then** trashed records, old timeline entries,
  finished import rows and old merge snapshots are deleted per tenant.

### US-07 - Concurrency and worker mode

- **Given** creates at the plan limit on two connections, **then** exactly `limit` records are stored.
- **Given** a write waiting on a row another connection holds, **then** it answers 409 `CONFLICT_001` with
  `Retry-After` and `X-Conflict-Code`, and the connection is usable afterwards.
- **Given** one kept-alive kernel serving two tenants and two actors, **then** no data crosses, no managed entity
  survives a request and memory stays flat.

### US-08 - HubSpot import (0.10.0)

- **Given** a CRM manager pastes a HubSpot credential with read scopes only, **then** it is checked, sealed and shown
  as its last four characters; a credential with a write scope is refused.
- **Given** a person with `import` + `edit` picks objects and lists, maps the properties and confirms, **then** the run
  imports companies, contacts, owners and lists page by page, and a re-run updates instead of duplicating.
- **Given** an operator rotates the secret key, **then** every token keeps opening and `secrets:rotate` re-seals them
  without losing any; a key that is not configured pauses the import and keeps the token.

## Requirements

- **FR-CONFIG-001**: strict configuration tree (contract section 10 as built), parameters `nowo_crm_kit.<path>`, the
  later-phase switches refuse `true`.
- **FR-EM-001**: private entity manager `nowo_crm_kit` (or the default one with explicit transactions), checked at
  container build; table prefix; XML mapping.
- **FR-ARCH-001**: layer rules (REQ-ARCH-001): Domain without Symfony / Doctrine / HTTP; Application without Symfony /
  Doctrine; optional packages only in bridges; standard, DDD and single-EM hosts.
- **FR-PORT-001**: host ports with safe defaults (tenant resolver, access checker, feature gate, plan limits, actor
  directory, audit sink, id generator, clock), configured by service id; panels and resolvers autoconfigured by tag.
- **FR-GATE-001**: every call checks tenant → feature gate → actor → record in the tenant → permission (404 / 403);
  deny by default; exactly the permission of the action.
- **FR-REC-001**: companies and contacts through `RecordServiceInterface` / `RecordInput`: validation, owners checked
  against the directory, versions, trash / restore / purge.
- **FR-REC-002**: contact e-mail unique per tenant (case-insensitive key).
- **FR-AFF-001**: affiliations with role and one primary company.
- **FR-TAG-001**: tags per tenant (unique case-insensitive name, UiKit colours), assignment with set semantics.
- **FR-FIELD-001**: custom fields per tenant and type (8 types, up to `fields.max_per_subject`, archive keeps values).
- **FR-NOTE-001**: plain-text notes (≤ 10 000), pinned first; others' notes need `manage`.
- **FR-TL-001**: append-only timeline with bundle and host entries.
- **FR-LINK-001**: host links by id, idempotent, resolved per view by host resolvers; reverse lookup.
- **FR-PANEL-001**: host panels on the 360° page; failures contained, transient storage failures rethrown.
- **FR-READ-001**: reads: record, filtered and sorted lists, duplicates hints, owners, tags, fields.
- **FR-MERGE-001**: merge with ordered row locks, version checks, child moves and a snapshot.
- **FR-IMP-001**: CSV / vCard import: preview, mapping, duplicate policy, claim, chunks, resume, cancel.
- **FR-EXP-001**: CSV export of a list (formula cells neutralised, `manage`) and vCard 4.0 (`view`).
- **FR-GDPR-001**: consent per contact and configured purpose, append-only.
- **FR-GDPR-002**: GDPR export and erasure of a contact (`manage`, idempotent, consent proof kept without evidence).
- **FR-GDPR-003**: retention purge per tenant settings.
- **FR-SET-001**: per-tenant settings, versioned.
- **FR-LIMIT-001**: plan-limit counters moved only by atomic UPDATEs; rebuild command.
- **FR-EVT-001**: events and audit entries after the commit, ids and counts only.
- **FR-CONC-001**: write units through transaction-doctrine ^1.6; lock conflicts 409 `CONFLICT_001` / `CONFLICT_002`
  with `X-Conflict-Code`; domain conflicts 409 with their own code; fallback listener (REQ-RUNTIME-003).
- **FR-SEC-006**: exact identifiers: VO rules, binary NO PAD collations, `===` re-checks (REQ-SEC-006).
- **FR-WORKER-001**: worker mode with kernel reset false: stateless services, per-call buffers, entity manager cleared
  (REQ-RUNTIME-002).
- **FR-UI-001**: opt-in UiKit + FormKit pages, route loader `nowo_crm_kit_ui`, CSRF on every POST.
- **FR-UI-002** (0.4.0): the import modal of the lists: UiKit `confirm_dialog` anatomy (wide), step fragments from the
  import routes (`ImportDialog`: `X-Nowo-Crm-Dialog`, 204 + next step, 401 `session_expired`), same access checks and
  CSRF tokens as the pages, the import page as the no-JS fallback, unfinished imports linked from the modal and the lists, the script `js/nowo-crm-import.js` (asset package
  `nowo_crm_kit`).
- **FR-SEARCH-001**: SearchKit providers for companies and contacts (bridge).
- **FR-API-001** (0.3.0): the `Api\Operation` layer: one operation catalogue (`CrmApiOperation`), typed inputs
  (`Api/Input/*`), read models (`Api/Read/*`), cursor paging, guard order tenant -> grant -> record, error codes
  (`CrmApiCode`, `CrmApiRefusal`); write units through the same services as the pages.
- **FR-API-002** (0.3.0): idempotent writes: `Idempotency-Key`, stored claim and replay (`ApiIdempotency`, store port,
  Doctrine store), `RecordVersion` checks, purge command `ApiPurgeIdempotencyCommand`.
- **FR-API-003** (0.3.0): the opt-in HTTP edge: attribute-free route loader, controller, authenticator and tenant
  resolver ports, exception listener mapping refusals to HTTP status and `CONFLICT_001` / `CONFLICT_002`.
- **FR-PIPE-001** (0.5.0): pipeline: funnels, stages, deals (decimal `Money`, lost reasons), party stages, next actions,
  stage history, funnel statistics, housekeeping; entities `Funnel`, `Stage`, `Deal`, `PartyStage`, `NextAction`,
  `StageChange` (`doctrine-pipeline` mappings).
- **FR-PIPE-002** (0.5.0): next actions as tasks through `CrmTaskPort`: built-in port, optional TaskBoard bridge.
- **FR-ACCESS-001**: house access pattern (REQ-UI-002): security keys, `CrmKitAccessChecker` implementations,
  `*_ACCESS_CHECK` / visibility events, `CrmKitGuard`, roles (`RoleAssignment`), web UI security pass.
- **FR-GDPR-004**: actor personal data (anonymise / export of actor ids) and the purge claim.
- **FR-READ-002**: batch lookups and catalogue reads for host panels (parties, roles and tags).
- **FR-UI-003** (0.5.0): pipeline pages, forms, row actions, stylesheet `nowo-crm-kit.css`.
- **FR-SEARCH-002** (0.6.0): SearchKit providers for deals and note hits (bridge).
- **FR-I18N-001**: translations in en, es, it, fr, pt, de, nl (REQ-I18N-002).
- **FR-HUB-001** (0.10.0): one HubSpot credential per tenant (service key or private app token), checked against
  HubSpot (account, read-only scopes) before it is sealed; connect / replace / disconnect need `import` + `manage`,
  rate-limited per tenant (`connect_rate_limit`); only the last four characters are shown.
- **FR-HUB-002** (0.10.0): HubSpot import of companies, contacts, associations, owners and lists on the import queue:
  start, mapping, confirm, one page per write unit, references (`crm_external_ref`), unattended runs only for the
  confirmer's own or ownerless records; the start and mapping pages and the confirm are rate-limited per tenant
  (`browse_rate_limit`, 0.10.1).
- **FR-SECRET-001** (0.10.0): the secret box port (`CrmSecretBoxInterface`): sodium default, DoctrineEncrypt bridge with
  key ids and previous profiles (0.10.1); a bad value marks the connection invalid, an unavailable key keeps it;
  `secrets:rotate` never loses a token.
- **FR-MSG-001** (0.10.0): background import runs with Symfony Messenger (`import.messenger`).

## Success criteria

- **SC-001**: 100 % statement and element coverage of `src/` (REQ-TEST-003), PHPStan level max with the FrankenPHP
  rulesets and Igor green.
- **SC-002**: the database suite passes on SQLite, MySQL 8.4, MariaDB 11.4 and PostgreSQL 16; the two-connection race
  tests pass on the three servers.
- **SC-003**: the default access checker denies every call; another tenant's ids always answer 404.
- **SC-004**: after an erasure nothing the GDPR export returned remains readable.
- **SC-005**: the worker test (120 requests, two tenants, two actors) shows no crossed data, no managed entity after a
  request and < 8 MiB memory growth.
- **SC-006**: `code-inventory.md` lists every file of `src/`.

## Key entities

`Company`, `Contact` (mapped superclass `CrmRecord`), `Affiliation`, `Tag`, `Tagging`, `FieldDefinition`, `Note`,
`Activity`, `Consent`, `HostLink`, `Import`, `ImportRow`, `MergeRecord`, `Counter`, `CrmSettings`: 15 tables
`<prefix>crm_*`.

## Non-goals (phase A)

The pipeline, quotes, invoices, campaigns, customer portal and reports (phases B–G); a public JSON API (phase B);
attachments (phase B, StorageKit); a Messenger import handler; a link picker using `CrmHostObjectResolverInterface::search()`.
Never: telephony, social inboxes, AI scoring, marketing journeys, an accounting ledger, card payments, native apps.
