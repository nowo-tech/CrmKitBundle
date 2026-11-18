# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

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
  resolver shows the resolver's label and URL, or is hidden when the resolver does not return it; a type without a
  resolver shows the stored label without a URL.
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
  the other record becomes a `merged` tombstone, a snapshot kept in `crm_merge`.
- CSV and vCard 3.0 / 4.0 import (`ImportServiceInterface`: upload, preview, confirm, run, cancel): UTF-8, BOM
  stripped, `,` or `;`, header row, at most `import.max_rows` rows and `import.max_bytes` bytes; detected and editable
  column mapping (core fields, `custom.<key>`, `tags`, and `company` for contacts); duplicate policy `skip` or
  `update` by contact e-mail; outcomes `created`, `updated`, `duplicate`, `invalid`, `limit`. The confirm claims the
  import with a conditional UPDATE and runs chunks of `import.chunk_rows` in the request up to `import.sync_rows`; the
  page then offers "Continue", and `nowo:crm-kit:import:resume` finishes imports whose claim went stale.
- Exports (`CrmExporterInterface`): CSV of every record matching a list's filters (keyset batches, streamed, cells
  starting with `=`, `+`, `-`, `@`, tab or CR prefixed with `'`; `manage`) and a vCard 4.0 of one contact (`view`).
- GDPR: consent per contact and purpose (`ConsentServiceInterface`, append-only, purposes from `gdpr.purposes`),
  JSON export of a contact and erasure (`CrmPersonalDataInterface`, `manage`; idempotent; consent rows kept without
  evidence), retention purge `nowo:crm-kit:purge` (trash after `trash_days`, timeline after `timeline_years`, import
  rows after `import_keep_days`, merge snapshots after `merge_keep_days`; `--tenant`, `--dry-run`).
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
- Concurrency (REQ-RUNTIME-003): write units through `nowo-tech/transaction-doctrine` ^1.6, lock conflicts answered 409
  with `CONFLICT_001` + `Retry-After` + `X-Conflict-Code` (or `CONFLICT_002`), a fallback listener when the transaction
  bundle is absent; two-connection race tests on MySQL 8.4, MariaDB 11.4 and PostgreSQL 16
  ([CONCURRENCY.md](CONCURRENCY.md)).
- Worker mode (REQ-RUNTIME-002): stateless services, the private entity manager cleared after every unit and at the
  end of every request and command ([FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md)).
- Binary NO PAD collations on every id, reference and key column (MySQL 8 `utf8mb4_0900_bin`, MariaDB
  `utf8mb4_nopad_bin`) and `===` re-checks in PHP (REQ-SEC-006).
- Translations in en, es, it, fr, pt, de and nl.
- Scaffold: Composer, Docker (MySQL 8.4, MariaDB 11.4, PostgreSQL 16, SQLite), Makefile with `release-check`, CI,
  PHPStan with phpstan-frankenphp, Igor, Rector, CS, 100 % coverage gate, Spec Kit baseline, Flex recipe (private
  entity manager), demos for Symfony 7.4 and 8.1, strict configuration tree with the switches of the later phases.
