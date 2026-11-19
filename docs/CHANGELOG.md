# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
  resolver returns), an owner input or a visible owner added by a listener; a signed-in person is never the demo's
  shared actor `anonymous` either.
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
