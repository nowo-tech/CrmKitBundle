# Security

## Table of contents

- [Status and scope](#status-and-scope)
- [Security model](#security-model)
- [Permission matrix](#permission-matrix)
- [Tenant isolation and exact identifiers](#tenant-isolation-and-exact-identifiers)
- [Forms, CSRF and output](#forms-csrf-and-output)
- [Imports and exports](#imports-and-exports)
- [GDPR](#gdpr)
- [Host extension points](#host-extension-points)
- [Shared entity manager](#shared-entity-manager)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)
- [Reporting](#reporting)

## Status and scope

0.1.0 ships phase A (companies and contacts); 0.2.0 adds party roles, the company's primary contact and affiliation
purposes (contract section 6.8) with the same model: no new permission, deny by default. The security model is contract sections 5 (value objects), 6.1
(exact identifiers), 11.3 (errors), 13 (permission matrix), 15 (concurrency, [CONCURRENCY.md](CONCURRENCY.md)) and 16
(GDPR).

| Input | Handling |
| --- | --- |
| Configuration | Strict tree (unknown keys refused); `access.roles` empty by default (deny everything); later phases refuse `enabled: true` |
| Every service call | Tenant, feature gate, actor and permission checked in the application services, not only the controllers |
| Identifiers | Ids are canonical lowercase UUIDs; host references are 1–128 bytes, never trimmed or folded, compared with `===`; binary NO PAD collations |
| Form input | Trimmed, length- and kind-checked (e-mail, http(s) URL, phone, ISO country), control and format characters refused |
| Concurrent writes | One write unit per write; stale versions and lock conflicts answer 409 and store nothing ([CONCURRENCY.md](CONCURRENCY.md)) |
| Instants | Stored in UTC whatever the PHP default time zone (`nowo_crm_utc_datetime` DBAL type) |

## Security model

- **Deny by default.** The default access checker grants nothing until `access.roles` maps roles; without
  SecurityBundle it grants nothing at all. A host checker must return `false` for anything it does not grant.
- **Order of the checks, per call:** tenant (none ⇒ 404) → feature gate (off ⇒ 404) → actor (none ⇒ 404) →
  record lookup in the tenant (absent or another tenant's ⇒ 404) → permission (refused ⇒ 403). A user of another
  tenant can never learn whether an id exists: it is always 404, never 403.
- **Exactly the permission of the action.** `edit` and `manage` do not imply `view`; a record-level check passes the
  record (type, id, owner) so a host can grant "own records only".
- **No state between calls.** Tenant, actor and permissions are asked per call (FrankenPHP worker mode,
  [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md)).

## Permission matrix

| Action | view | edit | manage |
| --- | --- | --- | --- |
| Lists, 360° pages, timeline, notes, links, panels, consents, duplicates, vCard of one contact, header search | ✓ | | |
| Create / edit / trash / restore records, notes, tag assignment, **roles** (0.2.0), host links, affiliations with their purposes, consent records, import, merge (on both records), host timeline entries | | ✓ | |
| The company's primary contact (0.2.0): `edit` on the company (the contact needs `view`; through the affiliation form also `edit` on the contact; an import row that changes it also needs `edit` on the company, else `invalid`) | | ✓ | |
| Edit or delete another person's note | | ✓ + ✓ | ✓ |
| Tag and field administration, settings, purge from the trash, CSV export of lists, GDPR export and erasure | | | ✓ |

`amounts_view` and `campaigns_send` exist for phases B–E and are not asked in 0.1.0. Notes: the author edits and
deletes their own notes with `edit` on the record; another person's note needs `edit` on the record and `manage`.

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

## Imports and exports

- Uploads are parsed in memory before any transaction: UTF-8 only, at most `import.max_bytes` bytes and
  `import.max_rows` rows; the file itself is not stored, only its parsed rows (`crm_import_row`), which the retention
  purge deletes `import_keep_days` after the import finished.
- CSV cells are read as text: nothing is evaluated; every value goes through the same validation as the form.
- **CSV injection**: the CSV export prefixes every cell that starts with `=`, `+`, `-`, `@`, a tab or a carriage return
  with `'` and quotes every cell (RFC 4180). The export needs `manage` (bulk personal data).
- vCard output escapes `\`, `,`, `;` and line breaks and drops NUL bytes.
- Downloads (CSV, vCard, GDPR JSON) are sent with `Cache-Control: no-store, private`.

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
- **Retention** (`nowo:crm-kit:purge`): trashed records after `trash_days` with every child row, timeline entries
  after `timeline_years`, import rows after `import_keep_days`, merge snapshots after `merge_keep_days`; one audit
  entry per tenant with counts only.
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
