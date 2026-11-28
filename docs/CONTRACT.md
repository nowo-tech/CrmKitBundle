# CRM kit bundle (CrmKitBundle). Contract v0.1 for symfony-beacon spec 245

Status 2026-11-23: **released through v0.7.0**: phases A (0.1.0), A2 (0.2.0, 0.2.1) and B (0.3.0, with the API) are built;
0.4.0 to 0.7.0 are Beacon UI and reader releases (see [ROADMAP.md](ROADMAP.md)); phases B2 to G are not started and the
release numbers in §1 and §25 for them are taken, so each phase gets its number in its own contract revision.
Status 2026-11-19: **v0.2, phase A2 (0.2.0: party roles, affiliation purposes and the company's primary contact) on
top of phase A (0.1.0), with the owner's phase plan of 2026-11-19 (§25).** Status 2026-11-18: **v0.1, written for the
build of phase A (0.1.0).** Every Q-O (§22) and Q-B (§23) question has a
recommended answer; the build follows those answers until the owner and Beacon reply (§24). The owner approved the
bundle and the repository `nowo-tech/CrmKitBundle` was created (public, empty) on 2026-11-18; phase A is built in
`.uic-work/crmkit-1118/repo`: `main` gets the scaffold, phase A goes to `feat/phase-a-companies-contacts` and a PR. No
tag before the owner registers Packagist and the audit passes.

> **This copy follows the code of 0.2.0.** It is the contract with every place where the build differs from the plan
> adjusted to what the code does; those places are marked **(0.1.0)** or **(0.2.0)**. The later phases follow the plan
> of §25; each gets its own contract revision before it is built.

- **Approval.** Owner decision **D-20261118-05** (Beacon `docs/DECISIONS.md`): organizations get a complete CRM in
  phases A–G, built as a new reusable, configurable bundle `nowo-tech/crm-kit-bundle`, also for other projects (a
  podiatry clinic CRM, "crm podología v2"), with no host overrides and the brand set by tokens. Request:
  Beacon `docs/ops/BUNDLES-REQUESTS/2026-11-18-crmkit-bundle.md` (commit `dc683a58e`); spec
  `specs/245-organization-crm/spec.md` (FR-001 – FR-005), tasks T001 / T010.
- **House bars from the first commit.** `BUNDLES_FIX_RULES.md` and every applicable REQ of
  `BUNDLES_FULL_SPECS_DETAILS.md`: REQ-ARCH-001 (standard Symfony by default, DDD host opt-in; rule 3 strict: own VO
  per port, no Symfony / Doctrine / HTTP types in public ports, events or VOs), REQ-RUNTIME-002 (FrankenPHP worker,
  kernel reset **false**), REQ-RUNTIME-003 (write units through `nowo-tech/transaction-doctrine` **^1.6**, 409
  `CONFLICT_001` / `CONFLICT_002` with the `X-Conflict-Code` header, two-connection race tests on MySQL 8.4, MariaDB 11.4
  and PostgreSQL 16, `docs/CONCURRENCY.md`), REQ-SEC-006 (identifiers matched byte for byte, binary NO PAD collations),
  REQ-UI-001/002/003 + REQ-DEMO-014/015 (UiKit + FormKit), REQ-I18N-002 (7 locales), REQ-TEST-003 (100 % coverage),
  public HTTP API paths versioned, runtime settings env / DB where a setting changes at runtime.
- **Language rule.** Code, docs and comments never compare features with other products; they describe what the
  bundle does.

Read for this contract (2026-11-18):

| Source | Version read | What was read |
| --- | --- | --- |
| symfony-beacon (read-only, never modified) | commit `dc683a58e` | request `docs/ops/BUNDLES-REQUESTS/2026-11-18-crmkit-bundle.md`, `specs/245-organization-crm/{spec,tasks}.md`, D-20261118-05 |
| transaction-doctrine | v1.6.0 | `WriteUnit\*`, `ConflictCode`, `RequestCommitWatermark`, `ConcurrencyConflictResponder`, `docs/USAGE.md` (write units, conflict codes, the `X-Conflict-Code` header, `forHostEntityManager()`) |
| WorkingTimeBundle | 0.2 (S2 branch) | scaffold, `DoctrineTransactionRunner`, `RegisterMetadataListener` (prefix + binary collation), conflict formatter / fallback, test kernel, host kinds, race injector, worker test |
| SearchKitBundle | 1.7.0 | `Contract\SearchProviderInterface`, `Model\{SearchQuery,SearchGroup,SearchItem,SearchBadge}`, security contract |
| UiKitBundle | 2.55.0 | `View\{ListPage,Table,Column,Action,Confirm,FilterField,Pagination,ShowPage,DetailSection,DetailItem,PageHeader,FormPage,EmptyState}`, row actions and context menus (2.54), shell focus mode (2.55), `responsive: cards` (phone layout) |
| FormKitBundle | 2.23.0 | profiles, types, CSRF |
| VerifactuBundle | 1.8.0 | `Service\BillingRecordProcessor`, `Model\BillingRecord`, `Integration\{InvoiceDraft,InvoiceToBillingRecordMapper}`, hash-chain repository, events |
| SepaPaymentBundle | 2.4.0 | `Service\MandateService`, `Repository\MandateRepositoryInterface`, `Generator\{DirectDebitGenerator,CreditTransferGenerator}`, validators |
| MailKitBundle | 1.12.2 | `Mailer\{MailKitMailer,MailHeaders}`, `Tracking\{EmailTrackingPolicyInterface,EmailLinkRewriterInterface}`, events |
| StorageKitBundle | 1.6.1 (origin/main) | `Upload\ImageUploaderInterface`, `Context\FileContextInterface`, `Download\FileDeliveryInterface`, scopes |
| NotifyKitBundle | 0.1.1 | `Contract\NotifierInterface`, `NotificationRequest`, `RecipientRef`, `ContextRef`, access checker SPI |
| Earlier contracts (style) | — | WorkingTimeBundle `CONTRACT.md` v0.1, TaskBoard questions contract |

## Table of contents

- [0. Findings that shaped the contract](#0-findings-that-shaped-the-contract)
- [1. Scope and non-goals](#1-scope-and-non-goals)
- [2. Package](#2-package)
- [3. Shape](#3-shape)
- [4. Layout and architecture rules](#4-layout-and-architecture-rules)
- [5. Public API: value objects and enums](#5-public-api-value-objects-and-enums)
- [6. Domain model and tables](#6-domain-model-and-tables)
  - [6.1 Conventions](#61-conventions)
  - [6.2 Phase A tables (in detail)](#62-phase-a-tables-in-detail)
  - [6.3 Records, statuses and duplicates](#63-records-statuses-and-duplicates)
  - [6.4 Custom fields](#64-custom-fields)
  - [6.5 Host links and panels](#65-host-links-and-panels)
  - [6.6 Timeline](#66-timeline)
  - [6.7 Later phases (shape only; detailed in their own contract revision before each build)](#67-later-phases-shape-only-detailed-in-their-own-contract-revision-before-each-build)
  - [6.8 Party roles and affiliation purposes (0.2.0)](#68-party-roles-and-affiliation-purposes-020)
- [7. Public services (what a host calls)](#7-public-services-what-a-host-calls)
- [8. Ports and SPIs (host-implemented; each has a safe default)](#8-ports-and-spis-host-implemented-each-has-a-safe-default)
  - [8.1 Required for a real deployment](#81-required-for-a-real-deployment)
  - [8.2 Extension points](#82-extension-points)
- [9. Behaviour of phase A](#9-behaviour-of-phase-a)
- [10. Configuration (strict tree, REQ-SF-006; defaults shown)](#10-configuration-strict-tree-req-sf-006-defaults-shown)
- [11. Routes, UI and API](#11-routes-ui-and-api)
  - [11.1 UI (opt-in, `ui.enabled: true`; route loader `nowo_crm_kit_ui`; the host imports it with its prefix)](#111-ui-opt-in-uienabled-true-route-loader-nowo_crm_kit_ui-the-host-imports-it-with-its-prefix)
  - [11.2 Public JSON API (versioned, off by default, Q-O6; **0.3.0**, decided by the owner on 2026-11-19, Q-O16)](#112-public-json-api-versioned-off-by-default-q-o6-030-decided-by-the-owner-on-2026-11-19-q-o16)
  - [11.3 Errors](#113-errors)
- [12. SearchKit provider (bridge)](#12-searchkit-provider-bridge)
- [13. Permission matrix (deny by default)](#13-permission-matrix-deny-by-default)
- [14. Events (dispatched after commit, ids and VOs only; REQ-ARCH-001 rules 4 / 7)](#14-events-dispatched-after-commit-ids-and-vos-only-req-arch-001-rules-4--7)
- [15. Concurrency design per write path (REQ-RUNTIME-003)](#15-concurrency-design-per-write-path-req-runtime-003)
  - [15.1 Plan-limit counters](#151-plan-limit-counters)
  - [15.2 Version checks](#152-version-checks)
  - [15.3 Merge](#153-merge)
  - [15.4 Import](#154-import)
- [16. GDPR](#16-gdpr)
  - [16.1 Legal basis and consent](#161-legal-basis-and-consent)
  - [16.2 Export (`CrmPersonalDataInterface::export`, `manage`)](#162-export-crmpersonaldatainterfaceexport-manage)
  - [16.3 Erasure (`erase(string $contactId)`, `manage`; **0.1.0**: no reason code)](#163-erasure-erasestring-contactid-manage-010-no-reason-code)
  - [16.4 Retention (`nowo:crm-kit:purge`, daily; `--tenant`, `--dry-run`)](#164-retention-nowocrm-kitpurge-daily---tenant---dry-run)
  - [16.5 A host person's references (0.2.1, system API)](#165-a-host-persons-references-021-system-api)
- [17. Migrations](#17-migrations)
- [18. Worker mode (FrankenPHP, kernel reset false; REQ-RUNTIME-002)](#18-worker-mode-frankenphp-kernel-reset-false-req-runtime-002)
- [19. Integration points](#19-integration-points)
  - [19.1 UiKitBundle (^2.55) and FormKitBundle (^2.23)](#191-uikitbundle-255-and-formkitbundle-223)
  - [19.2 SearchKitBundle (^1.7, optional)](#192-searchkitbundle-17-optional)
  - [19.3 StorageKitBundle (phase B, optional)](#193-storagekitbundle-phase-b-optional)
  - [19.4 VerifactuBundle and SepaPaymentBundle (phase D)](#194-verifactubundle-and-sepapaymentbundle-phase-d)
  - [19.5 MailKitBundle (phase E)](#195-mailkitbundle-phase-e)
  - [19.6 NotifyKitBundle (phase B)](#196-notifykitbundle-phase-b)
  - [19.7 brand-contracts and Dompdf (phase C)](#197-brand-contracts-and-dompdf-phase-c)
- [20. Standard vs DDD host (REQ-ARCH-001)](#20-standard-vs-ddd-host-req-arch-001)
- [21. Test plan (100 % PHP coverage, REQ-TEST-003)](#21-test-plan-100--php-coverage-req-test-003)
- [22. Questions for the owner (Q-O)](#22-questions-for-the-owner-q-o)
- [23. Questions for Beacon (Q-B) — confirmed by Beacon 2026-11-19 (B1–B15)](#23-questions-for-beacon-q-b--confirmed-by-beacon-2026-11-19-b1b15)
- [24. Answers](#24-answers)
  - [Status 2026-11-18](#status-2026-11-18)
  - [Status 2026-11-19 (0.2.0)](#status-2026-11-19-020)
- [Q-B answers from Beacon (2026-11-19): B1–B15 confirmed as implemented](#q-b-answers-from-beacon-2026-11-19-b1b15-confirmed-as-implemented)
- [25. Phase plan (owner, 2026-11-19)](#25-phase-plan-owner-2026-11-19)
  - [25.1 0.3.0: funnels with per-stage statistics, and next actions](#251-030-funnels-with-per-stage-statistics-and-next-actions)
  - [25.2 0.4.0: tasks and agenda](#252-040-tasks-and-agenda)
  - [25.3 0.5.0: phase C, quotes](#253-050-phase-c-quotes)
  - [25.4 0.6.0: phase D, invoices](#254-060-phase-d-invoices)
  - [25.5 0.7.0: phase F, customer portal](#255-070-phase-f-customer-portal)
  - [25.6 0.8.0: explainable scoring](#256-080-explainable-scoring)
  - [25.7 0.9.0: lists and segments](#257-090-lists-and-segments)
  - [25.8 0.10.0: lean informative circulars](#258-0100-lean-informative-circulars)
- [26. Addendum: HubSpot import source (design, 2026-11-23)](#26-addendum-hubspot-import-source-design-2026-11-23)
- [27. Addendum: activities (A-1, 0.11.0)](#27-addendum-activities-a-1-0110)
- [28. Addendum: e-mail templates (A-2, 0.13.0)](#28-addendum-e-mail-templates-a-2-0130)

## 0. Findings that shaped the contract

| # | Finding | Effect |
|---|---|---|
| F1 | Beacon already has an organization address book of e-mail correspondents (spec 229 client threads) and the 360° page must show Beacon modules (projects, tasks, tickets, threads, hours, invoices) the bundle cannot know | The 360° page is assembled from **host panels** (`CrmPanelProviderInterface`, tagged); the bundle owns only companies, contacts and their own data. Host objects are referenced **by id** through `crm_host_link` rows and resolved per call by the host (`CrmHostObjectResolverInterface`) |
| F2 | Plan limits are counts ("contacts / deals / users") and two creates at the limit can both pass a `COUNT(*)` check | One **atomic counter row** per tenant and resource (`crm_counter`): `UPDATE … SET value = value + 1 WHERE … AND value < :limit`; no row changed ⇒ refused (REQ-RUNTIME-003 rule 4: never `COUNT()` then insert) |
| F3 | Merging duplicates touches two records and every child row (affiliations, tags, notes, timeline, consents, host links); two people may merge A→B and B→A, or A→B and C→A, at the same moment | Both records are locked `SELECT … FOR UPDATE` **in id order**, re-read and checked (`active`, expected versions) inside the lock; children are moved by primary key after a byte-exact re-select; unique children (tags, affiliations, host links) are de-duplicated before the move. The loser becomes a `merged` tombstone pointing at the survivor (§15.3) |
| F4 | A CSV or vCard import is long, may be confirmed twice (double click, two tabs, a retried request) and may stop half-way (a killed worker) | The import is **claimed** by a conditional `UPDATE` (`preview → running`, one winner); rows are processed in **chunks of 100**, one transaction per chunk, each row settled by `UPDATE … WHERE outcome = 'pending'`; a stale claim is taken over by the resume command; contact e-mails are unique per tenant so two imports of one person store one contact (§15.4) |
| F5 | VerifactuBundle is a compliance layer (records, hash chain per issuer NIF, AEAT submission) with no numbering, no invoice store and no payment state; SepaPaymentBundle generates pain files and keeps mandates in memory unless the host gives a repository | Phase D keeps the invoice, its numbering (one atomic sequence row per series) and its payment state in the CRM; it calls `BillingRecordProcessor::process()` after commit from a Messenger handler and implements `MandateRepositoryInterface` on its own tables (§19.4) |
| F6 | MailKitBundle sends and tracks opens but has no campaign, unsubscribe, suppression or bounce handling; tracking goes through a host `EmailTrackingPolicyInterface` | Phase E owns segments, campaigns, the suppression list, consent checks, `List-Unsubscribe` + one-click unsubscribe, and implements MailKit's tracking policy (track only with tracking consent) (§19.5) |
| F7 | StorageKitBundle 1.6 stores images only (non-image attachments are on its roadmap); NotifyKitBundle 0.1 writes an in-app inbox with dedupe keys but has no scheduling | Attachments on notes wait for StorageKit non-image files (phase B, port `CrmAttachmentStoreInterface`, Q-O9); reminders (phase B) are scheduled by the CRM (a due-reminder table + command) and delivered through NotifyKit with a dedupe key; the CRM implements NotifyKit's access checker for tenant-scoped notifications (§19.7) |
| F8 | SearchKit providers must return only rows the user may open, treat the scope as client input and never reveal existence | `Bridge\SearchKit\CrmSearchProvider` checks the tenant and `crm.view` per call, returns at most `limit` rows of the current tenant only, escapes `LIKE` wildcards and answers `null` for an unknown or forbidden scope (§12) |
| F9 | UiKit 2.54 / 2.55 already provide row-action context menus (right-click, ContextMenu key, long press), `responsive: cards` list layout on phones and a shell focus mode with a `focus_mode_toggle` partial | Lists use `ListPage` with row actions marked `context_menu: true`, `responsive: cards`; record and list pages put the focus-mode toggle in their toolbar; the bundle ships no CSS framework and no tokens of its own (brand by UiKit tokens only) |

## 1. Scope and non-goals

**Phases** (each opt-in by configuration and shippable alone; a phase that is off maps no tables, registers no services
or routes and renders nothing):

| Phase | Version | Contents | Enabled by |
|---|---|---|---|
| **A** companies and contacts | **0.1.0** | Companies; contacts (several per company, through affiliations with a role and one primary company); owners; tags; custom fields; notes; activity timeline; host links and host panels on a 360° page; CSV and vCard import with preview; duplicate hints and merging; CSV / vCard export; GDPR (consent per purpose, export, erasure, retention); trash with restore; plan-limit counters; SearchKit provider; audit events | always on when the bundle is enabled (`companies` is the root of every other phase) |
| **A2** roles and purposes | **0.2.0** | Party roles (many per company or contact; configurable catalogue), the company's primary contact and affiliation purposes, role / purpose filters, import and export columns; the UD-2 concurrency follow-ups (§6.8, §25) | always on (part of phase A) |
| **B** funnels | 0.3.0 | Deal funnels and party lifecycle funnels with ordered stages, won / lost terminal stages, stage history and per-stage statistics; next actions through `CrmTaskPort` (§25) | `pipeline.enabled` |
| **B2** tasks and agenda | 0.4.0 | Tasks from any record, opt-in automations, meetings and calls with attendees, "my agenda", reminders and ICS invites (§25) | its own switch (decided in its contract revision) |
| **C** quotes | 0.5.0 | Product / service catalogue, quotes from a deal or directly on a company or contact, versions, PDF, signed and expiring accept / reject link, e-sign hook; outcomes feed the funnels (§25) | `quotes.enabled` |
| **D** invoices | 0.6.0 | Invoices from accepted quotes, host billable lines or by hand; atomic numbering per series; corrective invoices; VeriFactu (VerifactuBundle) and SEPA (SepaPaymentBundle) after commit; payment status and reminders (§25) | `invoices.enabled` |
| **F** customer portal | 0.7.0 | A contact signs in (invitation, magic link / passwordless) and sees their party's invoices, quotes, pending amount, consents and subscriptions (§25) | `portal.enabled` |
| **S** scoring | 0.8.0 | Explainable rule-based contact and company scoring, hot / warm / cold bands (§25) | its own switch |
| **E1** lists and segments | 0.9.0 | Static lists and dynamic segments, bulk add (§25) | its own switch |
| **E2** informative circulars | 0.10.0 | One e-mail per recipient through MailKit's queue, per-purpose unsubscribe, consent rules, bounces, send log (§25) | `campaigns.enabled` |
| **G** reports | later → 1.0.0 | Deal scoring, deeper forecasts, velocity and cohorts, open / click tracking, two-way sync with external calendars | `reports.enabled` |

The phase plan was set by the owner on 2026-11-19 (§25); it replaces the earlier order B → C → D → E → F → G.

**Non-goals (all phases, spec 245 "out of scope"):** telephony (CTI), social media inboxes, AI scoring (rule-based,
explainable scoring is phase S), a marketing
automation engine (multi-step journeys), an accounting ledger (the CRM records invoices and payment state; ledgers
belong to the host or an accounting product), payment card processing, native apps. Phase A does not ship a public JSON
API (Q-O6) or attachments (Q-O9).

## 2. Package

| Item | Proposal |
|---|---|
| Name | `CrmKitBundle`, Composer `nowo-tech/crm-kit-bundle`, GitHub `nowo-tech/CrmKitBundle` (public, MIT; the owner creates the repository and registers Packagist before the first tag) |
| Namespace / class | `Nowo\CrmKitBundle` / `NowoCrmKitBundle`; config root `nowo_crm_kit`; Twig namespace `@NowoCrmKit`; translation domain `NowoCrmKitBundle` (house set **en, es, it, fr, pt, de, nl**, REQ-I18N-002); FormKit profile `crm_kit`; route names `nowo_crm_kit_*` |
| Releases | 0.x per phase; **1.0.0** after phase G, `bundle-req-audit` 100 % and the SEC-004 audits (Q-O13) |
| PHP / Symfony | `>=8.2 <8.7`; `^7.4 \|\| ^8.0`; CI Symfony 7.4 and 8.1; demos Symfony 7.4 and 8.1 on FrankenPHP worker mode, MySQL |
| Runtime deps (A) | `doctrine/dbal ^3.8.2 \|\| ^4.0`, `doctrine/orm ^3.7`, `doctrine/doctrine-bundle ^2.8 \|\| ^3.0`, `symfony/{framework-bundle,http-kernel,http-foundation,routing,config,dependency-injection,event-dispatcher,clock,console,form,validator,translation,twig-bundle,uid,security-csrf,string}`, `psr/clock`, `psr/log`, `nowo-tech/transaction-contract ^1.0`, **`nowo-tech/transaction-doctrine ^1.8.1`** (0.3.0; ^1.6 before), `nowo-tech/ui-kit-bundle ^2.55`, `nowo-tech/form-kit-bundle ^2.23`, `twig/extra-bundle` |
| Suggest | `nowo-tech/search-kit-bundle ^1.7` (header search), `symfony/messenger` (imports outside the request; **0.1.0** ships no handler yet), `symfony/security-bundle` (default role-map checker), later phases: `nowo-tech/notify-kit-bundle`, `nowo-tech/storage-kit-bundle`, `nowo-tech/mail-kit-bundle`, `nowo-tech/verifactu-bundle`, `nowo-tech/sepa-payment-bundle`, `nowo-tech/brand-contracts`, `dompdf/dompdf` (quote / invoice PDF) |
| Not used | No JS framework (UiKit runtime scripts only), no CSS of its own beyond UiKit tokens and slots, no external service in phase A |

## 3. Shape

```
                                    Host (Beacon, podiatry CRM, any Symfony app)
  ports ───────────────────────────────────────────────────────────────────────────────────────────────
  TenantResolver · AccessChecker · FeatureGate · PlanLimits · ActorDirectory · AuditSink · Clock · IdGenerator
  PanelProvider* · HostObjectResolver* · (B+) Notifier bridge · AttachmentStore · (C+) Brand · (D) Billing bridges
  ─────────────────────────────────────────────────────────────────────────────────────────────────────
  Presentation (opt-in routes, UiKit + FormKit)          Bridges (enabled when the package is installed)
    companies · contacts · 360° page · notes · tags        SearchKit: CrmSearchProvider
    fields · import · merge · trash · settings              (B) NotifyKit · (C) Dompdf · (D) Verifactu, Sepa
                                                            (E) MailKit · (F) portal token auth
  ─────────────────────────────────────────────────────────────────────────────────────────────────────
  Application   RecordService (companies, contacts, affiliations) · TagService · FieldService · NoteService
                HostLinkService (+ Timeline) · ConsentService · ImportService · MergeService · CrmReader
                (+ duplicates, search) · CrmExporter · CrmPersonalData · RecordPurger · PlanCounter · SettingsService
  Domain        VOs, enums, entities (plain PHP, XML mapping), ports, events, exceptions
  Infra         private EM `nowo_crm_kit` · write units (transaction-doctrine) · metadata listener (prefix,
                binary NO PAD collations) · conflict formatter (409 + CONFLICT_00x + X-Conflict-Code)
  Console       nowo:crm-kit:purge · nowo:crm-kit:import:resume · nowo:crm-kit:counters:rebuild
```

The bundle owns companies, contacts and their notes, tags, fields, timeline, consents, imports, merges and links. The
host owns tenants, people (actors and owners), who may do what, the plan, the brand, the objects of its own modules and
where the UI is mounted.

## 4. Layout and architecture rules

```
src/Domain/{Model,Enum,Event,Exception,Port,Service,Value}/   plain PHP: VOs, enums, entities, ports, events
src/Application/                                             services on the ports; no Symfony / Doctrine types in signatures
src/Infrastructure/Doctrine/                                  XML mapping, stores (repositories), metadata listener, runner
src/Infrastructure/Symfony/                                   DI, config tree, controllers, forms, commands, Twig, defaults (no Messenger in 0.1.0)
src/Bridge/{SearchKit,…}/                                     only code that imports optional packages
src/Resources/{config/doctrine,views,translations}/
```

- **Architecture test** (`tests/Architecture/LayerDependencyTest`): `Domain` imports no Symfony / Doctrine / HTTP class
  and nothing from `Application` / `Infrastructure` / `Bridge`; `Application` imports no Symfony HTTP / Form / Doctrine
  class (only `Psr\*` and the transaction contracts); bridges are the only importers of optional packages.
- **Both host kinds** (REQ-ARCH-001): entities are bundle-owned plain classes with XML mapping; hosts reference them by
  id (UUID strings); no relation to host entities, no `resolve_target_entities`, no base class or trait for the host.
  The suite boots three hosts: standard (private EM on the default connection), DDD (own connection and EM `ddd`,
  another table prefix) and single-EM Flex (`entity_manager: null`, the host's EM shared with explicit transactions).

## 5. Public API: value objects and enums

All `final readonly`, validated in the constructor, in `Nowo\CrmKitBundle\Domain\Value\…`.

| VO | Rule |
|---|---|
| `TenantRef`, `ActorRef` | Opaque host ids, 1–128 bytes of valid UTF-8, no control / format characters (ZWSP, BOM, bidi marks), no edge whitespace or NBSP, never trimmed or case folded, compared with `===` (REQ-SEC-006). `TenantRef::default()` = `default` |
| `HostObjectRef` | `type` (`^[a-z][a-z0-9_.-]{0,63}$`, `/D`) + `id` (same rule as `TenantRef`) |
| `RecordId` | Canonical lowercase UUID string (`\A[0-9a-f]{8}-…\z`, `/D`); new rows UUIDv7 through `IdGeneratorInterface` |
| `SubjectRef` | `SubjectType` + `RecordId` (+ optional owner `ActorRef` for access checks) |
| `EmailAddress` | ≤ 254 bytes, one `@`, no spaces or control characters; `key()` = lowercase of the whole address (the unique key per tenant); the original spelling is stored as typed |
| `FieldKey` | `^[a-z][a-z0-9_]{0,39}$` (`/D`) |
| `ConsentPurpose` | `^[a-z][a-z0-9_.]{0,63}$` (`/D`); the purposes a tenant can record are configured (`gdpr.purposes`) |
| `TagName` | 1–60 characters after trimming of the edges **by the service** (the VO refuses edge whitespace), no control or format characters; `key()` = `mb_strtolower` of the name as typed (**0.1.0**: no NFC normalisation), unique per tenant |
| `ActorProfile` | `ActorRef`, display name (≤ 180), e-mail (nullable) |
| `PanelContext` / `Panel` / `PanelItem` | §8.2 |
| `AuditEntry` | tenant, actor, action key (`crm.*`), subject type + id, counts / codes only (never names, e-mails or note text) |

Enums: `CrmPermission { View; Edit; Manage; AmountsView; CampaignsSend }` (values `view`, `edit`, `manage`,
`amounts_view`, `campaigns_send`), `SubjectType { Company; Contact }` (later phases add `Deal`, `Quote`, `Invoice`),
`RecordStatus { Active; Trashed; Merged; Erased }`, `FieldType { Text; Textarea; Number; Date; Boolean; Choice; Url;
Email }`, `ActivityKind` (§6.6), `ConsentSource { Form; Import; Manual; Portal; Api }`, `ImportFormat { Csv; Vcard }`,
`ImportStatus { Preview; Running; Done; Failed; Cancelled }`, `RowOutcome { Pending; Created; Updated; Duplicate;
Invalid; Limit }` (**0.1.0**: no `Skipped`; a skipped duplicate is `Duplicate`), `DuplicatePolicy { Skip; Update }`, `CrmResource { Companies; Contacts }` (later `Deals`).

## 6. Domain model and tables

### 6.1 Conventions

- Prefix `doctrine.table_prefix` (default `nowo_`, REQ-ORM-002); names below use `<p>`. Table names `crm_*`.
- Ids: `string(36)` PK, UUIDv7. Host refs (`tenant_id`, `owner_id`, `*_by_id`, `actor_id`, `host_id`) and keys
  (`email_key`, `name_key`, `field_key`, `purpose`, `host_type`): `string(128)` or shorter with a **binary NO PAD**
  collation (`utf8mb4_0900_bin` MySQL 8, `utf8mb4_nopad_bin` MariaDB; exact by default on PostgreSQL / SQLite),
  declared by the metadata listener so `schema:update` and `migrations:diff` keep it. **(0.1.0)** The listener applies
  it to every string column named `id` or `*_id` and to `email_key`, `name_key`, `tax_id_key`, `domain_key`,
  `sort_key`, `field_key`, `purpose`, `host_type`, `claim_token`, `resource` (so `crm_company.tax_id` gets it too); it
  needs the platform at metadata load (`doctrine.dbal.server_version`). Every read, export, erasure or
  bulk write keyed by such a value re-checks `===` in PHP (REQ-SEC-006 rule 2).
- Instants: `datetime_immutable` UTC (second precision); **(0.2.0)** the `updated_at` of companies, contacts, notes and
  settings to the microsecond (`DATETIME(6)` / `TIMESTAMP(6)`), so every write moves the optimistic version
  (`touch()` keeps the exact instant, one microsecond later only on an exact tie). Optimistic `version` (int, ORM `version="true"`) on every
  editable row.
- No foreign keys to host tables. Internal relations are plain id columns (no ORM associations), so the bundle never
  loads a graph and children move by primary key.
- **Per-phase mapping groups**: `Resources/config/doctrine` (phase A) and `doctrine-pipeline`, `doctrine-quotes`, … for
  the later phases, registered only when the phase is enabled.

### 6.2 Phase A tables (in detail)

| Table | Columns (beyond `id`) | Keys | Write rule |
|---|---|---|---|
| `<p>crm_settings` | PK `tenant_id`; `trash_days` (7–365, 30), `timeline_years` (1–10, 5), `import_keep_days` (1–90, 30), `merge_keep_days` (1–365, 90), `version`, `updated_by_id`, `updated_at` | — | Upsert under version |
| `<p>crm_company` | `tenant_id`, `name` (200), `name_key` (200, lowercase, duplicate hints), `legal_name` (200) NULL, `tax_id` (64) NULL, `tax_id_key` (64) NULL (uppercase, no spaces / dots / dashes), `website` (255) NULL, `domain_key` (190) NULL (host of the website, no `www.`), `email` (254) NULL, `phone` (40) NULL, `street` (255), `postal_code` (20), `city` (120), `region` (120), `country` (2) NULL, `industry` (80) NULL, `description` (text ≤ 5000) NULL, `owner_id` NULL, `custom` (json), `status`, `merged_into_id` NULL, `trashed_at` NULL, `version`, `created_at`, `created_by_id` NULL, `updated_at`, `updated_by_id` NULL | idx `(tenant_id, status, name_key)`, `(tenant_id, domain_key)`, `(tenant_id, tax_id_key)`, `(tenant_id, owner_id)` | Version on every edit |
| `<p>crm_contact` | `tenant_id`, `first_name` (120), `last_name` (120), `display_name` (241), `sort_key` (241, lowercase "last first"), `email` (254) NULL, `email_key` (254) NULL, `phone` (40) NULL, `mobile` (40) NULL, `job_title` (120) NULL, `street`, `postal_code`, `city`, `region`, `country` NULL, `owner_id` NULL, `custom` (json), `status`, `merged_into_id` NULL, `trashed_at` NULL, `erased_at` NULL, `version`, `created_at`, `created_by_id`, `updated_at`, `updated_by_id` | **unique `(tenant_id, email_key)`** (NULLs allowed); idx `(tenant_id, status, sort_key)`, `(tenant_id, owner_id)` | Version; erasure blanks the personal columns |
| `<p>crm_affiliation` | `tenant_id`, `contact_id`, `company_id`, `role` (120) NULL, `is_primary` (the contact's primary company), `created_at`, **(0.2.0)** `is_primary_contact` (the company's primary contact, default false), `purpose_keys` (1100: 32 keys × 33 characters + 1, default `''`: the purposes as `,billing,orders,`) | **unique `(contact_id, company_id)`**; idx `(tenant_id, company_id)` | Insert / delete; primary flag changed under the contact's version |
| `<p>crm_role_assignment` **(0.2.0)** | `tenant_id`, `subject_type`, `subject_id`, `role_key` (32), `created_at`, `created_by_id` NULL | **unique `(tenant_id, subject_id, role_key)`**; idx `(tenant_id, role_key, subject_type)` | Insert / delete under the record's lock (set semantics); a racing duplicate ⇒ one more run |
| `<p>crm_tag` | `tenant_id`, `name` (60), `name_key` (60), `colour` (UiKit palette token), `version`, `created_at` | **unique `(tenant_id, name_key)`** | Version on rename |
| `<p>crm_tagging` | `tenant_id`, `tag_id`, `subject_type`, `subject_id`, `created_at` | **unique `(tag_id, subject_type, subject_id)`**; idx `(tenant_id, subject_type, subject_id)` | Insert (duplicate = already tagged) / delete |
| `<p>crm_field` | `tenant_id`, `subject_type`, `field_key`, `label` (80), `type`, `choices` (json list of ≤ 50 strings ≤ 80), `required`, `position`, `archived`, `version`, `created_at` | **unique `(tenant_id, subject_type, field_key)`** | Version; archived, never deleted (values stay in `custom`) |
| `<p>crm_note` | `tenant_id`, `subject_type`, `subject_id`, `body` (text, 1–10 000, plain text shown escaped), `pinned`, `author_id` NULL, `version`, `created_at`, `updated_at` | idx `(tenant_id, subject_type, subject_id, created_at)` | Version on edit; delete by id |
| `<p>crm_activity` | `tenant_id`, `subject_type`, `subject_id`, `kind`, `actor_id` NULL, `params` (json, ≤ 20 scalars, never note text or contact data), `occurred_at` | idx `(tenant_id, subject_type, subject_id, occurred_at)`; idx `(tenant_id, occurred_at)` | **Insert only** (moved by merge, deleted by retention / erasure) |
| `<p>crm_consent` | `tenant_id`, `contact_id`, `purpose`, `granted`, `source`, `evidence` (255) NULL, `recorded_by_id` NULL, `recorded_at` | idx `(tenant_id, contact_id, purpose, recorded_at)` | **Insert only**; the current state is the latest row per purpose |
| `<p>crm_host_link` | `tenant_id`, `subject_type`, `subject_id`, `host_type` (64), `host_id` (128), `label` (200) NULL, `created_at`, `created_by_id` NULL | **unique `(tenant_id, subject_type, subject_id, host_type, host_id)`**; idx `(tenant_id, host_type, host_id)` | Insert (duplicate = already linked) / delete |
| `<p>crm_import` | `tenant_id`, `subject_type`, `format`, `status`, `file_name` (255), `duplicate_policy`, `columns` (json: the file's column names, **0.1.0**), `mapping` (json: column ⇒ field), `rows_total`, `rows_done`, `created_count`, `updated_count`, `duplicate_count`, `invalid_count`, `limit_count`, `claim_token` (36) NULL, `claimed_at` NULL, `created_by_id` NULL, `created_at`, `finished_at` NULL, `version` | idx `(tenant_id, created_at)`, `(status, claimed_at)` | Claim by conditional UPDATE; counters by atomic UPDATE |
| `<p>crm_import_row` | `import_id`, `tenant_id`, `row_no`, `data` (json, the parsed row), `outcome`, `record_id` NULL, `message` (80) NULL | **unique `(import_id, row_no)`** | Settled by `UPDATE … WHERE outcome = 'pending'`; deleted after `import_keep_days` |
| `<p>crm_merge` | `tenant_id`, `subject_type`, `survivor_id`, `merged_id`, `snapshot` (json: the merged record's fields before the merge), `taken` (json: fields taken from it), `actor_id` NULL, `merged_at` | idx `(tenant_id, survivor_id)`, `(tenant_id, merged_id)` | Insert only; deleted after `merge_keep_days` |
| `<p>crm_counter` | PK `(tenant_id, resource)`; `value` | — | **Atomic** `UPDATE value = value ± 1`; rebuilt by a command |

### 6.3 Records, statuses and duplicates

- `Active` → `Trashed` (restore within `trash_days`, then purged) → hard delete with children. `Active` → `Merged`
  (tombstone: `merged_into_id`, `email_key` NULL, no children, **(0.1.0)** every personal column blanked: only id,
  tenant, status and survivor stay, the snapshot lives in `crm_merge`; deleted by the retention purge after
  `merge_keep_days`; a GET of a merged id redirects to the survivor, a write answers 409 `crm_record_merged`). Contacts: `Active | Trashed` → `Erased` (GDPR, §16.3).
- A contact's e-mail is **unique per tenant** (`email_key`); creating or editing a contact with an e-mail of another
  active or trashed contact is refused with a link to it (restore or merge). A trashed record keeps its keys.
- **Duplicate hints** (`CrmReaderInterface::duplicates()`, read only): companies with the same `name_key`,
  `domain_key` or `tax_id_key`; contacts with the same `sort_key` (**0.1.0**: no phone-digit matching). Shown on the
  record page and the merge picker, never enforced; the import preview shows the existing contact with the same
  e-mail.
- **Owners** are host actors (`owner_id`); the owner list comes from `CrmActorDirectoryInterface::owners()` and every
  stored owner is re-checked against it on write (an unknown owner is refused).

### 6.4 Custom fields

Definitions per tenant and subject type (`crm_field`), up to `fields.max_per_subject` (50). Values live in the record's
`custom` JSON (`field_key ⇒ scalar`), validated on every write by type (`number`: decimal string ≤ 30 characters,
`date`: `Y-m-d`, `boolean`, `choice`: one of the choices exactly, `url`: http(s), `email`: `EmailAddress`, `text` ≤ 255,
`textarea` ≤ 5 000). Archiving a definition hides its value; a key is never reused for another type. Forms add one
FormKit field per active definition. The values are part of the record (versioned with it, merged, exported, erased).

### 6.5 Host links and panels

`crm_host_link` stores `(subject, host_type, host_id, label)`; the host adds links through `HostLinkServiceInterface`
(e.g. "company ↔ project 42") or the UI when a `CrmHostObjectResolverInterface` for that type exists. On render every
link is resolved per call; a link the resolver does not return (deleted, other tenant, no permission) is **hidden**,
never shown with its id. **(0.1.0)** A link of a type **without** a resolver (added through the service) shows its
stored label (or "type id") without a URL. The UI's add-link form offers only the resolver types and asks for the
host id; `CrmHostObjectResolverInterface::search()` (link picker) is called only for a resolver that also implements the optional `CrmHostObjectSearchInterface` (0.7.0; type names through the optional `CrmHostObjectTypeLabelInterface`). Panels (§8.2) are the host's own read models; the bundle passes the subject and the tenant and
renders what they return.

### 6.6 Timeline

`crm_activity` kinds: `created`, `updated` (changed field names only), `owner_changed`, `trashed`, `restored`,
`merged` (on the survivor, with the merged record's id), `note_added`, `note_edited`, `note_deleted`, `tagged`,
`untagged`, `linked`, `unlinked`, `affiliated`, `unaffiliated`, `consent_given`, `consent_withdrawn`, `imported`,
`exported` (GDPR export), `host` (written by the host through `TimelineInterface::record()` with a translation key and
≤ 20 scalar params). The 360° page shows the subject's entries (and, for a company, its contacts' entries) newest
first, keyset-paged by `id` (UUIDv7, so time order; **0.1.0**); a company page includes up to 200 of its contacts.

### 6.7 Later phases (shape only; detailed in their own contract revision before each build)

| Phase | Tables |
|---|---|
| B | `crm_pipeline` (name, position, version), `crm_stage` (pipeline, name, probability, position, kind `open/won/lost`), `crm_deal` (title, company, owner, stage, amount `decimal(18,4)` + `currency` ISO 4217, probability, expected_close, status, lost_reason, next_action_at, next_action, version), `crm_deal_contact`, `crm_activity_item` (call / meeting / e-mail / task with due and done), `crm_reminder` (due_at, recipient, dedupe_key unique, sent_at), `crm_counter` resource `deals` |
| C | `crm_product` (name, sku unique per tenant, unit price, tax rate, active), `crm_quote` (number, deal, company, contact, currency, status `draft/sent/accepted/declined/expired/superseded`, valid_until, version, totals), `crm_quote_line`, `crm_quote_token` (sha256 of the accept token, expires, used_at), `crm_sequence` (tenant, series, next value: atomic) |
| D | `crm_invoice` (series + number from `crm_sequence`, issue date, quote, company, totals, status `draft/issued/paid/partially_paid/overdue/cancelled`, verifactu_hash, aeat_reference, version), `crm_invoice_line`, `crm_payment` (amount, method, collected_at, reference), `crm_mandate` (implements SepaPaymentBundle `MandateRepositoryInterface`), `crm_debit_batch`, `crm_outbox` (after-commit jobs: VeriFactu submission, SEPA file) |
| E | `crm_segment` (rules json), `crm_campaign` (status, template, segment, scheduled_at, version), `crm_campaign_recipient` (unique `(campaign_id, contact_id)`, send id, status), `crm_suppression` (unique `(tenant_id, email_key)`), `crm_unsubscribe_token` (sha256) |
| F | `crm_portal_access` (contact, token hash, expires, last_used_on), `crm_portal_session` |
| G | none (reads; optional `crm_report_cache` with tenant key and TTL) |

### 6.8 Party roles and affiliation purposes (0.2.0)

- **Roles.** A company or a contact (also a standalone contact: a private customer) holds **any number** of party roles
  from the configured catalogue `roles.catalogue` (built in: `customer`, `supplier`, `partner`; `distributor` is
  translated but opt-in; a host adds its own keys). Keys match `^[a-z][a-z0-9_]{0,31}$` with `/D` (no trailing
  newline), at most 32, compared byte for byte (`RoleKey`); labels are the translation keys `role.<key>` of the
  `NowoCrmKitBundle` domain (7 locales for the built-in ones; a host translates its own keys in that domain). One row
  per role in `crm_role_assignment` (binary NO PAD collation on `role_key`, like every key column).
- **Not roles.** A prospect and a former customer are **lifecycle stages of the customer role** (the party lifecycle
  funnels of phase B, 0.3.0), not roles; a competitor is a **tag**.
- **A role removed from the configuration** never breaks a row: its assignments stay in the database and are
  **hidden** (record views, lists, filters, CSV export, forms); a role edit only adds or removes configured roles, so
  a hidden role is kept as it is and shows again when the key is configured again. The GDPR export lists **every
  stored role**, configured or not (it shows what the database holds about the person). The same rule applies to
  affiliation purposes.
- **Affiliations.** At most one **primary company per contact** (`is_primary`, unchanged) and at most one **primary
  contact per company** (`is_primary_contact`), enforced under the company's row lock (lock order contact → company,
  as `affiliate()`; an unticked box in the affiliation form keeps it, clearing is the company page's own action);
  one or more **purposes** from `affiliations.purposes` (built in: `billing`, `orders`,
  `technical`, `general`; same key rule, labels `purpose.<key>`), stored sorted and distinct in `purpose_keys` as
  `,billing,orders,` so a filter is one exact `LIKE` with escaped wildcards.
- **Filters.** `ListQuery::$role` (companies and contacts), `ListQuery::$purpose` and `ListQuery::$primaryContactOnly`
  (contacts; one affiliation must match every affiliation filter, with `companyId` too); list pages filter by role,
  purpose and "primary contacts only"; an unknown key in the URL is ignored.
- **Import.** Columns `roles` (keys separated by `,` or `;`, both types), and for contacts `purposes` and
  `primary_contact` (a row that changes the company's primary contact needs `edit` on that company, else it is
  `invalid` with `error.forbidden`; yes / no: `1`, `true`, `yes`, `y`, `x` / empty, `0`, `false`, `no`, `n`) of the affiliation to
  the named company. Keys are compared exactly (never case folded); an unknown key makes the row `invalid`
  (`error.role_unknown`, `error.purpose_unknown`). An import only adds roles and purposes, never removes them.
- **Export.** The CSV of a list has a `roles` column (configured keys, the format the import reads back).
- **Permissions** are unchanged and deny by default: roles, purposes and the primary flags are edited with `edit` on
  the record; making someone the company's primary contact needs `edit` on the company. Role edits are audited
  (`crm.roles.changed`, keys only), affiliation saves carry the primary-contact flag and the purpose keys
  (`crm.affiliation.saved`), the company's primary contact `crm.affiliation.primary_contact` (ids only); all after the
  commit. The 360° page shows the roles, the purposes and the primary contact; the GDPR export includes the roles and
  every affiliation with its purposes and flags.

## 7. Public services (what a host calls)

All in `Nowo\CrmKitBundle\Domain\Service`; inputs are VOs, enums, `RecordInput` / `ListQuery` or scalars, outputs read
models (`RecordView`, `ListResult`, …) or ids. **(0.1.0)** Ids are passed as canonical lowercase UUID strings (an
invalid one is 404), not as `RecordId` objects; one `RecordServiceInterface` serves companies and contacts. Every call resolves the tenant and the actor per call and checks the permission (404 / 403).

| Service (interface) | Methods (summary) |
|---|---|
| `RecordServiceInterface` **(0.1.0)** | `create(RecordInput): string`, `update(string $id, RecordInput, int $expectedVersion): int` (new version), `trash(SubjectType, string $id, int $v)`, `restore(SubjectType, string $id)`, `purge(SubjectType, string $id)` (from the trash, `manage`), `affiliate(string $contactId, string $companyId, ?string $role, bool $primary): string` (an existing pair is updated), `unaffiliate(string $affiliationId)`. `RecordInput(SubjectType $type, array $values, ?string $owner, ?array $custom, ?list $tagIds, bool $setOwner = true)`: raw core values (a missing key keeps the value), custom values (null keeps them), tag ids (null keeps them), `setOwner: false` keeps the owner |
| `RecordServiceInterface` **(0.2.0)** | `affiliate(string $contactId, string $companyId, ?string $role, bool $primary, ?bool $primaryContact = null, ?list $purposes = null): string` (null keeps the stored flag / purposes), `setPrimaryContact(string $companyId, ?string $contactId)` (null clears), `setRoles(SubjectType, string $id, list $roles)` (set semantics over the configured roles); `RecordInput::$roles` (null keeps them) on create / update |
| `CrmCatalogueInterface` **(0.2.0)** | `roles(): list<string>`, `purposes(): list<string>` (the configured keys) |
| `CrmReaderInterface` | `record(SubjectType, id): RecordView` (a merged id returns the tombstone with `mergedIntoId`), `list(ListQuery): ListResult` (offset pages with the total), `timeline(SubjectType, id, int $limit = 50, ?string $before)`, `duplicates(SubjectType, id)`, `notes(SubjectType, id)`, `links(SubjectType, id)`, `panels(SubjectType, id)`, `consents(contactId)` (the history), `tags()`, `fields(SubjectType, bool $withArchived)`, `owners()` |
| `TagServiceInterface` | `create(string $name, string $colour): string`, `change(id, name, colour, int $v)`, `delete(id)`, `assign(SubjectType, id, list<tag id>)` (set semantics); the tag list is `CrmReaderInterface::tags()` |
| `FieldServiceInterface` | `define(SubjectType, string $key, string $label, FieldType, list $choices, bool $required, int $position): string`, `change(id, label, choices, required, position, int $v)` (key and type never change), `archive(id, bool $archived, int $v)` (also brings a field back); definitions through `CrmReaderInterface::fields()` |
| `NoteServiceInterface` | `add(SubjectType, string $subjectId, string $body, bool $pinned): string`, `edit(id, body, pinned, int $v)`, `delete(id)` |
| `HostLinkServiceInterface` | `link(SubjectType, string $subjectId, HostObjectRef, ?label): string` (idempotent), `unlink(id)`, `subjectsLinkedTo(HostObjectRef): list<SubjectRef>` (the reverse lookup a host page uses: "companies of project 42"); the links of a record are `CrmReaderInterface::links()` |
| `TimelineInterface` | `record(SubjectType, string $subjectId, string $labelKey, array $params, ?string $url)` (host entries; `url` a local path) |
| `ConsentServiceInterface` | `record(string $contactId, string $purpose, bool $granted, ConsentSource, ?string $evidence): string`, `current(contactId): array<purpose, bool>`; the history is `CrmReaderInterface::consents()` |
| `ImportServiceInterface` | `upload(SubjectType, ImportFormat, string $fileName, string $bytes): string` (parses into rows, status `preview`), `preview(id, int $rows = 20): ImportView`, `confirm(id, mapping, DuplicatePolicy, int $v)`, `run(id, ?int $maxChunks): bool` (done?), `cancel(id)` |
| `MergeServiceInterface` | `preview(SubjectType, string $survivorId, string $otherId): MergePreview`, `merge(SubjectType, survivor, other, list $takeFromOther, int $survivorVersion, int $otherVersion): void` |
| `CrmExporterInterface` | `csv(ListQuery): iterable<string>` (one method for both types: the query's type; streamed lines, CSV-injection safe), `vcard(string $contactId): string` (vCard 4.0) |
| `CrmPersonalDataInterface` | `export(string $contactId): array` (JSON-ready), `erase(string $contactId): void` (no reason code) |
| `CrmSettingsInterface` | `get(): array{trash_days, timeline_years, import_keep_days, merge_keep_days, version}`, `update(int $trashDays, int $timelineYears, int $importKeepDays, int $mergeKeepDays, int $expectedVersion)` |
| `CrmBatchLookupInterface` **(0.2.1)** | **System API** (the exception to "every call checks the permission": no tenant resolution, no actor, no permission or visibility check; trusted backend jobs only, §13). Tenant-scoped, one read query per call (per chunk of 500): `contactsByEmails(TenantRef, list<string>): array<emailKey, list<contactId>>`, `companiesByDomains(TenantRef, list<string>): array<domainKey, list<companyId>>`, `subjectsLinkedToMany(TenantRef, list<HostObjectRef>): array<"type:id", list<SubjectRef>>`, `affiliationsOf(TenantRef, list<contactId>): array<contactId, list<companyId>>` (primary first, then affiliation date, then id), `rolesAndTagsOf(TenantRef, list<SubjectRef>): array<"type:id", RolesAndTags>`, `existing(TenantRef, list<SubjectRef>): list<SubjectRef>` (rows still present in the tenant whatever their status; purged ones missing). Active records only except `rolesAndTagsOf` / `existing` (trashed, merged and erased excluded); every usable input answered |

## 8. Ports and SPIs (host-implemented; each has a safe default)

All in `Nowo\CrmKitBundle\Domain\Port`, configured by service id; **no host override of a bundle service is ever
needed** (owner fixed point).

### 8.1 Required for a real deployment

```php
interface CrmTenantResolverInterface                 // [SingleTenantResolver: TenantRef::default()]
{
    /** Tenant of the current call; null = no tenant: every page and action answers 404. Per call, never cached. */
    public function current(): ?TenantRef;
}

interface CrmAccessCheckerInterface                  // [SecurityCrmAccessChecker (0.2.0): area checker + record checker + event]
{
    /**
     * Deny by default. $subject (type, id, owner) narrows the grant to one record (e.g. "only own companies");
     * null asks for the tenant-wide grant (lists, imports, settings).
     */
    public function isGranted(CrmPermission $permission, TenantRef $tenant, ?SubjectRef $subject = null): bool;

    /** The person acting, per call; null = nobody signed in (404). */
    public function currentActor(): ?ActorRef;
}

interface CrmFeatureGateInterface                    // [ConfiguredFeatureGate: feature.default_enabled (true)]
{
    /** Whether the CRM is on for the tenant (Beacon: organization feature + plan). Off: every route 404, nothing rendered. */
    public function isEnabled(TenantRef $tenant): bool;
}

interface CrmPlanLimitsInterface                     // [ConfiguredPlanLimits: limits.<resource> (null = unlimited)]
{
    /** Maximum active records of the resource for the tenant; null = no limit. */
    public function limit(TenantRef $tenant, CrmResource $resource): ?int;
}

interface CrmActorDirectoryInterface                 // [SecurityActorDirectory: the signed-in user only]
{
    public function actor(TenantRef $tenant, ActorRef $actor): ?ActorProfile;   // must return exactly that ref (===)
    /** @return list<ActorProfile> people who may own records (owner pickers) */
    public function owners(TenantRef $tenant): array;
}
```

**(0.2.1) The actor id.** `Infrastructure\Symfony\Security\CrmActorResolverInterface` (`security.actor_resolver`;
default `IdentifierActorResolver` = `getUserIdentifier()`, so no stored id changes) gives the actor id of a signed-in
person: `idOf(UserInterface): string` is what the access checker's `currentActor()` and the default directory use,
so it is what every owner, note author, created_by / updated_by, timeline actor, consent recorder, import uploader,
merge actor and audit actor stores. `resolve(list<string> $ids): array<id, ActorProfile>` (the Domain port
`CrmActorProfilesInterface`) names the people of one page in one call; ids it does not name fall back to
`CrmActorDirectoryInterface::actor()`. A host whose identifier can change (an e-mail) returns a stable id (a user
UUID); UPGRADING has the migration of existing ids.

### 8.2 Extension points

| Port | Default | Purpose |
|---|---|---|
| `CrmPanelProviderInterface` (tagged `nowo_crm_kit.panel`, priority order) | none | `key(): string` (`^[a-z0-9_-]{1,40}$`), `supports(SubjectType): bool`, `panel(PanelContext $ctx): ?Panel`. `PanelContext`: tenant, subject (`SubjectRef`), actor, linked host refs of the subject. `Panel`: title (translation key + domain, or text), `list<PanelItem>` (title, url (local path or http(s)), subtitle, badge text + tone, meta), `total`, `moreUrl`, `emptyText`, `position` (`main` \| `side`). Returns null to show nothing. A provider that throws is logged (class names only) and skipped; a transient storage failure is rethrown (REQ-RUNTIME-003 rule 8) |
| `CrmHostObjectResolverInterface` (tagged `nowo_crm_kit.host_object`) | none | `type(): string`, `resolve(TenantRef, list<string> $ids): array<string, HostObjectView>` (label, url; only the ids that exist in the tenant and the actor may see; keys must equal the requested ids byte for byte), `search(TenantRef, string $text, int $limit): list<HostObjectView>` for the link picker (**0.7.0**: called only when the resolver also implements the optional `CrmHostObjectSearchInterface`; `CrmHostObjectTypeLabelInterface::typeLabel(): HostTypeLabel` names the type) |
| `CrmAuditSinkInterface::record(AuditEntry)` | `PsrLogAuditSink` (one structured line) | `crm.*` audit keys of every write (§13); AuditKit bridge later |
| `IdGeneratorInterface::new(): string` | `SymfonyUuidGenerator` (UUIDv7) | Row ids |
| `Psr\Clock\ClockInterface` | native clock | Every "now"; tests use `MockClock` |
| (B) `CrmNotifierInterface` | NotifyKit bridge, else `NullNotifier` | Reminders and assignment notices |
| (B) `CrmAttachmentStoreInterface` | none (attachments off) | Note / activity attachments (StorageKit bridge once it stores non-image files) |
| (C) `Nowo\Brand\Contract\BrandProviderInterface` | container alias or neutral | PDF and e-mail branding (tokens only) |
| (C) `CrmQuoteAcceptedHandlerInterface` | none | Host hook: create the project / board from a template |
| (D) `CrmBillableLinesProviderInterface` | none | Host billable lines (TimeTrack hours) for an invoice |
| (F) `CrmPortalPanelProviderInterface` | none | What a signed-in contact sees from the host |

## 9. Behaviour of phase A

- **Create / edit** a company or contact: FormKit form (core fields + active custom fields + owner + tags); server-side
  validation; `version` posted as a hidden field; a stale version re-renders the form with the flash "Someone changed
  this record at the same moment" and status 409 (§15.2). Creating counts against the plan limit (§15.1).
- **Affiliations**: a contact links to 0…n companies, each with a role; at most one is primary (changing it bumps the
  contact's version). The company page lists its contacts with role; the contact page lists its companies.
- **Tags**: assign by picking existing tags; `manage` creates, renames (version) and deletes them (taggings removed).
- **Notes**: plain text up to 10 000 characters, pinned notes first; author or `manage` edits / deletes.
- **Import** (`edit`): upload a CSV (UTF-8, `,` or `;`, BOM stripped, header row, ≤ `import.max_rows` 5 000 rows,
  ≤ `import.max_bytes` 5 MiB) or vCard 3.0 / 4.0 (`FN`, `N`, `EMAIL`, `TEL`, `ORG`, `TITLE`, `ADR`); preview shows the
  first 20 rows, the detected column mapping (editable), invalid rows and the existing contact with the same e-mail;
  confirm runs it with `on duplicate: skip | update` **(0.1.0)**: in chunks in the confirm request up to
  `import.sync_rows`, then a "Continue" button runs the next `sync_rows`, and `nowo:crm-kit:import:resume` takes over
  stale claims; no Messenger handler. Tags in a file must name existing tags (else the row is `invalid`); a contact's
  `company` column affiliates to an existing active company of exactly that name (case-insensitive); companies have no
  duplicate key. CSV cells are read as text (no formula evaluation); every value goes through the same
  validation as the form. Rows over the plan limit end as `limit`.
- **Merge** (`edit` on both records): from a record or a duplicate hint, pick the other record; the preview shows every
  field side by side with a choice per field (default: the survivor's value, or the other's when the survivor's is
  empty); confirm moves the children and keeps a snapshot (§15.3).
- **Trash / restore / purge**: trash (`edit`) hides the record everywhere (lists, search, panels, links); restore within
  `trash_days` (`edit`, plan limit again); purge from the trash (`manage`) deletes it with its children.
- **Export**: CSV of the current list filter (`manage`, Q-O5), vCard of one contact (`view`), GDPR export (`manage`).
- **360° page**: header (name, owner, tags, status) with actions (edit, merge, trash, focus mode); sections: details
  (core + custom fields), contacts (company) / companies (contact), host links, host panels (main and side), notes,
  timeline; possible duplicates box; consent block (contact).

## 10. Configuration (strict tree, REQ-SF-006; defaults shown)

```yaml
nowo_crm_kit:
    tenant_resolver: null          # service id of CrmTenantResolverInterface; null = single tenant "default"
    access_checker: null           # DEPRECATED (0.2.0, removed in 0.3.0): a 0.1.0 CrmAccessCheckerInterface service id
    feature_gate: null             # service id; null = ConfiguredFeatureGate (feature.default_enabled)
    plan_limits: null              # service id; null = ConfiguredPlanLimits (limits.*)
    actor_directory: null          # service id; null = the signed-in security user only
    audit_sink: null               # service id; null = PSR log
    clock: null                    # service id of Psr\Clock\ClockInterface
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
        default_enabled: true
    limits:
        companies: null            # null = unlimited
        contacts: null
    doctrine:
        entity_manager: nowo_crm_kit   # private EM (the Flex recipe declares it); null = the default EM, explicit transactions
        table_prefix: nowo_         # 1-16 characters of a-z, 0-9, _
    fields:
        max_per_subject: 50        # 1..200
    import:
        max_rows: 5000             # 1..50000
        max_bytes: 5242880         # 1 KiB..50 MiB
        sync_rows: 500             # rows run in the confirm request (10..50000)
        chunk_rows: 100            # rows per transaction (10..1000)
        stale_claim_seconds: 600   # a running import without progress for this long may be resumed (60..86400)
    gdpr:
        purposes: [marketing_email, newsletter, tracking]   # consent purposes the UI offers
    roles:                         # 0.2.0
        catalogue: [customer, supplier, partner]   # party role keys (≤ 32, ^[a-z][a-z0-9_]{0,31}$); distributor is opt-in
    affiliations:                  # 0.2.0
        purposes: [billing, orders, technical, general]   # what a contact is a company's contact for (same key rule)
    search:
        enabled: true              # SearchKit providers (bridge) when SearchKit is installed and ui.enabled
                                   # (0.1.0: no per_group key; SearchKit's query limit applies)
    ui:
        enabled: false             # routes are opt-in (REQ-UI-001)
        path: /crm
        page_size: 25              # 10..100
        layout: null               # null = UiKit admin layout
    pipeline: { enabled: false }   # phase B, 0.3.0 (funnels)
    quotes: { enabled: false }     # phase C, 0.5.0
    invoices: { enabled: false }   # phase D, 0.6.0
    portal: { enabled: false }     # phase F, 0.7.0
    campaigns: { enabled: false }  # phase E2, 0.10.0 (circulars)
    reports: { enabled: false }    # phase G, later
```

Container validation: a later phase needs the earlier one it builds on (decided in each phase's contract revision); a phase that is not
built yet refuses `enabled: true` with a message naming the release that brings it; `ui.path` starts with `/`.
Per-tenant settings (`trash_days`, `timeline_years`, `import_keep_days`, `merge_keep_days`) live in `crm_settings`
(settings page, `manage`). No phase-A value is a typical `.env` value that changes at runtime, so no
`RuntimeSettingDefinition` is registered in 0.1.0; phase D's AEAT / SEPA credentials and phase E's tracking secret will
be (Q-O12).

## 11. Routes, UI and API

### 11.1 UI (opt-in, `ui.enabled: true`; route loader `nowo_crm_kit_ui`; the host imports it with its prefix)

UiKit scaffolds (`ListPage`, `ShowPage`, `FormPage`, `SettingsPage`, page header with tabs) and FormKit types
(REQ-UI-001/002/003). Lists: server filters and sort, `per_page`, `responsive: cards` on phones, row actions in a kebab
that also opens on right-click / long press (`context_menu: true`), empty states; record and list pages carry the
focus-mode toggle. Every POST is CSRF-protected; write controllers run in write units. **(0.1.0)** Row actions do not
set `context_menu` themselves: right-click / long press follows the host's `nowo_ui_kit.theme.context_menus: true`,
and the focus-mode toggle renders only with the host's `nowo_ui_kit.theme.focus_mode.enabled: true`. Routes live under
the import prefix **and** `ui.path`.

| Route (under `ui.path`) | Methods | Permission |
|---|---|---|
| `/` ⇒ redirect to `/companies` | GET | view |
| `/companies` (q, tag, owner, status, sort, direction, page, per_page; **0.2.0** role; contacts also purpose, primary_contact) | GET | view |
| `/companies/new` | GET, POST | edit |
| `/companies/{id}` (360°; a merged id redirects to the survivor) | GET | view |
| `/companies/{id}/edit` | GET, POST | edit |
| `/companies/{id}/{trash\|restore}` | POST | edit |
| `/companies/{id}/purge` | POST | manage |
| `/companies/{id}/merge` (pick; `?other=<id>` preview and confirm) | GET, POST | edit |
| `/contacts…` | the same set | the same |
| `/contacts/{id}/affiliations`, `/affiliations/{id}/remove` | POST | edit |
| `/companies/{id}/primary-contact/{contact}`, `/companies/{id}/primary-contact/clear` **(0.2.0)** (action token) | POST | edit on the company |
| `/contacts/{id}/consent` | POST | edit |
| `/contacts/{id}/vcard` | GET | view |
| `/contacts/{id}/gdpr-export`, `/contacts/{id}/erase` | POST (both with the action token) | manage |
| `/{type}/{id}/notes`, `/notes/{id}/edit`, `/notes/{id}/delete` | POST | edit |
| `/{type}/{id}/tags` | POST | edit |
| `/{type}/{id}/links`, `/links/{id}/remove` | POST | edit |
| `/import`, `/import/{id}` (preview, confirm; `do=run` continue, `do=cancel`) | GET, POST | edit |
| `/admin/tags`, `/admin/tags/{id}` (rename, recolour, delete) **(0.1.0)** | GET, POST | manage |
| `/admin/fields`, `/admin/fields/{id}` (change, archive) **(0.1.0)** | GET, POST | manage |
| `/{companies\|contacts}/export.csv` **(0.1.0)** | GET | manage |
| `/admin/settings` **(0.1.0)** | GET, POST | manage |

### 11.2 Public JSON API (versioned, off by default, Q-O6; **0.3.0**, decided by the owner on 2026-11-19, Q-O16)

`<api.prefix>/crm/v1/{companies,contacts}` (list with filters and keyset cursor, get, create, update, trash),
`/companies/{id}/contacts`, `/contacts/{id}/consents`, `/links`, `/tags`. Auth: the host's firewall (ApiToken bundle in
Beacon), the same access checker. Writes: `If-Match: "<version>"` (412 without it, 409 `crm_record_stale` when stale),
`Idempotency-Key` on POST (stored with the answer for 24 h, unique per tenant + key + route). Errors
`{"error": "...", "code": "..."}`; conflicts as §11.3. OpenAPI `docs/openapi/crm-v1.yaml`. Container parameter
`nowo_crm_kit.api_prefix` for the host's `security.yaml`.

### 11.3 Errors

`404` CRM off for the tenant, no tenant, no actor, a record of another tenant (never 403 across tenants); `403` missing
permission inside an enabled tenant; `409` lock conflict (transaction-doctrine responder: `CONFLICT_001` + `Retry-After`
+ `X-Conflict-Code: CONFLICT_001`, or `CONFLICT_002` without `Retry-After` when part of the request committed), stale
version (`crm_record_stale`, a domain conflict: own body code, no `X-Conflict-Code`), merged / trashed record
(`crm_record_merged`, `crm_record_trashed`), plan limit (`crm_limit_reached`, 409 without `Retry-After`); `400`/`422`
invalid form or input. **(0.1.0)** A file over `import.max_bytes` is a form error on the upload page (422), not 413.
POST actions of the HTML UI (trash, restore, notes, tags, links, consent) flash a domain conflict and redirect (303);
the record and import forms answer 409 / 422 themselves.

## 12. SearchKit provider (bridge)

`Bridge\SearchKit\CrmSearchProvider implements SearchProviderInterface`, **(0.1.0)** registered twice, as
`nowo_crm_kit.search.company` (group `crm_companies`, priority 60) and `nowo_crm_kit.search.contact` (group
`crm_contacts`, priority 59), only when SearchKit is installed (it needs PHP >= 8.3; the bundle's tests use stubs),
`ui.enabled` and `search.enabled`: per call resolves the tenant, the feature gate and `crm.view`; searches active
companies (name, legal name, tax id key, domain, e-mail) and contacts (sort and display name, e-mail key, phone and
mobile as typed: **no digit normalisation**) of that tenant only, `LIKE` with escaped wildcards, at most SearchKit's
query limit plus the visible total; titles and subtitles are record data rendered by
SearchKit as text; `moreUrl` to the filtered list. Any refusal returns `null` (no 403, no existence hint). A scoped
query (`SearchQuery::$scope`) matching an active company id of the tenant searches that company's contacts; any other
scope (or a scope on the company provider) answers `null`.

## 13. Permission matrix (deny by default)

Every call (except the **0.2.1** system APIs `CrmBatchLookupInterface`, §7: tenant-scoped, no actor, no permission or
visibility check, and `CrmActorPersonalDataInterface`, §16.5: no tenant scope at all; trusted backend jobs only, never
end users, HTTP APIs or agent tools): tenant (none ⇒ 404) → feature gate (off ⇒ 404) → actor (none ⇒ 404; **0.2.0** on the pages behind a
firewall: the firewall's entry point, login redirect or 401) → `isGranted()` (false ⇒ 403); a record of another tenant
is 404.

**(0.2.0) The house access shape (REQ-UI-002).** The application keeps its port `CrmAccessCheckerInterface`; its
default adapter `SecurityCrmAccessChecker` asks, for each permission, the **area checker**
`CrmKitAccessCheckerInterface` (`canAccess`, `canCreate`, `canEdit`, `canDelete`, `canImport`, `canExport`,
`canManageSettings`, `canErase`; tenant-aware variant `TenantAwareCrmKitAccessCheckerInterface`; default
`ConfigurableCrmKitAccessChecker` on `security.*_roles`, `AllowAllCrmKitAccessChecker` for demos and tests, a host
service through `security.access_checker`), and, for one record, `CrmResourceAccessChecker`: deny by default when
`security.resource_scoping` is on (owner, ownerless record, owners added by `nowo_crm_kit.record_visibility`
listeners, or `canManageSettings`), then the event
`CrmKitEvents::RECORD_ACCESS_CHECK` (`RecordAccessCheckEvent`: user, actor, tenant, type, id, owner, action, default;
`grant()` / `deny()`, a deny wins). `CrmKitGuard` (`requireGranted`, `isGranted`, `grants`) is the pages' gate and a
`kernel.controller` listener requires `view` on every bundle route. The 0.1.0 root key `access_checker` still points
the port at a host service (deprecated, removed in 0.3.0), with the new permissions asked as the ones it knew.

| Action | Permission | Area (default roles) |
|---|---|---|
| Lists, 360° pages, timeline, panels, vCard of one contact, search | `view` | `canAccess` (`access_roles`) |
| Create companies and contacts | `create` | `canCreate` (`manage_roles`) |
| Edit records, notes, tags on records, roles, links, affiliations, consent records, merge | `edit` | `canEdit` (`manage_roles`) |
| Trash / restore | `delete` | `canDelete` (`manage_roles`) |
| Import | `import` | `canImport` (`manage_roles`) |
| CSV export of lists, GDPR export | `export` | `canExport` (`export_roles`) |
| Tags and fields administration, settings, purge from the trash, others' notes | `manage` | `canManageSettings` (`settings_roles`) |
| GDPR erasure | `erase` | `canErase` (`erase_roles`) |
| (B–D) Deal amounts, quote and invoice totals, revenue reports | `amounts_view` | `canViewAmounts` (`amounts_roles`) |
| (E2) Send a circular | `campaigns_send` | `canSendCampaigns` (`campaigns_roles`) |

Every area also needs `canAccess`. Notes: the author edits / deletes own notes with `edit`; others' notes need
`manage`. Lists follow only `RECORD_VISIBILITY` (owner-based); `RECORD_ACCESS_CHECK` decides single-record pages and
writes; to hide a record from lists, use its owner. Beacon maps its `crm.*` permissions in a host area checker (or keeps its 0.1.0 port for 0.2.0).

## 14. Events (dispatched after commit, ids and VOs only; REQ-ARCH-001 rules 4 / 7)

`Domain\Event\RecordCreated`, `RecordUpdated` (changed field names), `RecordTrashed`, `RecordRestored`, `RecordPurged`,
`RecordsMerged` (type, survivor, merged), `ContactErased`, `NoteAdded`, `ConsentRecorded` (contact, purpose, granted),
`ImportCompleted` (import id, counts), `TagsChanged`, `HostLinkAdded` / `HostLinkRemoved`. Each carries the
`TenantRef`, the `ActorRef` (nullable) and the occurred-at instant. A per-call after-commit buffer releases them; a
rollback or a write-unit replay drops what the failed attempt collected. Audit entries (`crm.company.created`,
`crm.contact.erased`, `crm.records.merged`, `crm.import.completed`, `crm.settings.changed`, …) are written through the
audit sink after commit with counts and ids only. **(0.2.0)** `RolesChanged` (type, id, added and removed keys) and the audit keys `crm.roles.changed`,
`crm.affiliation.primary_contact`. **(0.2.1)** `Infrastructure\Symfony\Event\CrmKitEvents` names every domain event
(constants equal to the class names). There is no `CompanyErased` (companies are not erased; they leave through the
trash and `RecordPurged`, or `RecordsPurged` from the retention purge). Erasure, purge and merge delete or move a
record's links without a `HostLinkRemoved` per link: `ContactErased`, `RecordPurged` / `RecordsPurged` and
`RecordsMerged` (type, survivor, merged) cover them. `RecordsPurged` (tenant, `list<SubjectRef>` with type + id only,
no actor) is dispatched once per committed retention-purge batch (≤ 200 records of one type), after that batch's
commit; a rolled-back batch dispatches nothing. **(0.1.0)** Audited: record create / update / trash / restore / purge,
merge, contact export and erasure, consent, note delete, tag and field administration, import upload / completion /
cancel, settings, retention purge. Not audited (timeline and events only): note add / edit, tag assignment, host link
add / remove, affiliations, host timeline entries.

## 15. Concurrency design per write path (REQ-RUNTIME-003)

Every write is one **write unit** (transaction-doctrine `WriteUnitInterface`): one transaction on the bundle's entity
manager, flushed before the commit, replayed up to 3 times after a deadlock / lock-wait timeout / serialization failure
(MySQL 1213 / 1205, MariaDB 1020, PostgreSQL 40001 / 40P01 / 55P03), side effects (events, audit) after the commit, the
entity manager cleared after the unit. A final conflict answers **409** through transaction-doctrine's responder with
`CONFLICT_001` + `Retry-After` + `X-Conflict-Code` (or `CONFLICT_002` without `Retry-After` when the request already
committed part of its work: request commit watermark, transaction-doctrine 1.6); JSON on the bundle's routes keeps
`{"error": "concurrency_conflict", "code": "CONFLICT_001"}`. **(0.1.0)** transaction-doctrine's bundle is required (its
commit ledger, DBAL middleware and request watermark produce `CONFLICT_002`): the container build fails with the fix
without it; when the host turns its conflict responder off, a fallback listener on the bundle's routes gives the same
status, codes and headers. A unit never holds an entity from an earlier attempt (ids only), never flushes, clears or
closes the host's entity manager (single-EM hosts: the runner begins, flushes and commits itself, rolls back and
detaches only the bundle's entities on a failure, and refuses to start while the host has pending changes), never
runs inside a transaction that is already open (a host's on the same connection: refused, since its events and audit
are released after its own commit), and never does HTTP, mail or file I/O inside a transaction.

| Write path | Transaction | Control | Conflict answer |
|---|---|---|---|
| Create company / contact | 1 unit | plan counter `UPDATE … WHERE value < limit` (§15.1); contact `email_key` unique **(0.1.0)**: on the unique refusal the other row is re-read in a fresh read; it exists ⇒ "e-mail in use" form error naming it; it vanished ⇒ one more run; refused again with no row ⇒ domain conflict `crm_write_conflict` (409, no `X-Conflict-Code`: nothing committed, a retry is safe); on an edit the form error appears only when another record (not the edited one) holds the e-mail | limit ⇒ 409 `crm_limit_reached` (no Retry-After); twice refused ⇒ 409 `crm_write_conflict` |
| Edit company / contact (fields, owner, tags in the form) | 1 unit | `version` posted, compared before the change, ORM version at flush; tags by unique `(tag, subject)` | stale ⇒ 409 `crm_record_stale`, form re-rendered |
| Trash / restore / purge | 1 unit | version on trash; status checked under `FOR UPDATE`; counter −1 / +1 (restore checks the limit) | stale / limit ⇒ 409 |
| Affiliate / unaffiliate, primary company | 1 unit | unique `(contact, company)`; affiliate locks the contact row, then the company row (`FOR UPDATE`, always in that order; imports too); unaffiliate locks the contact; both bump the contact's version; an existing pair is updated (role, primary); a merged or non-active parent ⇒ 409 | racing duplicate ⇒ one more run as an update, refused again ⇒ 409 `crm_write_conflict`; lock ⇒ CONFLICT_001 |
| **(0.2.0)** Role edit (`setRoles`) | 1 unit | the record locked first (`FOR UPDATE`), then set semantics over the configured roles; unique `(tenant, record, role)`; a form edit or an import that does not lock the record and stores the same role ⇒ one more run | lock ⇒ CONFLICT_001; refused twice ⇒ 409 `crm_write_conflict` |
| **(0.2.0)** The company's primary contact (`setPrimaryContact`, `affiliate(…, primaryContact: true)`, import column) | 1 unit | the contact locked, then the company (`FOR UPDATE`, the order of `affiliate()`); the company's affiliations re-read under the company lock, every other flag cleared; a merge of contacts locks the companies of both contacts after the contacts, in id order | lock ⇒ CONFLICT_001; never two primary contacts |
| Tag create / rename / delete; assign | 1 unit | unique `(tenant, name_key)`; version on rename; assign = set difference, insert with unique catch (idempotent) | duplicate name ⇒ form error; stale ⇒ 409 |
| Field define / update / archive | 1 unit | unique `(tenant, subject_type, field_key)`; version (archive: the version the page showed); count ≤ `max_per_subject` **(0.1.0)** counted after locking the `fields.<type>` counter row of the tenant (`FOR UPDATE`), so two racing defines cannot pass the bound | duplicate ⇒ form error; stale ⇒ 409 |
| Note add / edit / delete | 1 unit | the parent record locked first (`FOR UPDATE`); version on edit; delete by id (gone ⇒ 404) | merged / non-active parent ⇒ 409; stale ⇒ 409 |
| Host link add / remove, host timeline entry | 1 unit | the parent record locked first; unique 5-column key (idempotent add); a type with a resolver links only an object the resolver confirms (byte-exact id) | merged / non-active parent ⇒ 409; unknown object ⇒ form error |
| Consent record | 1 unit | the contact locked first; insert only; the current state is the latest `(recorded_at, id)` per purpose (UUIDv7 ordering breaks ties) | merged / erased contact ⇒ 409; lock ⇒ CONFLICT_001 |
| Settings | 1 unit | upsert: insert with PK catch, then version | stale ⇒ 409 `crm_settings_stale` |
| Import upload | 1 unit | rows inserted with the import row (unique `(import_id, row_no)`) | — |
| **Import confirm / run** | **claim unit + one unit per chunk** | §15.4; **(0.2.0, UD2-L-1)** each chunk first locks the existing contacts of its e-mails in id order, then the companies its rows name in id order (the lock order of every other path), never row by row | second confirm ⇒ 409 `crm_import_running` or the finished result; lock in a chunk ⇒ that chunk replays |
| **Merge** | **1 unit** | §15.3 | stale / merged ⇒ 409 `crm_merge_stale` |
| GDPR erasure | 1 unit | contact `FOR UPDATE`; status checked; children deleted by primary key after an exact re-select; the merge tombstones pointing at it (recursively) locked `FOR UPDATE` and blanked, their merge traces deleted | already erased ⇒ idempotent |
| Retention purge (command) | 1 unit per batch of 200 | **(0.1.0)** single-run claim (claim row `FOR UPDATE`, conditional `UPDATE`, refreshed in every batch, released only while it is still the run's); trashed records and merge tombstones re-selected and re-checked (status, dates) under `FOR UPDATE`; timeline, import rows and merges deleted by id after a tenant re-check | second run ⇒ "another purge is running" at once **(0.2.0, UD2-L-3)**: the claim row is read `FOR UPDATE SKIP LOCKED`, so a run never waits on another run's batch; a run whose claim was taken over stops; batch replays |
| Counter rebuild (command) | 1 unit per tenant and resource | **(0.1.0)** the counter row `FOR UPDATE` first (selected, inserted only when missing, selected again), then `COUNT(*)` of active rows, then set | — |

**(0.2.0, UD2-L-2) A replay clears the host's entity manager.** When a write unit is replayed after a deadlock, a
lock-wait timeout or a serialization failure, transaction-doctrine (1.6) clears **every** open entity manager before
the next attempt, the host's default one included, in private and in shared mode. A host that calls a CRM write while
it holds managed entities of its own must re-read them after the call (or call the CRM before it loads them). This is
inherited from transaction-doctrine; a write unit scoped to the bundle's own manager is a platform item. **(0.3.0)** Resolved: the bundle requires transaction-doctrine ^1.8.1, whose replays put a host manager that managed
entities back to what the database holds instead of clearing it (GHSA-9wh3-m3m5-jffx, GHSA-65w2-9r4h-848g).

### 15.1 Plan-limit counters

`crm_counter (tenant_id, resource, value)`: the row is created with `INSERT … ON DUPLICATE KEY` / `ON CONFLICT DO
NOTHING` **(0.1.0: `INSERT IGNORE` on MySQL / MariaDB, `INSERT OR IGNORE` on SQLite)** from `COUNT(*)` of active rows on first use, then only moved atomically:
`UPDATE <p>crm_counter SET value = value + 1 WHERE tenant_id = ? AND resource = ? AND (value < ? OR ? IS NULL)`; zero
rows ⇒ `ConflictException('crm_limit_reached')` **(0.1.0)** (409 `crm_limit_reached`, no Retry-After: a retry cannot succeed). Decrement on trash,
merge, purge, erasure; increment on create, restore. The counter changes in the same transaction as the record, so a
rollback restores it. `nowo:crm-kit:counters:rebuild` recounts (drift after manual SQL).

### 15.2 Version checks

Every editable row has `version`; forms and future API calls send the version they showed. The service compares it
before applying the change (cheap refusal) and Doctrine's optimistic lock compares it again at flush (the real guard
against a writer between the read and the flush). A mismatch is a **domain conflict** (`ConflictException('crm_record_stale')` **(0.1.0)**, not a
lock conflict: retrying the same version cannot succeed), so it answers 409 with its own body code
(`crm_record_stale`), no `Retry-After` and no `X-Conflict-Code` (platform convention); the HTML form is re-rendered
with the other writer's values and a flash (status 409).

### 15.3 Merge

```
merge(type, survivorId, otherId, take, vS, vO):
  unit:
    [a, b] = sort([survivorId, otherId])                      # fixed lock order: no deadlock between A→B and B→A
    SELECT … FROM crm_<type> WHERE id IN (a, b) AND tenant_id = ? ORDER BY id FOR UPDATE
    re-check: both rows exist with tenant === current (PHP), status Active, versions === (vS, vO), survivor ≠ other
      else 409 crm_merge_stale (nothing changed)
    survivor.apply(take ⊆ allowed fields from other); custom = union (survivor wins unless taken)
    contacts: other.email_key → NULL first (flush), then survivor may take the e-mail (unique key never doubled)
    children of other, selected by (tenant_id, subject_type, subject_id) and re-checked === in PHP, moved by PK:
      taggings / affiliations / host links: delete the ones the survivor already has, move the rest
      notes, activities, consents: move
    other.status = Merged, merged_into_id = survivor, personal columns blanked; survivor.version++ (ORM); counter −1
    insert crm_merge (snapshot of other, taken fields); activity "merged" on survivor
  after commit: RecordsMerged, audit crm.records.merged
```

Races: A→B and B→A ⇒ the second waits on the first lock, then sees `Merged` ⇒ 409. A→B and C→A ⇒ ordered locks
serialize them; the later one sees A merged or its version moved ⇒ 409. A merge racing an edit of either record ⇒ the
edit's optimistic version check or the merge's version check refuses the later one. Tested on two connections on
MySQL, MariaDB and PostgreSQL (§21).

### 15.4 Import

```
confirm(id, mapping, policy, v):        unit: UPDATE crm_import SET status='running', claim_token=?, claimed_at=now,
                                              mapping=?, duplicate_policy=?, version=version+1
                                              WHERE id=? AND tenant_id=? AND status='preview' AND version=?
                                        0 rows ⇒ 409 crm_import_running (0.1.0: also when done; the UI flashes it
                                        and shows the import's page with its result)
run(id, maxChunks): loop (0.1.0: the token is read from the claimed import):
                   unit (per chunk of chunk_rows):
                     SELECT import FOR UPDATE; still running with my claim_token? else stop (taken over / cancelled)
                     SELECT pending rows ORDER BY row_no LIMIT chunk_rows
                     (0.2.0) lock the existing contacts of the rows' e-mails FOR UPDATE in id order, then the
                     companies the rows name in id order (before any row is settled)
                     per row: validate → plan counter +1 (or outcome limit) → insert / update the record
                              (e-mail unique: a racing import or user stored it ⇒ the whole chunk replays once in a
                              fresh unit and the row becomes a duplicate) → UPDATE row SET outcome=? WHERE id=? AND
                              outcome='pending'
                     UPDATE import SET rows_done = rows_done + n, created_count = created_count + c, …, claimed_at=now
                 last chunk: status='done', finished_at; after commit: ImportCompleted, audit
resume command:  UPDATE crm_import SET claim_token=?, claimed_at=now, version=version+1
                 WHERE id=? AND tenant_id=? AND status='running' AND claimed_at < now - stale
                       AND claim_token IS NOT NULL
                 (0.2.1) stops before a row that needs a person: in the same unit
                 UPDATE crm_import SET claim_token=NULL, claimed_at=now, version=version+1
                 WHERE id=? AND status='running' AND claim_token=?   (waits for a person)
continue:        (0.2.1) on a paused import: UPDATE crm_import SET claim_token=?, claimed_at=now, version=version+1
                 WHERE id=? AND tenant_id=? AND status='running' AND claim_token IS NULL (one winner)
```

A double confirm stores nothing twice (one claim wins; rows settle once). A killed worker leaves `running` with
progress; the resume command takes over a stale claim **(0.1.0)**; the page's "Continue" button (anyone with `edit`)
runs further chunks under the current claim, and "Cancel" ends a preview or a stale running import. **(0.2.1)** An
import the resume command paused (no claim: it waits for a person) is skipped by later resume runs, claimed at once by
"Continue" and cancellable at once; no schema change (the empty `claim_token` of a running import is the flag). Each chunk is
small so locks are short; no file I/O inside a unit (the file is parsed at upload, before the unit that stores the rows).

## 16. GDPR

### 16.1 Legal basis and consent

The tenant is the controller; the bundle is the tool. Legitimate interest (B2B contact data) or contract covers the CRM
itself; **consent** is recorded per contact and purpose (`gdpr.purposes`: `marketing_email`, `newsletter`, `tracking`
by default) with source, evidence (e.g. "web form 2026-11-18"), actor and time, append-only. Phase E sends only to
contacts whose current `marketing_email` (or the campaign's purpose) consent is granted and who are not suppressed;
tracking only with `tracking` consent.

### 16.2 Export (`CrmPersonalDataInterface::export`, `manage`)

One JSON document: the contact's fields and custom fields, **(0.2.0)** every stored party role (configured or not),
affiliations (company names, **(0.2.0)** with the purposes, the primary-company and primary-contact flags), tags, notes about the contact,
consents (full history), timeline entries, host links (type + id), merges where it was the merged record. Audited
(`crm.contact.exported`), a timeline entry `exported`.

### 16.3 Erasure (`erase(string $contactId)`, `manage`; **0.1.0**: no reason code)

Under the contact's lock: names become a fixed translated placeholder ("Erased contact"), every other personal column
(e-mail, phone, mobile, job title, address, custom values) NULL / empty, `email_key` NULL, `status = Erased`,
`erased_at`; notes, taggings, **(0.2.0)** role assignments, host links, affiliations, timeline entries and merge
snapshots of the contact deleted;
consent rows kept **without evidence** (proof that consent was given or withdrawn, art. 7.1 — Q-O8); the owner is
cleared too; counter −1 when the contact was active; audit
`crm.contact.erased` (ids only). An erased contact cannot be edited, restored or merged. Export ↔ erasure parity test
(everything the export returns is gone or blank after the erasure).

### 16.4 Retention (`nowo:crm-kit:purge`, daily; `--tenant`, `--dry-run`)

Per tenant: trashed records older than `trash_days` purged with their children; timeline entries older than
`timeline_years` deleted; import rows of imports finished more than `import_keep_days` ago deleted (they hold personal
data); merge snapshots older than `merge_keep_days` deleted. Batches of 200, one transaction each; audit summary per
tenant (counts only, `crm.retention.purged`); **(0.2.1)** each committed batch of deleted records (trashed records,
merge tombstones) dispatches `RecordsPurged` (tenant, type + id of each record) after its commit, nothing on a
rollback; `--dry-run` **(0.1.0)** counts trashed records only (up to one batch
per type). Import rows are deleted only for finished imports; the `crm_import` rows themselves stay. Inactive-contact review (contacts without activity for N years) is a report in
phase G (Q-O8).

### 16.5 A host person's references (0.2.1, system API)

`CrmActorPersonalDataInterface::exportActor(string $actorId): array` and `anonymiseActor(string $actorId): int` (their
own interface, so the tenant-scoped `CrmPersonalDataInterface` stays safe for host GDPR controllers) cover every
CRM reference to a host person's actor id **in every tenant**: owner, note author, created_by / updated_by, timeline
actor, consent recorder, host-link and role creator, import uploader, merge actor, and the owner inside owner-changed
timeline entries and merge snapshots (kinds in `Application\Port\CrmActorDataStoreInterface::KINDS`). The export
lists tenant ⇒ kind ⇒ row keys. Anonymisation replaces the id with the placeholder `anonymised`, keeps the records
and everyone else's data, moves the version of versioned rows (a form opened before fails as stale instead of writing
the old id back), and runs in batches of 200 rows, one write unit each (rows locked first; a lock conflict replays the
batch or answers 409 + `CONFLICT_001` / `CONFLICT_002`, REQ-RUNTIME-003 rule 6); after the last commit it dispatches
`ActorAnonymised` (tenant, count; never the id) and audits `crm.actor.anonymised` (count) per tenant. Running it again
changes nothing and returns 0; if a batch fails, the batches committed before are still announced and audited, then
the failure is rethrown. Each page is read without a lock and only its matched rows are locked by primary key (the
actor columns have no index: a locking scan would block other tenants' writes). The placeholder is reserved
(`ActorRef::ANONYMISED`): never an actor, an owner input or a visible owner. Like `CrmBatchLookupInterface` it is a
**system API**: no tenant, actor or permission
check; never exposed to end users or agent tools (MCP). There is no assignee in 0.2.x (tasks come in 0.4.0, which will
add theirs to the kinds). Audit entries already written live in the host's audit sink: the host anonymises them.

## 17. Migrations

- **No Doctrine migration classes are shipped** (house style; tables carry the host's prefix and live on the host's
  connection): the host generates them with `doctrine:migrations:diff --em=nowo_crm_kit` (or its own EM). The metadata
  listener sets prefixes and collations, so the diff contains the binary NO PAD columns.
- `docs/INSTALLATION.md` carries the reference DDL of phase A for MySQL 8.4, MariaDB 11.4 and PostgreSQL 16; a test
  creates the schema on each platform and asserts the collations and the unique keys.
- Each phase adds **new tables only** (its own mapping group); a phase never alters a table of an earlier phase in a
  minor release except adding nullable columns or indexes, and UPGRADING lists the DDL of every release.
- REQ-SEC-006 rule 9: any later collation change ships its detection queries in UPGRADING.
- **(0.2.0)** adds one table (`crm_role_assignment`) and two columns of `crm_affiliation` that are `NOT NULL` with a
  default (`is_primary_contact` false, `purpose_keys` `''`), so existing rows need no backfill; UPGRADING lists the DDL
  for MySQL / MariaDB, PostgreSQL and SQLite.

## 18. Worker mode (FrankenPHP, kernel reset false; REQ-RUNTIME-002)

- No tenant, actor, permission or record data in service properties or statics; every port is asked per call. No
  caches in phase A.
- Private EM `nowo_crm_kit`: cleared after every unit and at `kernel.finish_request` / `kernel.terminate` /
  `console.terminate`; a closed EM is reset through the registry; transactions open and close inside the call.
- After-commit event buffers are local to the call. Streams of CSV exports write row by row from a keyset reader
  (flat memory) and close in `finally`.
- Tests: one kept-alive kernel (`disableReboot()`, no services resetter between requests) serves **(0.1.0)** 120
  requests alternating two tenants and two actors (the companies list on every request, a contact created through the
  form every fifth; `HostKindsAndWorkerTest::testOneKernelServesTwoTenantsWithoutCrossedData`), asserting no crossed
  data, no managed entity left after a request, and flat memory (< 8 MiB growth after a warm-up of 20 requests). Igor and the `phpstan-frankenphp` worker
  rules pass.

## 19. Integration points

### 19.1 UiKitBundle (^2.55) and FormKitBundle (^2.23)

Pages extend `@NowoUiKit/page/{list,show,form,settings}.html.twig` with UiKit view models; tokens and slots only (brand
by tokens, no override needed); row actions in kebabs with `context_menu: true`; `responsive: cards` lists on phones;
`focus_mode_toggle` in the toolbar (renders nothing while the host leaves focus mode off). Forms are FormKit forms under
the `crm_kit` profile (prepended when FormKit is registered), never raw `<form>` (REQ-TWIG-005).

### 19.2 SearchKitBundle (^1.7, optional)

§12. Beacon adds the provider by installing SearchKit; nothing to wire.

### 19.3 StorageKitBundle (phase B, optional)

`CrmAttachmentStoreInterface` (store, stream, remove) with a StorageKit bridge once StorageKit stores non-image files
(its roadmap); the bridge implements `FileContextInterface` (`type: crm_note`, readable = notes the viewer may view in
the tenant, ids compared exactly) and uses the tenant as the StorageKit scope. Until then, attachments are off.

### 19.4 VerifactuBundle and SepaPaymentBundle (phase D)

- The CRM issues the invoice in its own unit (number from `crm_sequence` by atomic `UPDATE`, totals frozen), then an
  outbox row; a Messenger handler maps the invoice to `Integration\InvoiceDraft` → `InvoiceToBillingRecordMapper` →
  `BillingRecordProcessor::process($record, submitToAeat)` **after commit** (never under the CRM's locks; the
  VeriFactu chain lock is per issuer NIF) and stores the hash / AEAT reference in a second unit. Issuer NIF per tenant
  through a port (`CrmIssuerProviderInterface`). Cancellation = an `Anulacion` record; rectifying invoices = R-type
  records (verified against Verifactu's XML generator before phase D).
- SEPA: the CRM implements `MandateRepositoryInterface` on `crm_mandate` (tenant-scoped, exact ids), creates mandates
  through `MandateService`, validates IBAN / BIC with the bundle's validators, builds pain.008 batches with
  `DirectDebitGenerator` from frozen batch rows (amounts as decimal strings converted only at the edge) and tracks
  payment state itself (SepaPaymentBundle has none); bank returns are recorded manually or by a host import.

### 19.5 MailKitBundle (phase E)

Campaign fan-out = one Messenger message per recipient (idempotent on `crm_campaign_recipient` unique key), sent with
`MailerInterface` + `TemplatedEmail`, `MailHeaders::template()`, `MailHeaders::trackingRecipient()` only with tracking
consent (else `MailHeaders::noTracking()`); the CRM's `EmailTrackingPolicyInterface` implementation decides tracking from
consent; `List-Unsubscribe` / `List-Unsubscribe-Post` headers and a one-click unsubscribe route (signed token, writes
the suppression row and a consent withdrawal); unsubscribe links marked `data-mail-kit-track="off"`. Delivery events
(`MailDeliveredEvent`, `MailDeliveryFailedEvent`, `MailOpenedEvent`) update the recipient row.

### 19.6 NotifyKitBundle (phase B)

Reminders: `crm_reminder` rows with `due_at`; a command claims due rows (conditional UPDATE) and calls
`NotifierInterface::notify(NotificationRequest(type: 'crm.reminder', recipients: [RecipientRef('user', owner)],
context: ContextRef('organization', tenant), dedupeKey: 'crm-reminder:<id>'))` after commit; the CRM implements
NotifyKit's `RecipientAccessCheckerInterface` contribution for `crm.view` (or Beacon's own checker does).

### 19.7 brand-contracts and Dompdf (phase C)

Quote and invoice PDFs: closed Dompdf rendering (no remote, no JS, no PHP), brand from `BrandProviderInterface` (name,
logo data URI, tokens), per call.

## 20. Standard vs DDD host (REQ-ARCH-001)

| Topic | Standard Symfony host | DDD / hexagonal host |
|---|---|---|
| Entities | Bundle-owned, XML mapping; nothing to extend | Same; host code holds `RecordId` strings |
| Users / tenants | `SecurityActorDirectory` + `SingleTenantResolver` + role map, or a few-line adapter | Host adapters over its read models |
| Flush | Private EM + explicit transaction; never the host's EM | Same (dedicated connection allowed) |
| Events | Symfony dispatcher, after commit, ids only | Same; the host maps them to its bus |
| UI | UiKit pages; Twig overrides by bundle path | Disable `ui` and call the application services |

## 21. Test plan (100 % PHP coverage, REQ-TEST-003)

- **Unit:** VOs (every refusal: edge whitespace, NBSP, ZWSP, BOM, bidi, control, invalid UTF-8, 129 bytes; `/D`
  anchors), enums, custom-field validation per type, CSV / vCard parsers (BOM, `;`, quotes, folded vCard lines,
  limits, formula cells kept as text), CSV writer (injection neutralised), config tree, DI.
- **Database suite** (SQLite by default; CI matrix MySQL 8.4, MariaDB 11.4, PostgreSQL 16, DBAL 3 and 4):
  create / edit / trash / restore / purge, affiliations, tags, fields, notes, links, consents, import, merge, erasure,
  export, retention, counters, collation (`bob` vs `Bob`, `bob `, NBSP / ZWSP look-alikes never match on MySQL /
  MariaDB), schema per platform.
- **Races (two connections, a second kernel on its own connection inside the first one's unit):** merge A→B vs B→A
  (one merged, one 409, children moved once); merge vs edit (one 409); two imports confirmed at once (one runs, one
  409, no duplicate contact); two imports with the same e-mail (one contact, the other row `duplicate`); concurrent
  edits with one version (one stored, one 409 `crm_record_stale`); creates at the plan limit (exactly `limit` stored);
  a real lock wait inside a unit ⇒ 409 + `CONFLICT_001` + `Retry-After` + `X-Conflict-Code` and the connection usable
  afterwards; a replay after an injected deadlock stores one row.
- **Permissions:** every route × profile (no tenant, feature off, no actor, view, edit, manage, other tenant's id) ⇒
  404 / 403 / 200 / 303; the default checker denies everything.
- **Worker mode:** §18.
- **Host kinds:** standard, DDD, single EM run the service suite.
- **SearchKit bridge (0.1.0):** tested with SearchKit stubs (`tests/Stubs/SearchKit`), since SearchKit needs PHP >= 8.3.
- **UI / e2e (demo):** Playwright on `demo/symfony8`: list, create, 360°, note, import preview → confirm, merge, phone
  width without horizontal scroll, axe clean, WebP screenshots (REQ-DEMO-013).

## 22. Questions for the owner (Q-O)

| # | Question | Recommendation (assumed for the build) | If not |
|---|---|---|---|
| Q-O1 | Bundle name `CrmKitBundle` / `nowo-tech/crm-kit-bundle`, public MIT repository `nowo-tech/CrmKitBundle`, 0.x per phase and 1.0.0 after phase G? | **Yes** | Another name, or 1.0.0 after phase A |
| Q-O2 | Contacts relate to companies **many-to-many** through affiliations (role, one primary), not one company per contact? | **Yes** (people change companies; a podiatry clinic's patients have none) | One optional company per contact |
| Q-O3 | A contact's e-mail is **unique per tenant** (case-insensitive key); duplicates are merged, not stored twice? | **Yes** (imports and campaigns need one person per address) | Duplicates allowed, merge only by hint |
| Q-O4 | Deleting is **trash first** (restore for `trash_days`, default 30, then purged), purge from the trash needs `manage`? | **Yes** | Immediate hard delete |
| Q-O5 | Bulk CSV export of lists needs `manage` (it exports personal data in bulk); one contact's vCard needs `view`? | **Yes** | `view` for everything |
| Q-O6 | Phase A ships the UI and the PHP services; the public JSON API `/api/crm/v1` arrives later, off by default, with `If-Match` versions and `Idempotency-Key`? | **Yes; decided for 0.3.0 (owner, 2026-11-19, Q-O16)** | API in 0.1.0 |
| Q-O7 | Plan limits are enforced by atomic counters per tenant and resource (companies, contacts; deals in B); the default is unlimited? | **Yes** | Count queries (racy) |
| Q-O8 | GDPR erasure keeps the consent history **without evidence text** as proof (art. 7.1), deletes notes / timeline / links of the contact; an inactive-contact review is a phase G report, not an automatic deletion? | **Yes** | Delete consents too; automatic deletion of inactive contacts |
| Q-O9 | Attachments wait until StorageKit stores non-image files (its roadmap), through a port with a StorageKit bridge in phase B? | **Yes** | A file store of the CRM's own now |
| Q-O10 | Merge keeps a **snapshot** of the merged record for `merge_keep_days` (default 90) for audit; no automatic "unmerge"? | **Yes** | Unmerge in 1.0 |
| Q-O11 | Custom field types for 1.0: text, textarea, number, date, boolean, choice, url, email; no multi-choice or relation fields? | **Yes** (multi-choice in a 1.x minor if asked) | Add multi-choice / relation now |
| Q-O12 | Runtime settings: phase A has none; AEAT / SEPA credentials (D) and the tracking secret (E) become `RuntimeSettingDefinition`s with `fail_closed`? | **Yes** | Env only |
| Q-O13 | Releases: 0.1.0 = phase A after `bundle-req-audit` of the scaffold and a SEC-004 audit (permissions, GDPR erasure, merge = data loss paths are audited immediately, batch rule 2026-11-07)? | **Yes** | One audit at 1.0.0 |
| Q-O14 | Invoices (D) keep their own numbering per series in the CRM (VerifactuBundle has none) and the CRM models payment status (SepaPaymentBundle has none)? | **Yes** | Numbering in SerialNumberBundle |
| Q-O15 | Campaigns (E) are built in the CRM (segments, suppression, unsubscribe, consent) on top of MailKit's sending and tracking, not in MailKit? | **Yes** (MailKit stays a transport / log / tracking layer) | Add campaigns to MailKit |
| Q-O16 **(0.2.0)** | The public JSON API (Q-O6) was planned for 0.2.0; the phase plan of 2026-11-19 does not name it. Ship it with phase B (0.3.0)? | **Decided (owner, 2026-11-19): 0.3.0** | — |
| Q-O17 **(0.2.0)** | A role or purpose removed from the configuration: its rows are kept and **hidden** everywhere except the GDPR export, and come back when the key is configured again (edits of the configured keys never drop them)? | **Decided (owner, 2026-11-19): hidden but kept** | — |
| Q-O18 **(0.2.0)** | Roles apply to every contact, not only to contacts without a company? | **Decided (owner, 2026-11-19): any contact** | — |

## 23. Questions for Beacon (Q-B) — confirmed by Beacon 2026-11-19 (B1–B15)

| # | Question | Recommendation (assumed for the build) |
|---|---|---|
| Q-B1 | `TenantRef` = the organization UUID; `ActorRef` = the user UUID? | **Yes** (as WorkingTime / NotifyKit) |
| Q-B2 | Permission mapping `crm.view`, `crm.edit`, `crm.manage`, `crm.amounts.view`, `crm.campaigns.send` 1:1 to `CrmPermission`; organization admins get all by default; plain members none? | **Yes** |
| Q-B3 | Feature gate: Beacon's `CrmFeatureGateInterface` = organization feature (spec 244 style) **and** the plan includes CRM; off ⇒ 404 everywhere, menus hidden by Beacon? | **Yes** |
| Q-B4 | Plan limits: Beacon implements `CrmPlanLimitsInterface` from its plan catalogue (contacts, companies; deals in B); null = unlimited? | **Yes** |
| Q-B5 | 360° panels Beacon provides in T010: projects, tasks, support tickets, e-mail threads (spec 229), tracked / billable hours, invoices (Billing). Each panel checks Beacon's own permissions; the bundle passes tenant, subject, actor and linked host refs. Agreed? | **Yes** |
| Q-B6 | Host links: Beacon links companies to its `project`, `client` (spec 229 address book) and `ticket_requester` objects and implements `CrmHostObjectResolverInterface` for them? | **Yes** |
| Q-B7 | Spec 229's organization address book: import its correspondents into CRM contacts once (a Beacon command calling `RecordServiceInterface`, 0.1.0), then the CRM is the address book? | **Yes**, a one-off import plus links |
| Q-B8 | Owners: `CrmActorDirectoryInterface::owners()` = active organization members with `crm.edit`? | **Yes** |
| Q-B9 | Mount the UI at `/dashboard/crm` (route names `nowo_crm_kit_*`), organization context from Beacon's resolver; Beacon adds menu entries and breadcrumbs? | **Yes** |
| Q-B10 | Search: install SearchKit's provider as is (group `crm_companies`, `crm_contacts`, priority 60)? | **Yes** (0.1.0: priorities 60 and 59, and `ui.enabled` is needed) |
| Q-B11 | Audit: Beacon implements `CrmAuditSinkInterface` with `UserActionRecorder` (keys `crm.*`)? | **Yes** |
| Q-B12 | Private EM `nowo_crm_kit` on Beacon's default connection (Flex recipe), table prefix `nowo_`? | **Yes** |
| Q-B13 | Consent purposes for Beacon: `marketing_email`, `newsletter`, `tracking`? | **Yes** |
| Q-B14 | Phase order after A: B (pipeline) next, then C, D, E, F, G as spec 245; Beacon adopts each phase in a minor? | **Yes** |
| Q-B15 | Locales: the house set (en, es, it, fr, pt, de, nl); Beacon's ca / eu / gl as extra locales later if asked? | **Yes** |

## 24. Answers

_Empty until the owner and Beacon answer. The build of phase A follows the recommendations above; an answer that
differs is applied in the next commit and noted here with the date._

### Status 2026-11-18

- The owner approved the bundle; the repository `nowo-tech/CrmKitBundle` exists (public).
- Phase A is built on `feat/phase-a-companies-contacts` with every recommended answer above assumed. Places where
  0.1.0 differs from this text are marked **(0.1.0)**.
- The tag constants of the two host extension points are `CrmPanelProviderInterface::PANEL_TAG` and
  `CrmHostObjectResolverInterface::HOST_OBJECT_TAG`, so one host class can implement both.
- Done after the first review (2026-11-19): the route × profile permission-matrix test, the `__purge__` single-run
  claim, the counter row locked before the recount, a `crm_counter` row lock for `fields.max_per_subject`, audit
  entries for notes, tag assignments, links, affiliations and host timeline entries, a dependency-floor CI job.
- Done after audit UD (2026-11-19): merge tombstones without personal values, erased with their survivor (recursively)
  and purged after `merge_keep_days`; child writes lock their parent (affiliations: contact, then company); the shared
  entity manager is never closed by a refused write; writes inside an open transaction are refused;
  `NowoTransactionDoctrineBundle` required; `crm_write_conflict` is a domain 409; counter and claim rows locked
  before any insert; the purge claim refreshed per batch and released only while it is the run's; the GDPR export a
  POST with the action token; host links confirmed by their resolver; upload size checked before reading; the owner
  check skipped when unchanged; field archive with the shown version; "Continue" attributed to the clicking person.
- Open items for later releases: the Messenger import handler, the link picker
  (`CrmHostObjectResolverInterface::search()`), the public JSON API (Q-O6: 0.3.0, decided 2026-11-19).

### Status 2026-11-19 (0.2.0)

- Owner request (2026-11-19): customers and suppliers in one entity with roles, contacts inside each, grouping
  contacts for informative circulars; then funnels with per-stage statistics, quotes and invoices from the CRM, a
  customer portal, tasks and agenda. 0.2.0 builds roles, the company's primary contact and affiliation purposes (§6.8)
  and the UD-2 follow-ups (UD2-L-1: an import chunk locks its parents in id order before any row; UD2-L-2: the
  replay note in §15 and INSTALLATION; UD2-L-3: a second purge answers "Another purge is running" at once). The
  later releases follow §25.
- Owner decisions (2026-11-19, after audit UL): MySQL 8.0 / MariaDB 10.6 minimums and the CSV `roles` column
  accepted; roles and purposes removed from the configuration are hidden but kept (Q-O17); the JSON API ships in
  0.3.0 (Q-O16); roles apply to any contact (Q-O18).
- Owner instruction (2026-11-19): "crmkit debería usar el mismo tipo de guard y access que el resto de bundles". 0.2.0
  adopts the house access shape (REQ-UI-002, §13): the `security` node, the area checker
  `CrmKitAccessCheckerInterface` (+ tenant-aware variant, configurable and allow-all defaults), the record checker with
  the `nowo_crm_kit.record_access_check` event, `CrmKitGuard`, a route listener, and the compile-time SecurityBundle
  check; the 0.1.0 `access.roles` is removed and the root `access_checker` deprecated (removed in 0.3.0).
- Audit UP (2026-11-19) and owner decisions: login / 401 for anonymous visitors of the pages accepted; `touch()`
  keeps `updated_at` exact (no forced version bump); `canViewAmounts()` / `canSendCampaigns()` with
  `security.amounts_roles` / `campaigns_roles` now; no advisory for the 0.1.0 import update path (CHANGELOG Security
  line). Fixed: resource scoping applied in SQL to every read that returns several records (port
  `CrmRecordVisibilityInterface`, event `nowo_crm_kit.record_visibility`); imports need `edit` on a matched contact and
  `view` on a named company; merges need `delete` on the merged record; removing a primary contact's affiliation needs
  `edit` on the company; empty role lists mean any authenticated user (settings / export / erase need one);
  `allow_unauthenticated` refused in prod and turns scoping off.
- Audit UL fixes (2026-11-19): an affiliation edit keeps the stored purposes that are no longer configured; an
  unticked "primary contact" keeps the company's primary contact (clearing is the company page's own action, and a
  linked company has a pre-filled form); `purpose_keys` is 1100 characters; an import row that changes a company's
  primary contact needs `edit` on that company (else `invalid`, `error.forbidden`); every write of a record moves its
  version on every write (owner decision 2026-11-19: `updated_at` of companies, contacts, notes and settings kept to
  the microsecond, `DATETIME(6)` / `TIMESTAMP(6)`; `touch()` keeps the exact instant and moves it by one
  microsecond only on an exact tie with the stored one); imports announce and audit role changes;
  the GDPR export lists affiliations to trashed companies with their status; `ListQuery` refuses invalid role and
  purpose keys; a contact merge moves the primary-company flag of a shared company.

## Q-B answers from Beacon (2026-11-19): B1–B15 confirmed as implemented
- B9: Beacon resolves the organisation from the header context (D-20261118-01). It answers 404 in Personal or when the feature is off.
- B5: Beacon checks each 360° panel with its own permission. Deny by default, with a neutral placeholder when the panel is not visible.
- B7: a one-time import of the spec 229 address book is welcome.
- B11: audit through UserActionRecorder, recording ids (`recordIdsAndFlush`).
- The integration findings are accepted: invoice numbering, payment state, unsubscribe and reminders live inside the CRM.
- Fit of the ports (bundle side, 2026-11-19): `CrmAuditSinkInterface::record(AuditEntry)` receives ids, codes and
  counts only and is called after the bundle's commit, outside its unit of work, so a recorder that writes and flushes
  its own entity manager (`recordIdsAndFlush`) fits without touching the bundle's transaction. A panel provider that
  denies its panel returns `null` (nothing shown) or a `Panel` with no items and an `emptyText` (the neutral
  placeholder); both render.
- 0.2.1 for Beacon: an actor resolver for stable user ids (§8.1; Beacon registers its own, its access checker returns
  `idOf()` itself); `CrmActorPersonalDataInterface` for account deletion (§16.5), called only from a backend job, never
  from a page or an MCP tool; `CrmBatchLookupInterface` and `RecordsPurged` for derived rows (§7, §14).

## 25. Phase plan (owner, 2026-11-19)

The owner's plan of 2026-11-19 replaces the earlier order of §1. Each release gets its own contract revision (tables,
services, concurrency, permissions) before it is built; every release keeps the house bars (REQ-ARCH-001,
REQ-RUNTIME-002 / 003 with two-connection race tests, REQ-SEC-006, deny by default, 7 locales, 100 % coverage).

| Release | Phase | Content |
|---|---|---|
| **0.2.0** (this release) | A2 | Party roles; the company's primary contact and affiliation purposes; role and purpose filters, import and export columns; the UD-2 follow-ups (§6.8, §15) |
| **0.2.1** | A2 patch | Stable actor ids (`CrmActorResolverInterface`, §8.1), a host person's export and anonymisation across tenants (§16.5), the unattended import resume that waits for a person (§15.4). Additive, for host backend jobs: `CrmBatchLookupInterface` batch lookups including `existing()` for reconciliation (§7; system API without access checks, one query per call), the `RecordsPurged` event per committed retention-purge batch (§14, §16.4) and the `CrmKitEvents` constants of the domain events. No schema change |
| **0.3.0** | B, funnels | Funnels with per-stage statistics and next actions (below) |
| **0.4.0** | B2, tasks and agenda | Tasks, automations, meetings and calls, "my agenda" (below) |
| **0.5.0** | C, quotes | Below |
| **0.6.0** | D, invoices | Below |
| **0.7.0** | F, customer portal | Below |
| **0.8.0** | S, scoring | Below |
| **0.9.0** | E1, lists and segments | Below |
| **0.10.0** | E2, informative circulars | Below |
| later → **1.0.0** | G, reports | Deal scoring, forecasts, velocity and cohorts, open / click tracking, two-way sync with external calendars |

### 25.1 0.3.0: funnels with per-stage statistics, and next actions

- Tenant users with the permission define **funnels**: a name, ordered stages, won / lost terminal stages. Each stage
  has a default probability (a deal can override it) and an optional age limit that flags rotting items.
- Two kinds: **deal funnels** (a deal links to a company and / or a standalone contact, with an amount and next
  actions) and **party lifecycle funnels** (contacts or companies move through stages, e.g. lead → prospect →
  customer → recurring → former; the lifecycle of the customer role, §6.8).
- **Stage history** per item: from → to, who, when, time in stage.
- **Statistics per funnel:** count per stage now and at a past date; sum and weighted value (amount × probability)
  per stage for deal funnels; conversion between consecutive stages and from the first stage to won; average time in
  stage; stale items; won / lost in a period with the lost reasons. Filters: period, owner, role, tag (segments from
  0.9.0). Output: a funnel chart and a table, and a CSV export with formula cells neutralised. Gated by a permission,
  deny by default.
- **Next actions** go through a new port `CrmTaskPort`, with an optional TaskBoard adapter: the task lives on a
  configurable board and column with a typed link back to the CRM record; the record lists its open and done tasks;
  an overdue next action marks the deal stale in the statistics. Without TaskBoard, a minimal built-in next-action
  list is used.

### 25.2 0.4.0: tasks and agenda

- A task from any CRM record (company, contact, deal; quotes and invoices as they arrive).
- Opt-in automations: a stage change creates a task from a TaskBoard template.
- Meetings and calls with date, time, duration and attendees (internal users and CRM contacts), linked to the record,
  shown in TaskBoard's calendar through a new TaskBoard timed event-source slot with reschedule (planned for
  TaskBoard 2.24; TaskBoard has only day annotations today).
- "My agenda" across CRM and tasks; reminders through NotifyKit; an ICS invite to contacts; a meeting marked done
  becomes a timeline activity.
- Linking never grants access in either direction: the other side shows a neutral placeholder when the viewer has no
  access. Tenant isolation on every link.

### 25.3 0.5.0: phase C, quotes

- Product / service catalogue; quotes from a deal or directly on a company or contact; versions, PDF, a signed and
  expiring accept / reject link, an e-sign hook.
- An accepted quote moves the deal to won; a rejected quote records a lost reason; both feed the funnel statistics.
- Status, amount and version shown on the deal and on the 360° page.

### 25.4 0.6.0: phase D, invoices

- From an accepted quote, from host billable lines, or by hand; atomic numbering per series; corrective invoices; an
  issued invoice is never edited or deleted.
- VeriFactu through VerifactuBundle after the commit; SEPA mandates and batches through SepaPaymentBundle.
- Payment status, reminders through NotifyKit; opt-in automation: an overdue invoice creates a collection task.
- Invoiced and paid totals per customer on the 360° page and in the statistics.
- Separate permissions for create, issue and void, deny by default.
- GDPR erasure anonymises the party but keeps issued invoices for the legal retention period.

### 25.5 0.7.0: phase F, customer portal

- The portal user is a contact, invited or signing in by magic link / passwordless through AuthKit.
- A contact sees only their own party's documents; company invoices only when the affiliation purpose allows it
  (e.g. `billing`, §6.8).
- No enumerable ids, signed and expiring downloads, rate limits, tenant isolation; every access and download audited.
- Invoices (list, status, PDF, corrective invoices); quotes (view, accept / reject); pending amount, a pay-link host
  hook and the SEPA mandate status; contact data (view, request a change); consents and circular subscriptions per
  purpose; self-service GDPR export.
- Tenant brand through brand-contracts tokens, 7 locales, accessible and mobile-first.

### 25.6 0.8.0: explainable scoring

- Per-tenant rules that add or remove points: has an e-mail, a role, activity within / without N days, a field equals
  a value, a funnel stage, meetings, quotes and invoices. Companies have their own rules or an aggregate.
- The record shows the breakdown of points; bands hot / warm / cold with configurable thresholds.
- Incremental on events plus a periodic recompute through Messenger, worker-safe; writes never inside a host
  transaction. Rule changes are audited and start a background recompute. AI scoring is a non-goal.

### 25.7 0.9.0: lists and segments

Static lists and dynamic segments; criteria: role, tag, affiliation purpose, has an e-mail, consent purpose, score
band, funnel stage. Bulk add from list views and from the record. Segments also filter the funnel statistics.

### 25.8 0.10.0: lean informative circulars

- One e-mail per recipient through MailKit's queue, with a rate limit; a send log per recipient.
- Per-purpose unsubscribe; a new consent purpose `service_notice` (legitimate interest with opt-out); commercial
  circulars need `marketing_email` / `newsletter` consent.
- Bounces mark the address; the open signal goes to scoring; payment reminders can use this channel too.

## 26. Addendum: HubSpot import source (design, 2026-11-23)

Design only, not built: [design/HUBSPOT_IMPORT.md](design/HUBSPOT_IMPORT.md) for symfony-beacon spec 253 and owner
decision D-20261123-04. It becomes this contract's revision for each slice before the slice is built. In short:

- **Slices.** H1 (proposed 0.9.0): private app token connection per tenant, companies, contacts, contact ↔ company
  associations, owners → members by e-mail, lists → tags. H2 (0.10.0): deals, pipelines → funnels, stages → stages
  (needs `pipeline.enabled`, released in 0.3.0). H3 (0.11.0): OAuth with state + PKCE. Numbers follow 0.8.0 (PR #19).
- **Same queue.** `ImportFormat::HubSpot`; `crm_import` / `crm_import_row` with nullable additions only
  (`connection_id`, `cursor`, `resume_after`, `error_code`; `crm_import_row.subject_type`); HubSpot rows store the
  object kind and id only, never property values or response bodies.
- **New tables** (mapping group `doctrine-hubspot`): `crm_hubspot_connection` (unique per tenant, sealed token, last 4,
  scopes, version) and `crm_external_ref` (unique `(tenant_id, source, account_id, object_type, external_id)`); new
  exact-key columns `source`, `object_type`, `target_type`, `token_key_id` join `CrmMetadataListener::EXACT_COLUMNS`.
- **New ports.** `CrmSecretBoxInterface` (AEAD seal / open bound to a `SecretContext`; built-in sodium default,
  DoctrineEncrypt bridge) and the optional `CrmActorEmailLookupInterface` (owners by e-mail, keys re-checked with
  `===`); H3 adds `CrmHubSpotOAuthAppInterface` (runtime settings). The permission is the existing
  `CrmPermission::Import` (`canImport`); every HubSpot route answers 404 on a denial.
- **Client.** Constant base URL `https://api.hubapi.com`, the kit's own HttpClient, no redirects, size and time caps,
  `next.link` ignored, read-only scopes only, optional OutboundUrlGuard DNS pin; token and bodies never logged.
- **Run.** Fixed stages (owners → companies → contacts → deals → lists); one HubSpot page settled per write unit with
  the cursor advanced in the same unit; 429 backoff in workers, pause with `resume_after` in web requests; resume
  through `nowo:crm-kit:import:resume` or the optional `RunCrmImport` Messenger handler.
- **Matching.** External ref first (always update), then the duplicate policy (contacts by e-mail, companies by a
  single matching domain); a record, its ref, its associations and its taggings commit together, so no half
  association is ever stored.
- **Open owner questions** Q-H1..Q-H13 with recommended answers are in section 15 of the design.

**Revision for slice H1 (built for 0.10.0, 2026-11-23).** The owner accepted Q-H1..Q-H13 as recommended; numbers moved
up by one (H1 0.10.0, H2 0.11.0, H3 0.12.0). Changes against the design, from the build and from Beacon's request of
2026-11-23:

- **Credential**: a HubSpot *service key* (new accounts can no longer create private apps) or a legacy private app
  token, no prefix or shape rule. Account via `GET /account-info/v3/details`; scopes from the token-info endpoint when
  HubSpot knows the credential, else by probing each required read endpoint with `limit=1` (403 = missing). Q-H2
  (refuse write scopes) applies where HubSpot lists the scopes; a service key's write scopes cannot be seen (USAGE).
- H1 scopes: `crm.objects.{companies,contacts,owners}.read`, `crm.schemas.{companies,contacts}.read`, plus
  `crm.lists.read` to import lists; no sensitive variants (sensitive properties are never offered).
- `crm_import.cursor` is `run_cursor` (`CURSOR` is reserved in MySQL). The token-check counter lives in two
  `crm_counter` rows; `connect_rate_limit.interval` is in seconds. 0.10.1: the start and mapping pages' HubSpot reads
  are counted in two more (`browse_rate_limit`, audit ZAM-9).
- Not in H1: the account-details call for currency (H2), per-input association paging (a contact's first page of
  companies is read), English country names (only ISO alpha-2 codes are mapped), the 80-requests-per-10-seconds
  token bucket (bursts are handled by the 429 backoff).

## 27. Addendum: activities (A-1, 0.11.0)

Contract revision of Beacon spec 254 block A-1 (tasks T001 and T002), built as 0.11.0 (Beacon priority, 2026-11-26).
Full requirements: [`specs/002-activities/spec.md`](../specs/002-activities/spec.md); configuration:
[CONFIGURATION.md](CONFIGURATION.md#activities-0110); usage: [USAGE.md](USAGE.md#activities-0110).

- **Switch.** `activities.enabled` (default false): three tables (`crm_engagement`, `crm_engagement_subject`,
  `crm_engagement_attendee`), services and routes only while on.
- **Services.** `EngagementServiceInterface` (`log`, `edit`, `delete`), `ActivityFeedInterface` (`feed`,
  `engagement`), `PinnedNotesInterface` (`pin`), `MentionDirectoryInterface` (`candidates`, `names`).
- **Ports (host, safe defaults).** `CrmHostActivitySourceInterface` (none), `CrmNotifierInterface` (NotifyKit bridge,
  else none), `CrmRecordAudienceInterface` (the `resource_scoping` rule). Bundle-owned VOs only (REQ-ARCH-001 rule 3).
- **Access.** `view` reads (feed, `.ics`), `edit` writes on the record (log, pin, notes), `manage` for someone else's
  activity; an activity is shown only while its primary record is viewable; mention candidates and recipients are
  members of the same tenant the audience lets view the record.
- **Concurrency (REQ-RUNTIME-003).** Each write locks the primary record `FOR UPDATE` in a transaction-doctrine write
  unit; `Engagement` and `Note` are versioned (409); the pin limit is enforced under the record lock; events and
  notifications after the commit; the runner's lost-savepoint pattern. Two-connection race tests: pins, edits of one
  activity, logging against an erasure.
- **GDPR (§16).** Export (`activities`, `meetings_attended`), erasure, purge, retention (`timeline_years`), merges
  and actor anonymisation (`engagement.author`, `engagement_attendee.actor`) cover the activities.
- **Deviations from the roadmap's plan.** No MailKit invite part yet (an authorised `.ics` download instead;
  `CrmMailSenderInterface` with MailKit 2.3); no NotifyKit reminders (Q-254-2, later slice); `ActivityDirection`
  instead of `CallDirection`; HubSpot engagements in HubSpot part 4.

## 28. Addendum: e-mail templates (A-2, 0.13.0)

Contract revision of Beacon spec 254 block A-2 (task T003, FR-015 / FR-016), built as 0.13.0 on MailKit 2.7's stored
templates (MailKit contract C1–C12; owner D-20261127-02). Full requirements:
[`specs/003-templates/spec.md`](../specs/003-templates/spec.md); configuration:
[CONFIGURATION.md](CONFIGURATION.md#e-mail-templates-0130); usage:
[USAGE.md](USAGE.md#e-mail-templates-and-write-e-mail-0130).

- **Switch.** `templates.enabled` (default false; needs `activities.enabled` and the optional
  `nowo-tech/mail-kit-bundle` >= 2.7 with `nowo_mail_kit.templates.enabled`): one table (`crm_template_purpose`),
  services, routes and the providers only while on.
- **Services.** `EmailTemplatesInterface` (`list`, `get`, `create`, `update`, `delete`, `compose`, `placeholders`),
  `MailComposerInterface` (`send`). Values `EmailTemplateView`, `ComposedText`, `MailOutcome`, `EmailTemplateInput`,
  `MailDraft`, `TemplateRef`, `CrmOutgoingMail`, `CrmSentMailRef`, enum `EmailTemplateKind`.
- **Ports.** `CrmMailSenderInterface` (host; default the MailKit bridge on Symfony Mailer). The CRM implements
  MailKit's `TemplatePermissionInterface` (`nowo_crm_kit.template_permission`) and four
  `PlaceholderProviderInterface` (`contact`, `company`, `deal`, `me`). MailKit types stay in `Bridge/MailKit`
  (REQ-ARCH-001 rule 3).
- **Access.** `edit` (tenant-wide) to use templates, `manage` for the organisation's; *Write e-mail* needs `edit` on
  the contact; placeholders resolve only for the exact CRM tenant and person and only what the record view shows.
- **Consent (§16).** A template is classified (a `gdpr.purposes` key or a service message); a commercial one is sent
  only while the contact's latest decision for its purpose is a grant; unclassified templates are refused.
- **Concurrency (REQ-RUNTIME-003).** Checks in one unit under the contact's lock, a one-time send key, the send outside
  any transaction, the activity in its own unit (CONCURRENCY.md "Write e-mail").
- **Events.** `CrmMailSent` (after the send). Audit `crm.template.created` / `saved` / `deleted`, `crm.mail.claimed`.
- **Deviations.** No `MergeField` value object (MailKit owns the grammar); contacts only, plain text, one recipient
  (spec 248's composer in Beacon covers the rest); the CRM's form edits the text body only.

