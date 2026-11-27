# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
- [[0.12.0] - 2026-11-27](#0120---2026-11-27)
- [[0.11.0] - 2026-11-27](#0110---2026-11-27)
- [[0.10.3] - 2026-11-25](#0103---2026-11-25)
- [[0.10.2] - 2026-11-24](#0102---2026-11-24)
- [[0.10.1] - 2026-11-24](#0101---2026-11-24)
- [[0.10.0] - 2026-11-23](#0100---2026-11-23)
- [[0.9.0] - 2026-11-23](#090---2026-11-23)
- [[0.8.1] - 2026-11-23](#081---2026-11-23)
- [[0.8.0] - 2026-11-23](#080---2026-11-23)
- [[0.7.0] - 2026-11-23](#070---2026-11-23)
- [[0.6.1] - 2026-11-22](#061---2026-11-22)
- [[0.6.0] - 2026-11-22](#060---2026-11-22)
- [[0.5.0] - 2026-11-22](#050---2026-11-22)
- [[0.4.2] - 2026-11-21](#042---2026-11-21)
- [[0.4.1] - 2026-11-21](#041---2026-11-21)
- [[0.4.0] - 2026-11-21](#040---2026-11-21)
- [[0.3.0] - 2026-11-20](#030---2026-11-20)
- [[0.2.1] - 2026-11-19](#021---2026-11-19)
- [[0.2.0] - 2026-11-19](#020---2026-11-19)
- [[0.1.0] - 2026-11-19](#010---2026-11-19)

## [Unreleased]

Planned as **0.12.1** (patch, follow-ups of audit ZDE-v). No BC break, no schema change.

### Fixed

- HubSpot activities import (ZDE-v-1, Q-H6): the erased-person check looks up every contact id HubSpot gives inline
  with an item, not only the first 20 used as records, so an item associated with more than 20 contacts whose erased
  person comes later is skipped too (`error.record_erased`). `HubSpotEngagement::$allContacts` (read model) holds them.

### Documentation

- SECURITY: erasures tombstone HubSpot references only while `import.hubspot.enabled` is on (the reference table is
  mapped only then); what to do when the import was switched off (ZDE-v-2).

## [0.12.0] - 2026-11-27

Planned as **0.12.0** (Beacon spec 254 T004, FR-100 block A; ships before A-2 templates, which waits for MailKit stored templates and moves to 0.13.0). No BC
break, no schema change, no new configuration key and no new HubSpot scope.

### Added

- **HubSpot import of activities**: notes (→ notes), calls and meetings (→ logged activities, `activities.enabled`)
  and tasks (→ next actions on the built-in list, `pipeline.enabled` too), offered in the start step when the
  configuration imports them (`HubSpotImportScope::$activities`, new enum `HubSpotActivity`; an import may read
  activities only). One `GET /crm/v3/objects/{kind}?associations=contacts,companies` per page with fixed properties;
  the item lands on the first associated contact of the same account (else company), a call or meeting also on the
  other associated records (subject rows, meeting contacts as attendees). HTML bodies become plain text, owners map
  to authors and assignees by e-mail, the HubSpot id is an external reference (`note` / `call` / `meeting` / `task`)
  and a next action's dedupe key is derived from it, so re-runs update and never duplicate; empty values never clear,
  associations only add. Rows `import.hubspot.no_record`, `import.hubspot.activities_off`, `import.hubspot.tasks_off`;
  input error `error.hubspot_activities_off`; 7 locales. Same access rules as records (attended `edit`, unattended
  owner rule), same pages, limits (`max_records` per kind), pauses and resume. Read with the already required
  `crm.objects.contacts.read` (granular `crm.objects.{notes,calls,meetings,tasks}.read` accepted).
- `NextAction::revise()` (title, due time, assignee) for re-runs; `HubSpotRecordWriter::targets()` is now callable by
  the engagements writer (internal).

### Changed

- The `crm.hubspot.import_started` audit entry also counts the activities (`activities`).
- `HubSpotRecordWriter::targets()` loads the first hop of every reference in one query (audit ZDE-L1).

### Security

- Pre-tag audit ZDE (unreleased code, no GHSA): the records an imported activity also shows on and a meeting's
  attendees need the same access as the records import's associations (`view` attended, owner rule unattended),
  others are skipped and counted (ZDE-M1); an item associated with an erased contact is skipped
  (`error.record_erased`) and an erasure tombstones the HubSpot references of the contact's deleted notes, activities
  and next actions (`ExternalRefHooksInterface::tombstoneItems()`, internal), so a re-run never brings them back
  (ZDE-M2, Q-H6); at most 20 associated ids per kind and 25 items per engagement page (ZDE-L1, ZDE-L2); an HTML body
  past PCRE's backtracking limit keeps its text (ZDE-I1); unattended runs pick the first writable record instead of
  pausing on the first one owned by someone else (ZDE-I2).

### Fixed

- Tests only: `ApiModelTest::testSealedCursors` sealed the value `Ada` and asserted that the random base64 cursor
  does not contain it, which failed now and then by chance; it now seals a long unique marker.

## [0.11.0] - 2026-11-27

Planned as **0.11.0** (Beacon spec 254, block A-1, tasks T001 and T002; Beacon priority moves it before H2). No BC
break: everything new is behind `activities.enabled` (off by default); with it off no table is mapped and no route or
service is added (the runner's lost-savepoint hardening and one more purge count apply to every host).

### Added

- **Logged activities** (`activities.enabled`): calls (direction, outcome from `activities.call_outcomes`, duration,
  summary, notes), meetings (title, start / end in an IANA time zone, place or https video link, members and contacts
  as attendees, notes, cancelled) and e-mails (direction, subject, plain-text excerpt of at most 500 characters) on a
  company or contact, through `EngagementServiceInterface` (`log` / `edit` / `delete`): `edit` on the record, `manage`
  too for someone else's activity, optimistic versions (409 `crm_record_stale`), the record locked `FOR UPDATE` in each
  write, a timeline entry (`call`, `meeting`, `email`, `activity_edited`, `activity_deleted`), audit entries
  `crm.activity.logged` / `edited` / `deleted` and the events `EngagementLogged` / `EngagementChanged` /
  `EngagementDeleted` after the commit. Tables `crm_engagement`, `crm_engagement_subject`, `crm_engagement_attendee`
  (mapping group `NowoCrmKitBundleActivities`).
- **Association fan-out**: a contact's activity also shows on the contact's companies and open deals, a company's on
  its open deals (stored when it is logged). An activity shows only while the viewer may view its primary record.
- **Activity feed** of a company, contact or deal (`ActivityFeedInterface::feed()`): notes, activities and host items,
  filtered by kind (`FeedKind`: note, call, meeting, email, host) and person (`TimelineFilter`), newest first in keyset
  pages (`TimelineWindow`). On the 360° page: the "Activities" card with the log openers (the forms load on demand
  into one UiKit remote dialog; plain pages without JavaScript), the filter and "Show older".
- **Pinned notes**: `PinnedNotesInterface::pin()` (`edit` on the record) and pin / unpin buttons on the notes card;
  `activities.max_pinned` (default 1; 0 = no limit) unpins the oldest pinned notes of the record in the same write.
- **@mentions**: with MilkdownEditor (>= 1.19) installed and `activities.mentions.enabled`, the note field is
  MilkdownEditor's with the CRM's `@` candidates (`MentionDirectoryInterface`: members of the same tenant who may view
  the record, `CrmRecordAudienceInterface`) and names; handles are keyed hashes, never ids or e-mail addresses. The
  members newly mentioned by a save are notified after the commit (`NoteMentioned`, `CrmNotifierInterface`; the
  NotifyKit bridge with NotifyKit >= 0.3 installed, type `crm.mention`, no record name or note text in it).
- **Host activity source** (T002): `CrmHostActivitySourceInterface` (`activities.host_source`) adds the host's items
  (tasks, time entries, linked mail, tickets) to the feed; the kit drops items of another record or outside the page.
- **Meetings on TaskBoard's calendar** (`Bridge/TaskBoard/CrmMeetingCalendarSource`, TaskBoard >= 2.24,
  `activities.taskboard_calendar`): the signed-in person's meetings on records they may view, read-only.
- **`.ics` download** of a meeting (view on its record; UID, SEQUENCE that moves with every change of time, place,
  title or cancellation, STATUS; no notes or addresses). MailKit has no invite part yet: invites by e-mail are a
  follow-up (MailKit 2.3, `METHOD:REQUEST` / `CANCEL`).
- **SearchKit** group `crm_activities` (titles of calls, meetings and e-mails on visible records).
- **GDPR**: a contact's export lists its activities and the meetings it attends; erasure deletes its activities and
  its attendance; a purged record takes its activities along; activities older than `timeline_years` are deleted by
  `nowo:crm-kit:purge`; `anonymiseActor()` covers activity authors and member attendees.
- Audit ZCR fixes before the tag: the feed reads past hidden activities (no early end), the mention candidates match
  names (or an exact e-mail address) only, a per-person limit on the mention endpoints (429), a meeting edit keeps the
  attendee contacts the editor cannot see, anonymisation rewrites the member's mention handles, a container warning when
  `record_access_check` listeners exist without `activities.audience`, a narrower export of meetings on other records.
- Configuration `activities.*` (CONFIGURATION.md), translations in the seven locales, the TaskBoard calendar, NotifyKit
  and MilkdownEditor test stand-ins.

### Fixed

- Versions up to 0.10.3: after a MySQL / MariaDB deadlock inside the ORM flush's savepoint, the rollback could fail
  with 1305 (lost savepoint), take the place of the deadlock and leave the connection's nesting level above 0, so on a
  kept-alive worker later CRM writes failed with "writes in transactions of its own" until a restart. The runner now
  repairs the connection (see "Changed"); the deadlock stays in the exception chain (replay, else 409).

### Changed

- REQ-RUNTIME-003 lost-savepoint pattern in `DoctrineTransactionRunner`: a failed unit is repaired by
  transaction-doctrine's `ConnectionRepairer` (rolls back to the level the unit started at; closes the connection, with
  a warning, when that rollback fails; clears a rollback-only flag) and the original failure is rethrown. The private
  entity manager is closed on a failure as before.
- The HubSpot check limits and the mention endpoints share one fixed-window counter (`DoctrineCounterWindow`).
- `RecordPurger::purge()` returns an `engagements` count (also in a dry run) (0 while activities are off); the purge command prints
  "logged activities" only when some were deleted.

### Documentation

- `docs/images/demo/` keeps only the two README screenshots (REQ-DEMO-013: 1-3 images, no gallery). The 68 before /
  after review captures of 0.10.0 and 0.10.2 (`crm-import-{before,after}-*`, `crm-filters-after-*`) are removed from
  the tree (they stay in the git history); the filter review spec now writes to the git-ignored
  `demo/symfony8/e2e/test-results/review-shots/` under its own Playwright project `review-shots`, and `make
  demo-screenshots` runs only `screenshots.spec.ts`.
- Tables of contents: `docs/ROADMAP.md` gets one, `docs/CONFIGURATION.md` lists `Key rotation` (REQ-DOCS-005).

## [0.10.3] - 2026-11-25

Planned as 0.10.3: the steps of the import modal opened directly land on their list with the modal open on that step
(Beacon D-20261124-05, UiKit 2.74 `remote_dialog.direct_get`), and every step of the modal is laid out to Beacon's
dialog standard (UI-PATTERNS §16). No BC break; the new option is off by default.

### Added

- `import.dialog.direct_get` (`off` | `redirect`, default `off`). With `redirect` (and `ui.enabled`), a step URL of the
  import modal opened from the address bar, a bookmark or another site — the CSV / vCard file step (`/import`), the
  steps of an import (`/import/{id}`: columns, preview, result) and, with `import.hubspot.enabled`, the HubSpot source,
  connect, start and mapping steps — answers a `302` to the companies or contacts list with the modal reopened on that
  step. The bundle prepends the UiKit `remote_dialog.direct_get.routes` (dialog `crm-import-dialog`); a host entry
  replaces one whole and `<route>: false` unmarks it. The controller's access check runs first: a denied person keeps
  the 403 / 404, never the redirect. Links followed inside the app keep the page (the no-JS fallback); the modal's own
  fetches and every POST (422 re-renders included) are unchanged.
- `ImportDialogPageResolver` (UiKit `page_resolver`, internal): the file step goes to the list of its `?subject=`, the
  steps of an import to the list of the import's subject (read with the person's access checked again; refused,
  unknown or another tenant's import = no redirect, no oracle); the HubSpot steps go to the companies list.
- The import modal script reads UiKit's reopen contract on the list (`data-nowo-ui-remote-form-reopen-*`): it opens the
  modal on the step the server named, only for a route of the safe list that equals the server's and a same-origin
  path, then removes the reopen query from the address (`history.replaceState`).
- Demo (Symfony 8): a fake in-memory HubSpot account (`App\Crm\DemoHubSpot`, credential `demo-hubspot-key`), the
  direct-GET redirect on, and before / after screenshots of every step at desktop and 390 px
  (`docs/images/demo/crm-import-{before,after}-*.webp`).
- 7 translation keys in the 7 locales (`import.hubspot.card.title`, `import.hubspot.mapping.*`).

### Changed

- Dependencies: `nowo-tech/ui-kit-bundle` ^2.74 (direct-GET page parameters and resolvers).
- Import modal layout (UI-PATTERNS §16): one primary per step, on the right of the footer with an icon and its label
  (*Next*, *Preview*, *Import*, *Continue*, *Check and save*, *Start an import*, *Connect HubSpot*); no primary in the
  body. The HubSpot connection is UiKit's detail list under a heading; *Replace the key* and *Disconnect* are secondary
  actions in a section of their own. The HubSpot mapping is a table per object (*HubSpot property* | *CRM field*),
  then the lists and the options, each a section with a heading and the dialog's divider; it stays readable at phone
  width without sideways scrolling. A mapping select is labelled by the property alone (the table caption names the
  object). The no-JS pages keep their layout.

## [0.10.2] - 2026-11-24

Planned as 0.10.2: every list of the kit (Companies, Contacts, Deals, Tags, Custom fields, Funnels) follows Beacon's
filter standard (Beacon request 2026-11-24, UI-PATTERNS §3, §4, §7, §8 and §11). No BC break in the public PHP API; the new `ui` keys are optional, and
UPGRADING lists the visible changes of the filter bars.

### Added

- `ui.filters_more` (`companies`, `contacts`, `deals`): the filter fields folded under UiKit's **More filters**
  disclosure after Filter / Clear filters. It shows how many of them are set and stays open while one is. Defaults:
  Companies role and dates; Contacts role, purpose, primary contact, company and dates; Deals stage, company,
  contact, stale and expected close. `[]` for a list = no fold.
- `ui.per_page_position` (`filters` | `pagination`), `ui.per_page_row_class` and `ui.search_width`: where the compact
  *N per page* select sits (first in the filter actions, or in UiKit's compact pager), a host class for its row
  (e.g. `issue-filters__pager`), and the search width (default one grid column).
- Filters: owner **Mine** (the acting person, through the actor resolver) and **No owner** on Companies, Contacts and
  Deals; created and changed day ranges on Companies and Contacts (native date fields, `ListQuery::$createdFrom` …
  `$updatedTo`, whole UTC days); company on Contacts; stage, owner, company, contact and an expected close range on
  Deals (`DealQuery::$ownerMe`, `$ownerNone`, `$expectedCloseFrom`, `$expectedCloseTo`). `ListQuery::forActor()` and
  `DealQuery::forActor()` resolve *Mine*; an unresolved *Mine* matches nothing.
- Sortable column headers: *Created* on Companies and Contacts; *Title*, *Amount*, *Expected close* and *Updated* on
  Deals, both directions (`DealQuery::SORT_TITLE`, `DealQuery::SORTS`, `DealQuery::$direction`; rows without an
  amount or a close date stay last). Deals get the *N per page* select too and numbered pages (below).
- Empty states (§11): on the list defaults the message says why the list is empty and offers create with **Import**
  next to it; with `import.hubspot.enabled` the hint mentions HubSpot. With a filter set: *Nothing matches these
  filters* and **Clear filters**. The bundle prepends its own UiKit `empty` partial for the NowoCrmKitBundle domain
  (`@NowoCrmKit/partials/_empty.html.twig`; a host entry wins).
- Deals: numbered pages with the total (*Showing 1–25 of N*, UiKit pager) instead of the keyset *Next page* link
  (`DealReader::page()`, `DealListResult`); a page past the end shows the last page. The keyset reader stays for
  the API.
- Tags, Custom fields and Funnels: every column sorts (in memory, both directions); Funnels gets the filter card
  (name search, *N per page*) and the pager like Tags and Custom fields.
- Empty states of every list: one sentence saying why it is empty, and the create action(s) (Custom fields: both;
  Deals without a deal funnel: *Open the funnels*), or *Nothing matches these filters* with **Clear filters** when
  the search or a filter is set. `UiSupport::emptyState()` / `UiSupport::sortRows()` share it.
- 30 translation keys in the 7 locales (`list.filter.*`, `deal.filter.*`, `list.empty_hint_hubspot`,
  `list.no_match_filters`, `list.col.created`, `error.owner_filter`, `tags.empty_hint`, `fields.empty_hint`,
  `funnels.empty_hint`, `deals.empty_hint`, `deals.empty_no_funnel`, `deals.open_funnels`).

### Changed

- Dependencies: `nowo-tech/ui-kit-bundle` ^2.73 (the 2.72 filter standard: no duplicate select classes, icon-only
  Filter / Clear filters at field height, the compact pager field, More filters, the phone sheet).
- The search field is one grid column wide (was two); `ui.search_width: lg` restores it.
- *Rows per page* is compact (*25 per page*), first in the filter actions, named *Results per page* for assistive tech
  (visually hidden label); it no longer takes a whole grid track.
- Contacts: *Primary contacts only* is a checkbox (same `primary_contact=1` query) instead of a select whose
  placeholder was cut.
- Deals: the search has a placeholder and an `aria-label`; the funnel placeholder reads *Any funnel*; the *Sort*
  select is gone (sortable headers instead; `?sort=` still works and `direction` joins it). The `sorts` option of
  `DealFilterType` is deprecated and ignored.
- List header (§3): one primary create, then **Import** (secondary, with an icon) and *Export CSV*. While a list is
  empty and unfiltered, the create button is the empty state's, not the header's (one create button), on every
  list. A search on Tags / Custom fields now reads *Nothing matches these filters* (was *Nothing matches the
  search*).
- Funnel statistics: the filters use the standard bar (icon-only Filter / Clear filters); `at` is a native date and
  the snapshot is taken at the end of that day in the application's time zone (now, for today).
- Demo (Symfony 8): UiKit's built-in SVG icons, icon-only filter buttons and the phone filter sheet, as Beacon draws
  them; screenshots `docs/images/demo/crm-filters-after-*.webp`.

## [0.10.1] - 2026-11-24

Planned as 0.10.1 (patch): the follow-ups of audit ZAM and its delta. No BC break in the public PHP API or config;
the new keys have defaults that keep 0.10.0's behaviour.

### Added

- `import.hubspot.browse_rate_limit` (`limit: 60`, `interval: 600`): HubSpot reads of the start and mapping pages
  per tenant (ZAM-9).
- `secrets.doctrine_encrypt` (`profile`, `key_id`, `previous`): the DoctrineEncrypt box's profiles, so its key can be
  rotated (ZAM-D2). `DoctrineEncryptCrmSecretBox` takes two optional constructor arguments, `$keyId` and `$previous`.
- `SecretKeyUnavailableException`: the key a sealed value names is not configured or cannot be used.
- REQ-ASSETS-005: `nowo-crm-import.js` logs one `📦 [crm-kit/import] script loaded, build: …` line per page (once-guard,
  build time from the committed `build-info.json`). Silence it with `<html data-nowo-console="quiet">` or
  `globalThis.NOWO_CONSOLE = 'quiet'` (USAGE, "Browser console").
- REQ-MSG-001: `RunCrmImportHandler` is declared with `#[AsMessageHandler]` and takes an optional PSR logger. It logs
  the message type, the import id and the outcome, and a warning when it drops a message whose tenant id is not valid.

### Changed

- Dependencies: `nowo-tech/form-kit-bundle` ^2.25.1, `nowo-tech/transaction-contract` ^1.1,
  `nowo-tech/transaction-doctrine` ^1.10.1, `nowo-tech/ui-kit-bundle` ^2.71 (twig 3.31 in the locks; the demo's lock
  follows).
- `NextActionService::set()` names transaction-contract's `ConcurrencyConflict`. transaction-doctrine 1.10 aliases
  its old class name to it, so callers catch the same class. Deptrac's last `skip_violations` entry is gone.
- REQ-RUNTIME-002: with `import.messenger.enabled`, the entity-manager hygiene listener also runs on Messenger's
  `WorkerMessageHandledEvent` / `WorkerMessageFailedEvent`, so every message in a worker starts with an empty manager.
- The DoctrineEncrypt box probes its key only after a decryption failed (audit ZAWF-I2: Defuse no longer derives its
  key twice per successful unseal); the classification is unchanged.

### Fixed

- ZAWD-L1: the "script loaded" once-guard of `nowo-crm-import.js` is UiKit 2.71+'s: a clobbered, read-only or
  cross-origin `window.__nowoScriptBanners` (DOM named access) falls back to a module set and can no longer throw
  before the import modal starts.
- ZAM-D2: the DoctrineEncrypt box can now be rotated without losing tokens. Add a DoctrineEncrypt profile with the new
  key, seal with it under a new `key_id`, and list the old key id and profile under `previous`. The old tokens keep
  working, and `nowo:crm-kit:secrets:rotate` re-seals them.
- ZAW-L1: when a decryption fails, the DoctrineEncrypt box loads the key in its own step (it encrypts a constant,
  non-secret probe value). A key that is missing, unreadable, malformed or of the wrong length (Halite `InvalidKey`,
  `CannotPerformOperation`, a bad Defuse key) is now "key not available": the connection stays active. Only the
  value failures (Halite `InvalidMessage` / `InvalidSignature` / `InvalidDigestLength` / `InvalidType`, Defuse
  `WrongKeyOrModifiedCiphertextException` / `BadFormatException`, `DecryptionFailedException`, `SodiumException`,
  an encoder's `RangeException`) still mark a token as not opening. CONFIGURATION says the key id must change whenever
  the key or profile does.
- ZAW-I5: `secrets:rotate` reports connections that are already invalid and whose token does not open on their own
  line. They no longer fail the run.
- UPGRADING (docs, ZAW-I1, ZAW-I3): the rotation steps now say to restart every worker, consumer and cron host, and
  to rotate once more before removing the old key. The consent normalisation only rewrites values that become valid,
  says what to do with the rest, and warns that the ALTERs rebuild the tables.
- Docs: CONFIGURATION "Timeouts" (REQ-RUNTIME-001: HubSpot calls per request, the hierarchy up to PHP, the proxy write
  timeout and FrankenPHP `max_wait_time`), DEMO-FRANKENPHP "Timeouts", SECURITY "Content-Security-Policy"
  (REQ-UX-001), an up-to-date FRANKENPHP-WORKER-AUDIT (0.10.1), PER-CS in PSR.md and the agent rules, the README
  status and TS coverage, the SPEC-DRIVEN-DEVELOPMENT scope through 0.10.1, and the binary comparison in the UPGRADING
  consent normalisation (ZAWF-I1).
- UPGRADING (docs): the 0.10.0 schema notes missed `nowo_crm_consent.source`. That column became a binary exact key on
  MySQL / MariaDB when `source` joined `CrmMetadataListener::EXACT_COLUMNS`. The notes also gave `utf8mb4_bin`
  instead of `utf8mb4_0900_bin` for the new MySQL 8.4 columns. UPGRADING "Unreleased" now has the check query and the
  ALTERs for MySQL 8.4, MariaDB 11.4, PostgreSQL 16 and SQLite, which also apply to hosts already on 0.10.0.
- ZAM-D1: SECURITY.md now says where `HubSpotToken` keeps the plaintext: in a suspended generator's frame owned by the
  object.

### Security

- ZAM-D2: `nowo:crm-kit:secrets:rotate` never loses a token. It writes a new seal only after the seal opens again to
  the same token. It leaves unchanged any token that does not open, whose key is not available, or that cannot be
  re-sealed. It no longer marks connections invalid, and it exits with 1 while any token is left unchanged.
  `--dry-run` re-seals in memory, so it also proves that the new key works.
- ZAM-D2: if a token's key is not configured (in the sodium or DoctrineEncrypt box), or a DoctrineEncrypt profile or
  key file fails, the token is no longer taken for a bad value. The import refuses with
  `error.hubspot_key_unavailable`, or a run pauses with `hubspot_key_unavailable`. The connection and its sealed token
  are kept. A connect whose seal fails stores nothing. Saving a new token resumes such paused imports.
- ZAM-D4: the DoctrineEncrypt profiles that the kit wires (`profile` and `previous`) must use Halite or Defuse and
  must exist. Otherwise the container build fails; before, the refusal came at the first seal.
- ZAW-L2: `confirm()` checks the import's version, the mapping's keys and its lists before it asks HubSpot, then
  counts against `browse_rate_limit`. Repeated stale or invalid confirms no longer reach HubSpot.
- ZAW-I2: an unavailable secret key is logged at error level with the key id and the class of the cause, never its
  message or trace. This applies to the import, the runner, connect and `secrets:rotate`; the services take an
  optional PSR logger.
- ZAM-9: once `browse_rate_limit` is reached, the start and mapping pages answer `error.hubspot_browse_limited` and
  do not ask HubSpot. Reloads can no longer burn the portal's shared daily API quota. The confirm step counts too
  (audit ZAW-L2), after its local checks: a stale version or an invalid mapping is refused before any HubSpot call.

### Internal

- REQ-GH-002 / REQ-GH-003: Dependabot watches the npm toolchain in two groups, and `dependabot-assets.yml` rebuilds the
  committed import script and `build-info.json` on those PRs. Hardened before release (audit ZAWD-M1): the build job
  is read-only (no persisted credentials, `pnpm install --ignore-scripts`) and the commit job, the only one with write
  access, never installs or builds. The commit job then dispatches `ci.yml` (now `workflow_dispatch`-able) so the
  required checks run on the rebuilt head (ZAWD-L2).
- REQ-CS-009: PHP-CS-Fixer uses `@PER-CS` and `@PER-CS:risky` instead of `@PSR12`.
- REQ-SPECKIT-001 / 003: the code inventory lists every file under `src/` (506 at 0.10.1).

- **Deptrac layer check** (REQ-ARCH-002): `deptrac.yaml` with a strict ruleset (Domain / Application framework-free,
  uncovered dependencies and unassigned classes fail), `composer deptrac`, `make deptrac`, part of
  `make release-check` and of the CI PHPStan job; it complements `LayerDependencyTest`. See
  [ARCHITECTURE.md](ARCHITECTURE.md#architecture-checks).
- Fixes from the first run, behaviour unchanged: forms, the UI support class and the SearchKit bridge read the
  translation domain from `Domain\TranslationDomain::NAME` and the FormKit profile from
  `Infrastructure\Symfony\Form\FormProfile::NAME` (`NowoCrmKitBundle::TRANSLATION_DOMAIN` / `FORM_PROFILE` keep the
  same values).
- One legacy dependency stays in `skip_violations` until 1.0.0 (`NextActionService` on transaction-doctrine's
  `ConcurrencyConflict`).

## [0.10.0] - 2026-11-23

Planned as 0.10.0.

### Added

- **HubSpot import, slice H1** (`import.hubspot.enabled`, off by default; design `docs/design/HUBSPOT_IMPORT.md`, owner
  answers Q-H1..Q-H13 as recommended, Beacon request 2026-11-23 for service keys). A third import source next to CSV
  and vCard, on the same queue (claim, counters, unfinished list, resume command, cancel, plan limits, duplicate
  policy, import modal):
  - one credential per tenant: a HubSpot **service key (or a legacy private app token)**, no prefix or shape rule. It
    is checked before it is saved, the same way for both kinds: the account through `account-info`; the scopes from
    HubSpot's token info when HubSpot knows the credential, else by probing each required read endpoint (`limit=1`,
    a 403 means the scope is missing). It is refused when a required read scope is missing, when HubSpot lists a
    write scope, or when it belongs to another account than the connected one (unless confirmed). Connect, replace
    and disconnect need `import` + `manage`; checks are rate-limited per tenant (`connect_rate_limit`);
  - the credential is sealed at rest by the new port `CrmSecretBoxInterface`: built-in `SodiumCrmSecretBox`
    (XChaCha20-Poly1305, keys `secrets.keys` / `secrets.current_key`), or the DoctrineEncrypt bridge
    (`secrets.box`, the house option); it is shown only as `••••` + the last four characters;
    `nowo:crm-kit:secrets:rotate` re-seals under the current key;
  - companies, contacts, contact ↔ company associations (HubSpot's primary company ⇒ primary affiliation when the
    contact has none), owners ⇒ record owners by e-mail (optional port `CrmActorEmailLookupInterface`), lists ⇒ tags
    (existing or created on confirm); property mapping with custom fields; HubSpot ids kept in the new table
    `crm_external_ref`, so a re-run updates instead of duplicating; an empty HubSpot value never clears a CRM value;
    GDPR tombstones keep erased people out (Q-H6); no lifecycle mapping (Q-H10);
  - only `https://api.hubapi.com` is called, by the kit's own HttpClient (no redirects, size and time caps,
    `paging.next.link` ignored); optional OutboundUrlGuard DNS pin (`import.hubspot.outbound_url_guard`);
    sensitive properties are never offered;
  - one HubSpot page per write unit with a cursor compare-and-set; 429 / 5xx backoff in workers, a pause with
    `resume_after` in web requests, the daily limit until the next UTC day; 401 marks the connection invalid and a new
    credential resumes the import; the resume command also takes imports whose `resume_after` passed;
  - pages `/crm/import/hubspot` (card), `/connect`, `/disconnect`, `/new`, `/{id}/mapping`, each a step fragment of
    the import modal loaded on demand (links marked `data-crm-import-link` load in the modal); every denial answers
    404; 7 locales.
- `import.messenger.enabled` (off by default, any format, REQ-MSG-001): confirm and "Continue" dispatch
  `RunCrmImport`; `RunCrmImportHandler` runs `import.messenger.units` units and dispatches the next message (with a
  `DelayStamp` while HubSpot makes it wait).
- `ImportView::$resumeAfter` / `$errorCode`; the import page shows why a HubSpot import paused and until when.

### Changed

- Schema: nullable columns `crm_import.connection_id`, `run_cursor`, `resume_after`, `error_code` (index
  `crm_import_resume`), `crm_import_row.subject_type` for every host; the tables `crm_hubspot_connection` and
  `crm_external_ref` only with `import.hubspot.enabled` (UPGRADING, "Unreleased").
- `ImportServiceInterface::upload()` refuses the `hubspot` format (a HubSpot import starts from its connection).
- A paused import's "Continue" clears its reason and wait time.

### Security

Immediate audit ZAM of the slice, fixed before release:

- ZAM-1: unattended HubSpot pages (resume command, Messenger) run as the person who confirmed (`crm_import.confirmed_by_id`);
  under `security.resource_scoping` a page that would update, affiliate or tag a record owned by someone else rolls
  back and waits for a person's "Continue" (`hubspot_needs_person`); an unattended run never changes an existing
  record's owner; the confirm needs `edit` whatever the policy; "Continue" checks `edit` on every list member.
- ZAM-2: the credential's plaintext is no property of `HubSpotToken` any more (VarDumper, the profiler and Twig's
  `dump` showed it next to `[redacted]`); it cannot be cloned.
- ZAM-3: the write-scope check fails closed: only HubSpot's "unknown token" answer leads to probing; unreadable or
  extra scope entries count as write scopes; a service key's scopes are stored and shown as "not verified".
- ZAM-4: a connection is marked invalid only while it holds the sealed token that failed, and its version moves.
- ZAM-5: the DoctrineEncrypt box accepts Halite and Defuse only (or an explicit opt-in), rethrows configuration
  errors, and `secrets:rotate --all` re-seals every credential.
- ZAM-6: `secrets.keys` is never a container parameter. ZAM-10: the rotation reads connections in SQL batches.

## [0.9.0] - 2026-11-23

### Added

- `ui.pipeline_forms: dialog` (0.9.0, Beacon request 2026-11-23, owner rule D-20261123-01 "every dialog loads on demand"): the funnel pages render no form they have not opened. *Move to a stage* and *Add a next action* (deal page), *Set stage* (lifecycle) and *Add a next action* (360° page), *New funnel* (funnels), *New stage* and each stage's *Edit* (funnel page), and the *Move to trash* / *Delete for good* confirms of a deal are each their own page — `GET` on `nowo_crm_kit_deal_move`, `…_next_action_add`, `…_lifecycle`, `…_stage_edit` (`?funnel=`), `…_deal_trash`, `…_deal_purge`, and the new routes `nowo_crm_kit_funnel_new` (`<ui.path>/admin/funnels/new`) and `nowo_crm_kit_stage_new` (`<ui.path>/admin/funnels/{id}/stages/new`) — opened by real `<a href>` links with UiKit's remote form opener attributes into one `remote_form_dialog` per page (`dialog#crm-pipeline-dialog`): `X-Nowo-Dialog: 1` answers the fragment (`_dialog_form.html.twig`), an invalid submission 422 in place, a stale version or a refused task 422 with its message in place, success 204 + `X-Nowo-Redirect` (generated from the route); the page answers the full form page (no-JS fallback, deep link; a stale version 409, success 303). Same access checks (`edit` on the deal / record, `delete` / `manage` for trash / purge, `manage` for funnels and stages; 404 outside the tenant or hidden), CSRF tokens and version checks as the inline forms; lock conflicts keep their 409. Default `inline`: the 0.8 pages unchanged.
- Audit ZAG-1: the *Add a next action* and *Set stage* pages and dialog fragments of a company or contact that is not active (trashed, merged, erased) answer 404 (`GET`, and with `dialog` their `POST`; the inline `POST` keeps its 0.8 refusal), like a deal in the trash; the record's page never offers these forms there, and before the fix a deep link showed a form whose submit could only be refused.
- `_dialog_form.html.twig`: `intro` (a confirm's question) and `variant` (the submit's slot; `btn_danger` also gives Cancel the first focus) — optional, the 0.8 fragments are unchanged.
- `stage.edit_title` (*Edit stage %name%*) in the seven locales.

### Deprecated

- `ui.pipeline_forms: inline` keeps the forms in the funnel pages (deprecated: `dialog` becomes the default in 1.0), and with it the template variables `forms.move` / `forms.action` (`pipeline/deal.html.twig`), `pipeline.forms` (`pipeline/_record.html.twig`), `form` (`pipeline/funnels.html.twig`), `stages[].form` and `add` (`pipeline/funnel.html.twig`). With `dialog` they are empty / null and the templates render the openers (`openers`, `stages[].edit`, `add_opener`) and `crm_dialog` instead. See UPGRADING, "To 0.9.0".

### Fixed

- Docs (audit ZAC-L1): the 0.8.0 entry for `ui.record_create: dialog` names the dialog fragment
  `record/_form_dialog.html.twig`; the template is `@NowoCrmKit/_dialog_form.html.twig` (the 0.8.0 section is left as
  released).

## [0.8.1] - 2026-11-23

### Fixed

- Beacon request 2026-11-23: with UiKit's `icon_set: ux_icon` (and Iconify off or `ignore_not_found: false`) the funnel
  page, the board, the statistics, a deal page and a company / contact page with next actions answered **500**. Their
  actions asked for icons no set has: the stage *Move up* / *Move down*, the next action *Done* / *Cancel* and a
  company's *Make* / *Clear primary contact* were `custom` actions without an icon (row context draws the kind icon,
  `bi:custom`), the funnel's *Statistics* asked for `chart` and *Merge* for `merge`. Every kit action now names a
  canonical UiKit kind or a Bootstrap Icons name that exists: *Done* is `mark_done`, *Cancel* is `cancel`, the stage moves are `move`
  with the up / down arrows (`sort_asc` / `sort_desc`); *Statistics* draws `bar-chart`, the statistics' *Board*
  `kanban`, *Merge* `union`, the primary contact buttons `person-check` / `person-dash` (Bootstrap Icons, UiKit's
  default set; `bi:<name>` under `ux_icon`). The kinds follow the host's `nowo_ui_kit.action_icons`. A host on
  `ux_icon` without Iconify imports the new names (`bi:bar-chart`, `bi:kanban`, `bi:union`, `bi:person-check`,
  `bi:person-dash`) and drops any stand-in it drew for `bi:custom`, `bi:chart` or `bi:merge` (UPGRADING, "To 0.8.1").
  A test renders every page with a kit action under a strict `ux_icon()` that throws on a missing icon. Known limit:
  UiKit has no kind for these five, so under `icon_set: tabler-icons` they draw an empty Tabler glyph and under
  `svg_inline` UiKit's generic *view* eye (no error; the label and tooltip are unchanged). The kinds above draw in
  every set.
- Beacon request 2026-11-23: a new or edited deal with neither a company nor a contact answered 422 with **no visible
  message**: the service reports the field `company_id`, the form has `company`, so the error went to the form root,
  which UiKit's form scaffold does not print. The deal pages map `company_id` / `contact_id` to the `company` /
  `contact` fields (the message shows under *Company*), and `record/form.html.twig` now prints the form's own errors
  above the fields in a `flash_error` box with `role="alert"` (as the 0.9 pipeline forms do), so no domain error is
  silent again and screen readers announce it.

## [0.8.0] - 2026-11-23

### Added

- `ui.list_per_page` (Beacon request 2026-11-23): a *Rows per page* select (`select[name=per_page]`, visible label) in the filter card of the company and contact lists, Tags and Custom fields; `per_page` is checked against the configured choices (else `ui.page_size`; without the option UiKit's 10, 25, 50, 100 and `page_size`). Default `[]`: no select, the filter card is unchanged.
- The UiKit pager with its "Showing x–y of z" count under every list with rows (a single page included); a page past the end shows the last page.
- `ui.record_create: dialog` (Beacon request 2026-11-23, rule "every dialog loads on demand"): *New company* / *New contact* open in a UiKit remote form dialog that loads the form page on demand (`X-Nowo-Dialog: 1` ⇒ fragment `record/_form_dialog.html.twig`, invalid ⇒ 422 in the dialog, saved ⇒ 204 + `X-Nowo-Redirect` to the new record, `Vary: X-Nowo-Dialog`); the header action stays a real link (the no-JS fallback). Same access checks, tenant and CSRF token as the page.
- `ui.admin_create: dialog_inline` (deprecated): the 0.6.1 – 0.7 in-page create dialog, for hosts whose template overrides rely on `crm_create` / `admin/_create.html.twig`.
- Tags and Custom fields: a filter card (name search `q`, *Rows per page* with `ui.list_per_page`) and the UiKit pager.
- Translations of *Rows per page*, the name search and *Nothing matches the search.* in the seven locales.

### Changed

- `ui.admin_create: dialog` (Tags, Custom fields) loads the create form on demand (Beacon rule D-20261123-01): a real link to `?new=1` with UiKit's remote form opener, one empty `remote_form_dialog` shell, the fragment for `X-Nowo-Dialog: 1`, 422 in the dialog, 204 + `X-Nowo-Redirect` once saved, `Vary: X-Nowo-Dialog`. The page no longer renders the form inside a hidden `confirm_dialog`; `?new=1` without JavaScript shows the form card. Same access checks and CSRF tokens.

### Deprecated

- `ui.admin_create: dialog_inline` and `admin/_create.html.twig` (removed in 1.0).

- A `per_page` outside the accepted values falls back to `ui.page_size` (any value from 1 to 100 was used before while the pager counted 25). See UPGRADING, "To 0.8.0".

### Demo

- Both demos run in UiKit's demo platform shell (`@NowoUiKit/demo_platform/layout.html.twig`, layouts admin + guest, REQ-DEMO-014); the screenshot spec frames the shell and creates its own readable data, and the README shows the companies list and the 360° page near the top (REQ-DEMO-013).

### Documentation

- Tables of contents of CONTRACT and USAGE list every `###` subsection; SPEC-DRIVEN-DEVELOPMENT gets the "Bundle functional scope" heading its table of contents already linked (REQ-DOCS-005).

### Tests

- `UiRouteLoader` and `CrmApiRouteLoader`: the full generated route lists (names, paths, methods) and no bundle controller carries `#[Route]`, so the host's `routing.controllers` import cannot load them (REQ-SF-004 rule 8).

## [0.7.0] - 2026-11-23

### Added

- Beacon request: the area tabs (Companies, Contacts, Deals, Tags, Custom fields, Settings, Funnels) carry an icon,
  so the host's tab style (`icon-text`, `icon`) has something to show; before, they had none and showed text only in
  every style. Built-in Tabler set (`tabler:building`, `tabler:users`, `tabler:briefcase`, `tabler:tags`,
  `tabler:forms`, `tabler:settings`, `tabler:filter`), drawn by UiKit's `tabler-icons` and `ux_icon` icon sets; other
  sets keep the label only. New optional `ui.tab_icons` (area ⇒ icon name, merged over the defaults; `""` / `null`
  removes one): see CONFIGURATION, "Area tab icons". The icon is decoration only (`aria-hidden`, the label stays the
  accessible name); the access checks still decide which tabs show.

- Beacon request (UI review 2026-11-22): the record page's *Link to* form names the types and picks the object.
  Two **optional** resolver capabilities, separate interfaces a `CrmHostObjectResolverInterface` may also implement
  (detected with `instanceof` on every call; a resolver with neither behaves exactly as before):
  - `Domain\Port\CrmHostObjectTypeLabelInterface::typeLabel(): HostTypeLabel` — the type's human name
    (`HostTypeLabel::trans('crm.host.project', 'messages')` or `HostTypeLabel::text('Project')`); without it the
    form shows `type()`.
  - `Domain\Port\CrmHostObjectSearchInterface` — states that the resolver's `search()` really searches. The id field
    becomes a UiKit combobox (opt-in runtime `combobox`, loaded by the form) that lists the matches grouped by type and
    submits the picked `<type>:<id>` in the new hidden `crm_link[hostRef]`; without JavaScript, or when nothing is
    picked, the typed text is the id of the chosen type, as before. A resolver without it is never searched, so the
    `search()` stubs that return `[]` stay harmless.
  - New read-only route `nowo_crm_kit_link_search` (`GET <ui.path>/{type}/{id}/links/search?q=`), the combobox's
    JSON. Same access decision as the link action (`edit` on the active record through the gate, the
    `nowo_crm_kit.record_access_check` listeners included; 404 / 403 / 409 otherwise, and the host is not asked);
    the actor's tenant only; the text is trimmed and passed as it is (the resolver owns the comparison,
    REQ-SEC-006); at most 20 answers per type (`limit` clamped to 1–20), at most 100 characters of text; every
    answer is confirmed by `resolve()` with its exact id, so the picker never offers an object the link would refuse.
    `Cache-Control: no-store, private`. A picked value whose type has no picker, or that the resolver does not
    confirm, is refused like a wrong id (never stored as a free link).
  - New `Domain\Service\HostObjectPickerInterface` (`searchableTypes()`, `search()`) and the values
    `HostTypeLabel`, `HostObjectMatch`, `HostObjectRef::fromKey()`.
  - Field labels: "Id" reads "Object id"; with a picker the field is "Object" with a help line. All seven locales.

## [0.6.1] - 2026-11-22

### Added

- Beacon UX request after the 1.171 review of Companies, Contacts, Tags, Custom fields and Settings. New `ui` options
  (see USAGE "Page layout options"), the defaults keep 0.6.0:
  - `list_tabs` (default `true`): `false` hides the area tabs (Companies, Contacts, Tags, Custom fields, Settings…) on
    the list, admin and form pages. The company / contact record page keeps following `record_tabs`.
  - `admin_create: dialog` (default `inline`): on Tags and Custom fields, create is a `View\Action` in
    `page.header.actions` (*New tag*, *New field for companies*, *New field for contacts*) that opens a UiKit
    `confirm_dialog` with its anatomy (form in the body, *Cancel* and the primary in the footer, a sheet on phones).
    Its href, the page with `?new=1`, is the no-JS fallback (the form in a `<noscript>` card) and opens the dialog on
    load with JavaScript; an invalid submission answers 422 with the dialog open. Same routes, form names, CSRF tokens
    and access checks.
  - `settings_width: full` (default `form`): the Settings form in the bundle's card at the full content width, like
    the lists, instead of the UiKit form panel (whose width is the host's form column).

### Fixed

- Tags and Custom fields with no rows printed two sentences ("No tags yet." and UiKit's "No items found."): the empty
  state is now one sentence through UiKit's `empty` partial (UX-005).
- The create card of Tags and Custom fields touched the list card above it: it renders after the list section, whose
  bottom margin is the gap. The bare "Define a field for: Company · Contact" line is replaced by secondary UiKit tabs
  inside the create card (with `admin_create: dialog` there is one header action per subject instead).

## [0.6.0] - 2026-11-22

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
