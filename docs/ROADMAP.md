# Roadmap

The bundle is built phase by phase from [CONTRACT.md](CONTRACT.md) (section 1). Each phase is opt-in by
configuration (`<phase>.enabled`), shippable alone, and when off maps no tables, registers no services or routes and
renders nothing. Each phase is one pull request with tests, docs and a green CI, released as a 0.x minor after its
audit; **1.0.0** follows phase G, `bundle-req-audit` at 100 % and the REQ-SEC-004 audits. Every phase gets its own
contract revision (tables, services, concurrency) before it is built.

## Table of contents

- [Phases](#phases)
- [HubSpot parity (Beacon spec 254)](#hubspot-parity-beacon-spec-254)
  - [Fixed points](#fixed-points)
  - [Release plan](#release-plan)
  - [Reuse matrix](#reuse-matrix)
  - [Old phases and spec 254](#old-phases-and-spec-254)
  - [Block A — activities and templates (A-1 0.11.0, A-2 0.15.0)](#block-a--activities-and-templates-a-1-0110-a-2-0150)
  - [Block B — mailbox sync and tracking (CrmKit 0.16.0)](#block-b--mailbox-sync-and-tracking-crmkit-0160)
  - [Block C — products, line items and quotes (C-1 0.17.0, C-2 0.18.0)](#block-c--products-line-items-and-quotes-c-1-0170-c-2-0180)
  - [Block D — tickets, SLA and support inbox (D-1 0.19.0, D-2 0.20.0, D-3 later)](#block-d--tickets-sla-and-support-inbox-d-1-0190-d-2-0200-d-3-later)
  - [Block E — segments and forms (E-1 0.21.0, E-2 0.22.0)](#block-e--segments-and-forms-e-1-0210-e-2-0220)
  - [Block F — automation, sequences and lead scoring (F-1 0.23.0, F-2 0.24.0, F-3 0.25.0)](#block-f--automation-sequences-and-lead-scoring-f-1-0230-f-2-0240-f-3-0250)
  - [Block G — forecast, goals and teams (CrmKit 0.26.0)](#block-g--forecast-goals-and-teams-crmkit-0260)
  - [Block H — knowledge base (WikiBundle `published_spaces`; CrmKit bridge in 0.20.0)](#block-h--knowledge-base-wikibundle-published_spaces-crmkit-bridge-in-0200)
  - [Block I — meeting scheduling links (CrmKit 0.27.0)](#block-i--meeting-scheduling-links-crmkit-0270)
  - [HubSpot scopes (verification)](#hubspot-scopes-verification)
  - [Owner questions (Q-254)](#owner-questions-q-254)
  - [Sequencing](#sequencing)
- [Not planned](#not-planned)
- [Phase A follow-ups](#phase-a-follow-ups)
- [Road to 1.0.0](#road-to-100)

## Phases

The order below is the owner's phase plan of 2026-11-19 ([CONTRACT.md](CONTRACT.md) section 25); it replaces the
earlier order B → C → D → E → F → G.

| Phase | Version | Content | Switch | Status |
| --- | --- | --- | --- | --- |
| **A** companies and contacts | 0.1.0 | Companies; contacts with affiliations (role, one primary company); owners; tags; custom fields; notes; activity timeline; host links and host panels on a 360° page; CSV and vCard import with preview; duplicate hints and merging; CSV / vCard export; GDPR (consent per purpose, export, erasure, retention); trash with restore; plan-limit counters; SearchKit providers; audit events | always on | Released (v0.1.0) |
| **A2** roles and purposes | 0.2.0 | Party roles (many per company or contact, configurable catalogue: customer, supplier, partner; distributor opt-in); the company's primary contact; affiliation purposes (billing, orders, technical, general); role / purpose filters; import and export columns; UD-2 concurrency follow-ups | always on | Released (v0.2.0, patch v0.2.1) |
| **B** funnels | 0.3.0 | Deal funnels and party lifecycle funnels (lead → prospect → customer → recurring → former) with ordered stages, default probabilities, rotting age, won / lost terminal stages; stage history; per-stage statistics (count now and at a past date, sum and weighted value, conversion, time in stage, stale items, won / lost with reasons; filters period, owner, role, tag; chart, table, CSV); next actions through `CrmTaskPort` (TaskBoard adapter, built-in fallback); the public JSON API `/crm/v1` | `pipeline.enabled` | Released (v0.3.0, with the `Api\Operation` layer and the JSON API) |
| **B2** tasks and agenda | 0.4.0 | Tasks from any record; opt-in automations from TaskBoard templates; meetings and calls with attendees in TaskBoard's calendar (timed event source, TaskBoard 2.24); "my agenda"; NotifyKit reminders; ICS invites; done meetings on the timeline; links never grant access | own switch | Not started |
| **C** quotes | 0.5.0 | Catalogue; quotes from a deal or on a company / contact; versions, PDF, signed expiring accept / reject link, e-sign hook; accepted ⇒ deal won, rejected ⇒ lost reason; shown on the deal and the 360° page | `quotes.enabled` | Not started |
| **D** invoices | 0.6.0 | From accepted quotes, host billable lines or by hand; atomic numbering per series; corrective invoices (issued invoices never edited or deleted); VeriFactu and SEPA after commit; payment status and reminders; overdue ⇒ collection task (opt-in); totals per customer; create / issue / void permissions; GDPR keeps issued invoices for the legal period | `invoices.enabled` | Not started |
| **F** customer portal | 0.7.0 | Contacts sign in (invitation, magic link / passwordless through AuthKit); own party's invoices (company invoices when the affiliation purpose allows), quotes (accept / reject), pending amount, pay-link hook, SEPA mandate status, contact data, consents and subscriptions, self-service GDPR export; signed expiring downloads, rate limits, audit; tenant brand, 7 locales, mobile-first | `portal.enabled` | Not started |
| **S** scoring | 0.8.0 | Explainable rule-based contact and company scoring (e-mail, role, activity, field value, funnel stage, meetings, quotes, invoices); breakdown on the record; hot / warm / cold bands; incremental plus a worker-safe Messenger recompute; audited rule changes | own switch | Not started |
| **E1** lists and segments | 0.9.0 | Static lists and dynamic segments (role, tag, affiliation purpose, has e-mail, consent purpose, score band, funnel stage); bulk add; segments filter the funnel statistics | own switch | Not started |
| **E2** informative circulars | 0.10.0 | One e-mail per recipient through MailKit's queue with a rate limit; per-purpose unsubscribe; `service_notice` purpose; commercial circulars need marketing consent; bounces mark the address; send log; open signal to scoring; payment reminders through this channel | `campaigns.enabled` | Not started |
| **G** reports | later → 1.0.0 | Deal scoring, deeper forecasts, velocity and cohorts, open / click tracking, two-way sync with external calendars, inactive-contact review | `reports.enabled` | Not started |
| **H1** HubSpot import: connection, companies, contacts | 0.10.0 | HubSpot as a third import source next to CSV and vCard (same `crm_import` queue, mapping, duplicate policy, limits, progress): a per-tenant service key (or legacy private app token), validated (account id, read scopes; write scopes refused where HubSpot lists them) and sealed; companies, contacts, contact ↔ company associations, owners → members by e-mail, lists → tags; HubSpot ids kept as external refs so a re-run updates; paging, 429 backoff, resume; optional Messenger run. Design: [design/HUBSPOT_IMPORT.md](design/HUBSPOT_IMPORT.md) (Beacon spec 253, D-20261123-04) | `import.hubspot.enabled` | Built (PR #23), unreleased |
| **H2** HubSpot import: deals | 0.12.0 | Pipelines → deal funnels, stages → stages (created on confirmation), deals with amount, currency, close date and open / won / lost; deal ↔ company / contact | `import.hubspot.deals` (needs `pipeline.enabled`, phase B released in 0.3.0) | Designed, not started |
| **H3** HubSpot import: OAuth | 0.14.0 | "Connect to HubSpot" with state + PKCE, sealed refresh token, revoke on disconnect; the token path keeps working | `import.hubspot.oauth.enabled` | Designed, not started |

**2026-11-26 (Beacon priority):** A-1 activities (Beacon spec 254) moves first and is built as **0.11.0**; H2 follows
as 0.12.0, the Zoho import Z1 (Beacon spec 253 block Z, owner decisions Q-Z1..18 of 2026-11-24, design in the Zoho
design note) as 0.13.0 and H3 as 0.14.0. Z2 / Z3 and the later spec 254 slices take their numbers when they are
scheduled, in the order below.

Status as of 2026-11-23 (latest tag v0.8.0; 0.8.1 is being released; 0.9.0, the pipeline dialogs, is PR #22; H1 is
built as 0.10.0 in draft PR #23). Tags v0.4.0 to v0.8.1 were not the phases of the plan: they are UI and
reader releases requested by Beacon (see [CHANGELOG.md](CHANGELOG.md)): 0.4.0 / 0.4.1 / 0.4.2 import modal, 0.5.0 notes
search (`CrmReaderInterface::searchNotes`, `notesOf`), 0.6.0 / 0.6.1 UX options (`ui` layout, list tabs, create dialogs),
0.7.0 area tab icons and the host link picker, 0.8.0 / 0.8.1 rows per page, create dialogs on demand and the action
icon fix, 0.9.0 pipeline dialogs on demand. So the version numbers in the Version column for B2 to E2 are the plan of
2026-11-19 and are already taken; each phase gets its number in its own contract revision. Phases B2 to G have no code
on `main` and no open pull request; from 0.13.0 they are scheduled as slices of the Beacon spec 254 blocks (section
[HubSpot parity](#hubspot-parity-beacon-spec-254), table *Old phases and spec 254*). Configurable numbering of customers, quotes and invoices with an import key (owner
decision QN-1..10, 2026-11-20, planned as 0.4.0 at the time) is also not started.

## HubSpot parity (Beacon spec 254)

Beacon spec 254 "CRM HubSpot parity" is approved under owner decision **D-20261123-11** (2026-11-23). It plans the
gaps between Beacon's CRM and the part of HubSpot a small team uses every day, as nine blocks **A–I**, each shippable
alone, each off by default and switched on by configuration. BUNDLES builds every kit piece (this bundle and the other
bundles named below); Beacon only wires ports, permissions, menus, breadcrumbs, the manual and e2e. This section is the
kit side of the plan: versions, dependencies, ports and value objects, audits, HubSpot import follow-ups, owner
questions and sequencing. Each slice still gets its own contract revision (tables, services, concurrency, permissions,
Q-O questions) in [CONTRACT.md](CONTRACT.md) before it is built.

### Fixed points

- **Owner decisions (D-20261123-11).** Outbound marketing and sequence mail is sent only from each organisation's own
  verified domain (SPF, DKIM and DMARC checked first; otherwise the send is refused with the reason). Mailboxes:
  Outlook and Gmail together in block B, Outlook ships first if Google's review lags. Quote signature: own acceptance
  link with an immutable record; an external e-sign provider later behind a port. Support channels: one ticket address
  per organisation on Beacon's inbound domain, plus chat for signed-in customers only (no anonymous website chat).
- **Every block extends the HubSpot import** (spec 253, [design/HUBSPOT_IMPORT.md](design/HUBSPOT_IMPORT.md)) for the
  objects it creates: read-only scopes, re-run upserts by HubSpot id, associations kept, same `crm_import` queue and
  limits (FR-100). E-mail bodies only on the organisation's opt-in; tracking, sequences, workflows, scores and form
  submissions are not imported (FR-101).
- **Agreed order.** (1) H1 (0.10.0) in Beacon v1.174; (2) Beacon architecture debt in v1.175; (3) spec 254 blocks,
  one or two per Beacon release, starting with A.
- **Reuse first (owner rule, 2026-11-23).** "If a nowo bundle already does, or nearly does, a CRM capability, use it
  or extend it." No CRM-only copy: a missing piece is added to that bundle (an option, a port or a slot) and CrmKit
  consumes it. Every capability below says **reuse X as is**, **extend X with Y** or **new, because Z** (the "new"
  verdicts were checked against each bundle's code and docs on `main` / `master`, 2026-11-23); the
  [reuse matrix](#reuse-matrix) lists what changes in each bundle and in which version.
- **House bars for every slice.** REQ-ARCH-001 (rule 3: every port takes and returns this bundle's own VOs and
  scalars, one VO per concept, no host, Symfony, Doctrine or HTTP types), REQ-RUNTIME-002 (worker mode, kernel not
  reset), REQ-RUNTIME-003 (write units, 409 on stale versions, two-connection race tests), REQ-SEC-006, deny by default
  with 404, dialogs on demand (D-20261123-01), 7 locales, 100 % coverage, one REQ-SEC-004 audit per minor.

### Release plan

Version numbers as of 2026-11-26: A-1 moved first (Beacon priority) and is **0.11.0**; then H2 0.12.0, Z1 (Zoho
companies and contacts) 0.13.0, H3 0.14.0; the slices after them take the next free minor in the order of this table
when they are scheduled (numbers move up when another release lands first; the order does not change). "Kit option" is the configuration switch; off means no tables mapped, no routes, no services, no menu entries.

| Slice | CrmKit | Content | Kit option | Other bundles (new work in **bold**) | Beacon release (target) |
| --- | --- | --- | --- | --- | --- |
| **A-1** activities | 0.11.0 | Kinds `call`, `meeting`, `email`; log dialogs; timeline filter by kind and person; fan-out contact → companies / deals; pinned notes; @mentions; host activity source; meetings on TaskBoard's calendar + ICS download (MailKit invite part a follow-up); HubSpot notes / calls / meetings / tasks built as 0.13.0 (T004, `specs/004-hubspot-activities/`) | `activities.enabled` | TaskBoard (event source, as is), NotifyKit 0.3 (as is), MilkdownEditor mentions (as is), **MailKit: ICS invites** (follow-up), SearchKit | v1.176 |
| H1 / H2 / Z1 / H3 | 0.10.0 / 0.12.0 / 0.13.0 / 0.14.0 | HubSpot import (spec 253), Zoho import Z1 (spec 253 block Z) | `import.hubspot.*`, `import.zoho.*` | — | v1.174 (H1) |
| **A-2** templates | 0.15.0 | Personal and shared e-mail templates, snippets, closed merge-field resolver, consent purpose per template; *Write e-mail* insert; sent mail logged as `email` | `templates.enabled` | **MailKit: stored templates + placeholder registry** (CrmKit adds the CRM placeholders) | v1.176 or v1.177 |
| **B** mailbox sync + tracking | 0.16.0 | Auto-link by exact contact e-mail, `email` activities from linked messages, open / click signal, reply detection; HubSpot e-mails (metadata; bodies on opt-in) | `mail_link.enabled` | **MailKit B1: Graph delegated personal mailboxes** (spec 236 kit side); **MailKit B2: Gmail provider** (off until Google's review); **TimeTrack: OAuth helpers with extra scopes** (Q-254-15); MailKit tracking + ShortLink clicks (as is) | v1.177 (Outlook); Gmail by configuration later |
| **C-1** catalogue + line items | 0.17.0 | Product catalogue, line items on deals, deal amount from items (per funnel), MRR / ARR; HubSpot products and line items | `catalog.enabled` | — (new in CrmKit; CurrencyKit only for an optional reference display) | v1.178 |
| **C-2** quotes | 0.18.0 | Quotes with series numbering, versions, branded PDF, signed accept / reject link, immutable acceptance, won / lost on answer; HubSpot quotes | `quotes.enabled` | ReportKit PDF runner + brand-contracts (as is); PdfSignable later as a provider (as is) | v1.178 or v1.179 |
| **D-1** tickets + SLA | 0.19.0 | `FunnelKind::Ticket`, priority, owner, team, links, board and list, SLA policies on business hours, pause, breach events; teams port; HubSpot tickets and ticket pipelines | `tickets.enabled` | **WorkingTime: business-time calculator**; TaskBoard teams (as is); NotifyKit (as is) | v1.179 |
| **D-2** e-mail channel + articles | 0.20.0 | Ticket address per organisation, e-mail → ticket, reply threading by token, unknown sender without contact; article suggester port and Wiki bridge | `tickets.inbound.enabled` | MailKit Graph inbound + plus addresses (as is); Wiki (block H) | v1.180 |
| **D-3** chat channel | later | Signed-in customers' chat opens or continues a ticket | `tickets.chat.enabled` | **ChatKit customer-channel option**; needs phase F (portal) | after phase F (Q-254-8) |
| **E-1** segments (phase E1) | 0.21.0 | Static lists and dynamic segments with live counts, bulk actions; HubSpot lists → lists / segments | `segments.enabled` | CrmKit tags as static lists (Q-254-16); **UiKit: rule-builder component** | v1.181 |
| **E-2** web forms | 0.22.0 | Form builder, field mapping, UTM fields, consent per purpose, embed by script / iframe, allowed origins, signed token, honeypot + Altcha, rate limits, double opt-in; no HubSpot form definitions in v1 (Q-254-3) | `forms.enabled` | **FormKit: honeypot + embed mode**; AltchaType, LegalKit, MailKit (as is) | v1.181 or v1.182 |
| **F-0** sending domains | — | Per-organisation sending domain, SPF / DKIM / DMARC verification before campaigns and daily for sequences | — | **MailKit `sending_domains`** (Q-254-6) | with F-2 |
| **F-1** scoring (phase S) | 0.23.0 | Explainable rule scoring, hot / warm / cold bands, breakdown, worker-safe recompute | `scoring.enabled` | — | v1.182 |
| **F-2** circulars (phase E2) | 0.24.0 | Informative and marketing circulars through MailKit's queue, only from a verified domain | `campaigns.enabled` | MailKit (`sending_domains`, tracking) | v1.183 |
| **F-3** automation + sequences | 0.25.0 | CRM triggers and actions for AutomationKit, the mailbox e-mail action and the sequence stop signals (reply, booking, unsubscribe); the sequences engine itself is AutomationKit's (Q-AK-1) | `automation.enabled` | **new `nowo-tech/automation-kit-bundle`** 0.1.0 (Q-254-7: repo created, contract first; Workflow evaluated first); WorkflowBundle through an AutomationKit action (as is); UiKit rule builder; OutboundUrlGuard (as is) | v1.184 |
| **G** forecast, goals, teams | 0.26.0 | Forecast categories with override history, goals per rep / team and period; HubSpot goals and owners' teams | `forecast.enabled` | ReportKit (as is; CrmKit ships the report types in `Bridge/ReportKit`); TaskBoard teams (as is) | v1.185 (may swap with C or E) |
| **H** knowledge base | — (bridge in 0.20.0) | Published spaces; suggestions in ticket replies | — | **WikiBundle `published_spaces`** (next Wiki minor) | with D-2 or alone |
| **I** booking links | 0.27.0 | Booking pages, availability (free / busy only), signed cancel / reschedule, meeting + ICS, sequence stop | `booking.enabled` | TaskBoard (event source, as is); **TimeTrack: busy-window reader** (Google / Microsoft) | v1.186 |

Spec 254 allows C, E and G to swap with a neighbour when a customer asks. The Beacon release column is a target that
assumes one kit minor per Beacon release from v1.176; it is not a commitment.

### Reuse matrix

Owner rule of 2026-11-23: reuse or extend a nowo bundle before building anything CRM-only. Bundle versions are the
latest tags on 2026-11-23. A version for an extension is that bundle's next free minor, planned at the time of writing;
it moves if that bundle releases something else first. Each extension is noted in that bundle's `docs/ROADMAP.md`
(pull requests 2026-11-23: MailKitBundle #59, TimeTrackBundle #143, FormKitBundle #94, UiKitBundle #164,
WorkingTimeBundle #21, WikiBundle #185, ChatKitBundle #125). AutomationKitBundle gets its plan in its contract (repo
created, README only).

| Capability | Block | Bundle | Verdict | What changes in that bundle | Version |
| --- | --- | --- | --- | --- | --- |
| Meetings on a calendar | A-1, I | TaskBoardBundle 2.31.0 | Reuse as is | Nothing: `CalendarEventSourceInterface` (timed events since 2.24); CrmKit's `Bridge/TaskBoard` adds a source | ≥ 2.24 |
| Outlook / Google meetings already in a calendar | A-1 | TimeTrackBundle 1.39.0 | Reuse as is | Nothing: read-only calendar readers and the meeting read model, through Beacon's host activity source | ≥ 1.19 |
| ICS invites | A-1, I | MailKitBundle 2.2.1 | Extend | ICS invite part on outgoing mail (`text/calendar`, `METHOD:REQUEST` / `CANCEL`, UID, SEQUENCE) | 2.3.0 |
| Call logging | A-1 | SmsKitBundle 2.0.1 | New in CrmKit | Nothing: SmsKit sends text messages only (no voice); reused as is later for the reserved `sms` kind | — |
| @mentions | A-1 | MilkdownEditorBundle 1.19.0 | Reuse as is | Nothing: `@` suggestion list; CrmKit supplies the candidates | ≥ 1.19 |
| Notifications (mentions, SLA, reminders) | A-1, D-1 | NotifyKitBundle 0.3.0 | Reuse as is | Nothing (e-mail delivery arrives with NotifyKit S4, 0.4.0) | ≥ 0.3.0 |
| E-mail templates and snippets | A-2 | MailKitBundle | Extend | Stored templates (personal / shared scope through a host owner port, locale, subject, body, snippets) and a closed placeholder resolver registry by service tag; CrmKit adds the CRM placeholders | 2.4.0 |
| Sending, sent-mail log | A-2, C-2, D-2, E-2, F | MailKitBundle | Reuse as is | Nothing | — |
| Personal Outlook mailboxes | B | MailKitBundle | Extend | Graph delegated mode for person mailboxes (read, send, delta sweep) with host-held tokens through a credential port | 2.5.0 |
| OAuth connect for Microsoft / Google | B | TimeTrackBundle | Extend (Q-254-15, decided) | PKCE OAuth helpers accept configurable extra mail scopes; one connection per person and provider in the host; MailKit has no OAuth flow or callback | 1.40.0 |
| Gmail mailboxes | B | MailKitBundle | Extend | Gmail provider behind the same mailbox port, off until Google's review | 2.6.0 |
| Open tracking | B | MailKitBundle | Reuse as is | Nothing: pixel, `MailOpenedEvent`, `MailMachineOpenedEvent`, `EmailTrackingPolicyInterface`, purge | ≥ 1.10 |
| Click tracking | B | ShortLinkBundle 1.0.0 | Reuse as is | Nothing: `createMany()` kind `email`, `ShortLinkClickedEvent`, MailKit `link_rewriter` | ≥ 1.0 |
| Consent per purpose | B, E-2, F | CrmKit (phase A) | Reuse as is | — (CookieConsent covers site visitors' cookie categories, not a contact's purposes) | — |
| Privacy text and link | B, E-2 | LegalKitBundle 1.15.3 | Reuse as is | Nothing (`upgrade-seed`, current privacy page) | — |
| Products, line items, MRR | C-1 | — | New in CrmKit | No bundle models products or prices | — |
| Amounts | C | CurrencyKitBundle 0.1.1 | Not used for totals | Nothing: reference rates only; optional `reference_money` display later | — |
| Quote PDF and brand | C-2 | ReportKitBundle 0.3.0, brand-contracts | Reuse as is | Nothing: `PdfDocumentRendererInterface`, brand resolver | — |
| Quote acceptance link and record | C-2 | PdfSignableBundle 3.13.0 | New in CrmKit | Nothing now: PdfSignable has no storage, tokens or public flow; later PAdES / TSA provider through its `PDF_SIGN_REQUEST` event | — |
| PDF editing | — | PdfEditorBundle 1.8.0 | Not used | Admin editing workspace, not generation | — |
| Quote numbering | C-2 | SerialNumberBundle 1.2.0 | New in CrmKit | Nothing: formatter without an atomic counter; CrmKit `crm_sequence` (QN-1..10) | — |
| Tickets and pipeline | D-1 | CrmKit funnels; ChatKitBundle 2.3.0 | Reuse CrmKit's engine | ChatKit support is platform user ↔ operator chat (no company / contact / deal, SLA or e-mail) | — |
| SLA business-time clock | D-1 | WorkingTimeBundle 0.3.1 | Extend | Business-time calculator: weekly opening hours + holiday calendar; add / count business minutes | 0.4.0 |
| Teams | D-1, G | TaskBoardBundle | Reuse as is | Nothing: teams with manager / member roles behind `CrmTeamDirectoryInterface` (UserKit / AuthKit have no groups; RbacKit groups are permissions) | — |
| Ticket e-mail channel | D-2 | MailKitBundle | Reuse as is | Nothing: Graph inbound, plus-address tags, `GraphInboundHandlerInterface` (a tagged handler chain only if two kit handlers must coexist) | ≥ 1.9 |
| Ticket chat channel | D-3 | ChatKitBundle | Extend (later, Q-254-8) | Customer-channel option for signed-in portal contacts | after phase F |
| Knowledge base and suggestions | H, D-2 | WikiBundle 1.54.0 | Extend | `published_spaces`: public read-only published pages, search over them, no author names | 1.55.0 |
| Static lists | E-1 | CrmKit tags | Reuse (Q-254-16, decided) | `list` flag on tags | — |
| Dynamic segments | E-1 | SearchKitBundle 1.12.0 | New in CrmKit | Nothing: SearchKit is free-text search over provider groups, no structured filters | — |
| Rule / condition editor | E-1, F-3 | UiKitBundle 2.71.0 | Extend | Rule-builder component (field / operator / value rows on a FormKit collection) | 2.72.0 |
| Public embeddable forms | E-2 | FormKitBundle 2.25.0 | Reuse + extend | Reuse `buildFormFromArray()` and `stateless_csrf`; add a honeypot field extension and an embed mode (signed form token bound to the form and an allowed-origins list) | 2.26.0 |
| Anti-spam proof of work | E-2 | AltchaTypeBundle 1.5.0 | Reuse as is | Nothing | — |
| Double opt-in | E-2 | — | New in CrmKit | UserKit / AuthKit verify account holders, not contacts without an account | — |
| Rules and sequences engine | F-3 | WorkflowBundle 2.3.0 → AutomationKitBundle | New bundle (Q-254-7; Q-AK-1..17 decided) | Workflow evaluated first, reused as is as an action; AutomationKit 0.1.0 holds rules **and** sequences (steps, windows, caps, enrolments; Q-AK-1); CrmKit contributes triggers, the mailbox e-mail action and stop signals | AutomationKit 0.1.0 |
| Failed automation runs | F-3 | MessengerMonitorKitBundle | Reuse as is | Nothing | — |
| Webhooks | F-3 | OutboundUrlGuardBundle | Reuse as is | Nothing | — |
| Sending domains (SPF / DKIM / DMARC) | F-0 | MailKitBundle | Extend | `sending_domains` (Resend domains API on Resend, DNS checks otherwise), refused sends with the reason | 2.7.0 |
| Lead scoring | F-1 | — | New in CrmKit | No bundle scores records | — |
| Forecast and goals data | G | — | New in CrmKit | No bundle models them | — |
| Reports, exports, scheduled mail | G | ReportKitBundle | Reuse as is | Nothing: CrmKit ships report types in `Bridge/ReportKit` | — |
| Owners | all | host port `CrmActorDirectoryInterface` | Reuse as is | — | — |
| Booking pages | I | — | New in CrmKit | No bundle has public booking | — |
| Outlook / Google free / busy | I | TimeTrackBundle | Extend | Busy-window reader for future windows (start / end only) | 1.41.0 |

### Old phases and spec 254

| Phase of the 2026-11-19 plan | Where it goes now |
| --- | --- |
| B2 tasks and agenda | Meetings and calls with attendees, the TaskBoard event source and ICS move into **A-1**; "my agenda" and NotifyKit reminders stay B2 and may ride with A-1 (Q-254-2) |
| C quotes | Split into **C-1** (catalogue, line items) and **C-2** (quotes) |
| D invoices | Unchanged, not part of spec 254; needs QN-9 (tax advisor) first; after C-2 |
| F customer portal | Unchanged, not part of spec 254; **D-3** (chat channel) waits for it |
| S scoring | **F-1** |
| E1 lists and segments | **E-1** |
| E2 circulars | **F-2** (needs the verified domain of FR-064) |
| G reports | Forecast and goals become **G**; velocity, cohorts and deal scoring stay in phase G "reports" later |

### Block A — activities and templates (A-1 0.11.0, A-2 0.15.0)

- **A-1 as built (0.11.0, Beacon tasks T001 and T002; spec in `specs/002-activities/`).** `activities.enabled` (off by
  default); calls, meetings and e-mails (`Engagement`, its records and attendees, three tables) with log / edit dialogs
  loaded on demand; the activity feed of a company, contact or deal (notes, activities, host items) filtered by kind and
  person, keyset pages; fan-out of a contact's activity to its companies and open deals (a company's to its open deals);
  pinned notes (`activities.max_pinned`, default 1); @mentions with MilkdownEditor as is (candidates: same tenant, may
  view the record) and NotifyKit 0.3 as is (`CrmNotifierInterface`, NotifyKit bridge); GDPR export / erasure /
  retention (`timeline_years`) and actor anonymisation; audit entries and after-commit events; the SearchKit group
  `crm_activities`; the host activity source (`CrmHostActivitySourceInterface`, T002); meetings on TaskBoard's calendar
  (`Bridge/TaskBoard`, TaskBoard >= 2.24); an authorised `.ics` download of a meeting. Deviations from the plan below:
  - MailKit has no ICS invite part yet (2.2.2): A-1 ships the download, not invites. The MailKit extension stays the
    follow-up of the reuse matrix (`text/calendar`, `METHOD:REQUEST` / `CANCEL`, UID, SEQUENCE; MailKit 2.3.0), with
    `CrmMailSenderInterface` / `CrmOutgoingMail` / `CrmSentMailRef`; `IcsInvite` already carries UID and SEQUENCE.
  - The HubSpot engagements import (notes, calls, meetings, tasks) is the next slice (HubSpot part 4, after H2).
  - NotifyKit reminders (Q-254-2) are not part of 0.11.0; they reuse `CrmNotifierInterface` in a later slice.
  - `ActivityDirection` (in / out) serves calls and e-mails instead of a `CallDirection` of calls only.

- **Reuse (owner rule).**
  - Meetings on a calendar: **reuse TaskBoard as is** (`CalendarEventSourceInterface`, timed events since 2.24;
    CrmKit's `Bridge/TaskBoard` adds a source). Meetings already in a person's Outlook / Google calendar: **reuse
    TimeTrack as is** (its read-only calendar readers and meeting read model) through Beacon's host activity source.
  - ICS invites: **extend MailKit** with an ICS invite part (`text/calendar`, `METHOD:REQUEST` / `CANCEL`, UID,
    SEQUENCE). No bundle writes invites today (WorkingTime only parses ICS for holiday imports).
  - Call logging: **new in CrmKit**, because no bundle logs calls: SmsKit sends text messages only (no voice, no CTI)
    and telephony is out of scope. SmsKit is **reused as is** later for the reserved `sms` kind.
  - @mentions in notes: **reuse MilkdownEditor as is** (its `@` suggestion list; TiptapEditor has the same); CrmKit
    supplies the candidates, members who may see the record.
  - Mention notifications: **reuse NotifyKit 0.3 as is** (in-app rows, bell, read-time visibility); e-mail delivery
    comes with NotifyKit S4 (0.4.0) without CrmKit changes.
  - Templates and snippets: **extend MailKit** with stored templates (personal / shared scope, locale, subject, body,
    snippets) and a closed placeholder resolver registry by service tag. MailKit sends mail today but stores no
    templates (its own roadmap already plans "Phase 2 — branded, editable e-mail templates" with template definitions
    as tagged services and placeholders; A-2's needs are added to that phase: personal / shared user templates and
    snippets). CrmKit A-2 only contributes the `contact.*`, `company.*`, `deal.*`, `me.*` placeholder
    provider, the consent purpose per template and the insert in *Write e-mail*.
  - Sending, the log and tracking hooks: **reuse MailKit as is**. Search of the new fields: **reuse SearchKit as is**.
- **Depends on.** Phase B (0.3.0). TaskBoard ≥ 2.24 (`CalendarEventSourceInterface`, timed events) through the existing
  `Bridge/TaskBoard`; NotifyKit for @mentions (recipient access re-checked at write time); MailKit to send ICS invites
  and templated mail; SearchKit for the new searchable fields. Spec 248's *Write e-mail* can ship before or after A-2.
- **New ports (host-implemented, safe default).**
  - `CrmHostActivitySourceInterface` — `items(TenantRef, ActorRef $viewer, SubjectRef, TimelineWindow): list<HostActivityItem>`;
    the host returns only items the viewer may see (tasks, time entries, linked mail, tickets); the kit drops any item
    of another subject. Default: none.
  - `CrmNotifierInterface` — `notify(CrmNotification)`; NotifyKit adapter in `Bridge/NotifyKit`; default: no-op. Shared
    later by B2 reminders, D SLA warnings and F notify actions.
  - `CrmMailSenderInterface` — `send(CrmOutgoingMail): CrmSentMailRef`; MailKit adapter; used by ICS invites (A-1),
    templates (A-2), quotes (C-2), ticket replies (D-2), double opt-in (E-2) and circulars / sequences (F).
- **New VOs / enums.** `ActivityKind` cases `call`, `meeting`, `email` (plus reserved `form_submitted`, `sms`);
  `CallDirection` (in / out), `CallOutcomeKey` (configured catalogue key), `ActivityDuration` (minutes, 0–1440),
  `MeetingSlot` (start, end, IANA zone; end after start), `MeetingAttendee` (an `ActorRef` or a contact `RecordId`),
  `MeetingPlace` (text ≤ 200 or an https URL), `EmailExcerpt` (≤ 500, plain text), `TimelineFilter` (kinds, person),
  `TimelineWindow`, `HostActivityItem`, `MentionRef`, `CrmNotification`, `CrmOutgoingMail`, `CrmSentMailRef`,
  `IcsInvite` (mapped to MailKit's invite part); A-2: `MergeField` (closed grammar
  `\A(contact|company|deal|me)\.[a-z_]{1,40}\z` with `/D`) and `TemplateRef` (a MailKit template id); the template
  store, `TemplateScope` and snippets live in MailKit.
- **Audit before tag (normal REQ-SEC-004).** Template merge fields (closed resolver, never Twig, unknown fields shown
  as text, escaping in HTML bodies); @mentions (only members who may see the record, no leak of the record's title
  in the notification to anyone else); host activity items checked per viewer; fan-out never shows a contact's
  activity on a company the viewer cannot see.
- **HubSpot import (A-1).** Notes, calls, meetings, tasks (→ next actions) with their associations to companies,
  contacts and deals; owners → actors as in H1. Scopes: see [HubSpot scopes](#hubspot-scopes-verification).

### Block B — mailbox sync and tracking (CrmKit 0.16.0)

- **Reuse (owner rule).**
  - Personal Outlook mailboxes: **extend MailKit** with Graph delegated mode (MailKit 1.8 / 1.9 is app-only on shared
    mailboxes). Gmail: **extend MailKit** with a Gmail provider behind the same mailbox port.
  - OAuth connect, refresh and revoke for Microsoft / Google: **extend TimeTrack** (Q-254-15, Decision 2026-11-23: (b)). TimeTrack already has
    PKCE OAuth helpers for both providers (`CalendarOAuthClients`, runtime OAuth apps, host-held tokens through
    `CalendarCredentialProviderInterface`); they gain configurable extra scopes so one consent per person covers the
    calendar and the mailbox. MailKit then reads host-held tokens through a credential port instead of running its
    own OAuth flow. MailKit B1 (Outlook) and B2 (Gmail) have **no OAuth flow or callback of their own**: they consume a token
    port. The host keeps one connection per person and provider, with tokens encrypted by DoctrineEncrypt.
  - Open tracking: **reuse MailKit as is** (1.10+: pixel, `MailOpenedEvent`, `MailMachineOpenedEvent`,
    `EmailTrackingPolicyInterface`, purge command). Click tracking: **reuse ShortLink as is** (`createMany()` with kind
    `email`, `ShortLinkClickedEvent`, plugged in as MailKit's `link_rewriter`).
  - Tracking consent: **reuse CrmKit's consent purposes as is** (`tracking`). CookieConsent is not used: it records a
    site visitor's cookie categories, not a contact's consent per purpose. Privacy text: **reuse LegalKit as is**
    (`upgrade-seed`).
  - Auto-link, `email` activities and reply detection: **new in CrmKit**, because they are CRM record logic on top of
    MailKit's messages.
- **Depends on.** **MailKit B1** (new minor): personal Outlook mailboxes through Graph *delegated* permissions (MailKit
  today reads and sends app-only on shared mailboxes, 1.8 / 1.9), with host-held tokens obtained through TimeTrack's
  OAuth helpers (state + PKCE; Q-254-15, decided), sealed by the host (DoctrineEncrypt in Beacon), revoked on unlink; delta
  sweep per mailbox. **MailKit B2** (new minor, off by
  configuration): Gmail provider (OAuth, sealed tokens, revocation, push + history sweep). MailKit tracking (1.10:
  pixel + link rewriting; ShortLink for clicks). Block A (`email` activity kind). Spec 236 / 248 for Beacon wiring.
- **Prerequisite with lead time (owner / ops, start now, T009).** Google Cloud project and OAuth app, consent screen,
  verified domain, privacy policy URL; Google's OAuth verification. Verified on Google's scope page (2026-11-23):
  `gmail.readonly` is **restricted** (needs the third-party security assessment), `gmail.send` is **sensitive**
  (verification without the assessment). Outlook never waits for it.
- **CrmKit stays provider-agnostic.** Gmail needs no CrmKit release: MailKit hands both providers' messages to the
  same CrmKit service.
- **New service / ports.** `CrmMailLinkerInterface::link(LinkedMessage)` (called by the MailKit bridge; links only when
  the counterpart is a contact by exact `EmailAddress::key()` and the person's option is on; otherwise nothing is
  stored); `CrmTrackingPolicyInterface` (contact's `tracking` consent + organisation switch → MailKit's
  `EmailTrackingPolicyInterface`). VOs: `MailMessageRef` (provider + hash of `internetMessageId`), `MailDirection`,
  `LinkedMessage`, `TrackingSignal` (opens, clicks, first / last, machine flag), `ReplySignal`, `MailboxOwnerRef`.
- **Immediate audit (crypto, auth, personal data).** Mailbox OAuth tokens (seal, AAD per person and mailbox, refresh,
  revoke on unlink, never logged; state + PKCE); tracking pixels and GDPR (consent mode per D-20261112-19, off per
  organisation by default, never after `tracking` is withdrawn, truncated IP, user-agent family only, 90-day purge,
  machine opens apart); no body stored for unlinked mail; a private message stays neutral for other viewers.
- **HubSpot import.** E-mail engagements: metadata always, bodies only on the organisation's opt-in (FR-101).

### Block C — products, line items and quotes (C-1 0.17.0, C-2 0.18.0)

- **Reuse (owner rule).**
  - Catalogue, line items, MRR / ARR: **new in CrmKit**, because no bundle models products or prices.
  - Amounts: **reuse CrmKit `Money` as is**. CurrencyKit is not used for totals: its rates are reference rates only
    ("not payment, settlement, trading or accounting rates") and multi-currency conversion is out of scope. An optional
    `≈ EUR` hint can use CurrencyKit's `reference_money` filter **as is** later.
  - Quote PDF: **reuse ReportKit as is** (`PdfDocumentRendererInterface`, the closed Dompdf runner, and its brand
    resolver over brand-contracts). PdfEditor is not used: it is an admin workspace for editing existing PDFs, not a
    document generator.
  - Acceptance link and immutable record: **new in CrmKit**, because PdfSignable is a widget for placing signature
    boxes, with events and audit-metadata keys but no storage, tokens or public answer flow. PdfSignable is **reused as
    is** later as the PAdES / TSA provider behind `CrmSignatureProviderInterface` (its `PDF_SIGN_REQUEST` event); the
    acceptance record uses the same evidence fields as its `AuditMetadata` (submitted at, IP, user agent).
  - Quote numbering: **new in CrmKit** (`crm_sequence`, owner decisions QN-1..10), because SerialNumberBundle formats
    patterns but keeps no atomic counter.
- **Depends on.** Phase B. ReportKit's closed PDF runner (Dompdf: no remote, no JS, no PHP) and its brand resolver;
  brand-contracts for the organisation brand. **CurrencyKit is not a dependency:** it gives reference rates only
  ("not payment, settlement or accounting rates") and multi-currency conversion is out of scope; CrmKit keeps its own
  `Money` (decimal string + ISO 4217). An optional reference display (`≈ €…`) through CurrencyKit can come later.
  Quote numbering uses the CrmKit `crm_sequence` series of the QN-1..10 design (atomic per series); SerialNumberBundle
  is a formatter only and is not needed.
- **New ports.** `CrmSignatureProviderInterface` (spec: own acceptance link by default, a provider later);
  `CrmDocumentRendererInterface` — `renderQuote(QuoteDocument, CrmBrand): RenderedPdf` (ReportKit adapter);
  `CrmBrandProviderInterface` — `brandOf(TenantRef): CrmBrand` (brand-contracts adapter; default: kit neutral brand).
- **New VOs / enums.** `Sku` (unique per tenant), `TaxRate` (decimal percent, scale 4, 0–100), `RecurringPeriod`
  (none / monthly / yearly), `Quantity` (decimal, > 0), `Discount` (percent or amount), `LineItemDraft`, `DealTotals`
  (one-off, MRR, ARR), `QuoteNumber`, `QuoteVersion`, `QuoteStatus` (draft, sent, accepted, rejected, expired,
  superseded), `AcceptanceToken` (random 32 bytes; only its SHA-256 stored), `AcceptanceRecord` (typed name, e-mail the
  link was sent to, time, IP, user agent, `DocumentHash`), `DocumentHash` (SHA-256 hex), `CrmBrand`, `RenderedPdf`.
- **Immediate audit (C-2: public anonymous surface, evidence).** Quote acceptance link (signed, expiring, single use,
  stored hashed, constant-time compare, same answer for unknown / expired / used / forbidden, rate limit, no personal
  data echoed, CSP nonce, no enumeration of quote numbers); immutability of the acceptance record and the hash of the
  accepted PDF version; amounts hidden without `amounts_view` on pages, PDF, API and search; concurrent numbering.
- **HubSpot import.** C-1 products and line items (deal ↔ line item associations; amount kept as imported); C-2 quotes
  (status mapped; no acceptance record is invented for an imported accepted quote).

### Block D — tickets, SLA and support inbox (D-1 0.19.0, D-2 0.20.0, D-3 later)

- **Reuse (owner rule).**
  - Ticket record and pipeline: **reuse CrmKit's own funnel engine** (`FunnelKind::Ticket`). ChatKit's support
    inbox is not reused for D-1 / D-2: its tickets are chats between platform users and an operator, with no company,
    contact or deal, no SLA and no e-mail channel.
  - SLA clock on business hours: **extend WorkingTime** with a business-time calculator (weekly opening hours plus a
    holiday calendar it already has; add or count business minutes). TaskBoard due dates and Workflow are not used:
    due dates are calendar dates with no pause or business-minute arithmetic, and Workflow has no timers. TaskBoard can
    reuse the calculator for due dates in business days later.
  - SLA warnings: **reuse NotifyKit as is**. Teams: **reuse TaskBoard as is** (teams with manager / member roles)
    behind `CrmTeamDirectoryInterface` (`Bridge/TaskBoard`; Beacon may plug its own adapter). UserKit and AuthKit have
    no groups; RbacKit has permission groups, not teams of people.
  - E-mail channel: **reuse MailKit as is**: Graph inbound with plus-address tags and the host port
    `GraphInboundHandlerInterface`. Beacon's handler hands `support+<token>` mail to CrmKit's bridge. MailKit is only
    extended (a tagged handler chain) if two kit handlers must coexist without host code.
  - Chat channel (D-3): **extend ChatKit** with a customer-channel option, later (Q-254-8).
  - Article suggestions: **reuse Wiki's search as is** over published pages (block H) through the D-2 bridge.
- **Depends on.** Block A (timeline). NotifyKit (SLA warnings). MailKit inbound as is for per-organisation ticket
  addresses (MailKit 1.9 already normalises inbound Graph mail, plus-address tags and SPF / DKIM / DMARC verdicts and
  hands it to a route / quarantine / discard port). WorkingTime business-time calculator (extension). ChatKit is **not** reused for D-1 / D-2: its support tickets are
  platform support (Beacon users → operator) and its roadmap keeps guest / external accounts out; D-3 needs a new
  ChatKit customer-channel option and phase F (Q-254-8).
- **New ports.** `CrmBusinessHoursInterface` — `calendarOf(TenantRef, ?TeamRef): BusinessCalendar` (default adapter
  over WorkingTime's business-time calculator in `Bridge/WorkingTime`; Beacon maps its organisation's working calendar
  and holidays; default without WorkingTime: 24 × 7);
  `CrmTeamDirectoryInterface` — `teamsOf(TenantRef, ActorRef): list<TeamRef>`, `membersOf(TenantRef, TeamRef)`
  (needed by FR-044 "agents see their teams' tickets", so it ships in D-1, not G; Q-254-11);
  `CrmArticleSuggesterInterface` — `suggest(TenantRef, string $query, int $limit): list<ArticleSuggestion>` (D-2, Wiki
  bridge); D-2 implements MailKit's inbound route port in `Bridge/MailKit`.
- **New VOs / enums.** `FunnelKind::Ticket`, `TicketNumber`, `TicketPriority`, `SlaPolicy`, `SlaTarget` (minutes),
  `BusinessCalendar`, `BusinessHoursWindow`, `SlaClock` (running / paused, due at), `TeamRef`, `InboundTicketAddress`
  (per-organisation random token, rotatable), `ReplyToken`, `ArticleSuggestion` (title, public path).
- **Immediate audit (D-2: anonymous inbound surface).** Ticket inbound address (random, rotatable, never derived from
  the organisation's name or id; plus-address tags are not authentication; spoofed senders: a matching From address
  is only a hint, SPF / DKIM / DMARC verdicts advisory, unknown or failing senders give a ticket without contact and
  never a new contact; reply threading by token only; attachment caps and type checks; no auto-reply loops). Agents
  limited to their teams; SLA timers against the 20-case reference table (SC-004).
- **HubSpot import (D-1).** Tickets and ticket pipelines (→ ticket funnels and stages, created on confirmation as in
  H2), ticket ↔ company / contact / deal associations.

### Block E — segments and forms (E-1 0.21.0, E-2 0.22.0)

- **Reuse (owner rule).**
  - Static lists: **reuse CrmKit tags** (Q-254-16, Decision 2026-11-23: tags with a `list` flag). A static list is a tag (H1 already maps HubSpot lists to tags);
    tags gain a `list` flag so lists can be filtered apart.
  - Dynamic segments: **new in CrmKit** (rules and live counts), because SearchKit is a free-text header search over
    provider groups with no structured filter model, and ReportKit's non-goals exclude a query builder.
  - Rule editor UI: **extend UiKit** with a rule-builder component (field / operator / value rows on a FormKit
    collection), shared by CrmKit segments and AutomationKit conditions. UiKit has no such component today.
  - Public forms: **reuse FormKit** (`buildFormFromArray()` for forms defined as data, `stateless_csrf` for anonymous
    forms) and **extend FormKit** with (1) a honeypot field extension and (2) an embed mode: a signed form token bound
    to the form and to an allowed-origins list. Stateless CSRF relies on same-origin `Origin` / `Referer`, so it cannot
    protect a form embedded on the customer's site. The CrmKit engine (Q-254-5) keeps the mapping, consent and double
    opt-in.
  - Proof of work: **reuse AltchaType as is** (`AltchaType`, single-use payloads, `invisible` / `contact` profiles).
    Privacy link: **reuse LegalKit as is**. Rate limits: Symfony RateLimiter.
  - Double opt-in: **new in CrmKit**, because UserKit and AuthKit verify account holders, not contacts without an
    account; the confirmation mail goes through MailKit (as is).
- **Depends on.** Block A (the `form_submitted` activity). E-1: UiKit rule builder (extension). E-2: FormKit honeypot
  and embed mode (extension), AltchaTypeBundle (proof of work next to the honeypot), LegalKit (privacy link and consent
  text), Symfony RateLimiter, MailKit for the double opt-in mail from the
  organisation's verified domain once F-0 exists; until then from Beacon's platform domain as a service message (Q-254-6, decided). ContactFormBundle is not reused
  (Q-254-5, decided): it serves hosted contact forms without embedding, origin checks, field mapping or double opt-in;
  the CrmKit engine is built on FormKit and AltchaType instead.
- **New ports.** `CrmCaptchaVerifierInterface` (Altcha adapter; default: honeypot only), `CrmPrivacyLinkInterface`
  (LegalKit adapter: URL of the current privacy page per locale).
- **New VOs / enums.** `ListId`, `SegmentRule` (closed set of fields and operators: role, tag, purpose, consent, score
  band, stage, owner, field value, last activity), `SegmentDefinition`, `FormKey` (random slug), `FormFieldMapping`,
  `AllowedOrigin` (scheme + host + port, exact match), `FormToken` (signed, expiring, bound to form and origin),
  `UtmParams` (closed keys, ≤ 200 each), `DoubleOptInToken` (hashed at rest).
- **Immediate audit (E-2: anonymous public surface, consent).** Web forms: allowed origins (CORS and `frame-ancestors`),
  signed token replay, honeypot + Altcha, rate limits per IP and per form, no enumeration of existing contacts (same
  answer whether the e-mail exists), no personal data echoed, redirect only to allowed URLs, consent recorded only
  after double opt-in, no file upload in v1.
- **HubSpot import.** E-1 lists (static → lists; active → segments where every filter maps to a `SegmentRule`, else a
  static snapshot with a note); form definitions are not imported in v1 (Q-254-3, decided 2026-11-23: `forms` is not a read-only scope).

### Block F — automation, sequences and lead scoring (F-1 0.23.0, F-2 0.24.0, F-3 0.25.0)

- **Reuse (owner rule).**
  - Rules engine: **WorkflowBundle was evaluated first.** It persists Symfony Workflow state machines bound to a
    subject's marking property, with guards in host listeners and transitions applied by host code
    (`WorkflowRuntimeInterface`). It has no event triggers, no delays or timers, no action registry, no per-event
    idempotency and no run log. Hosting "event → conditions → delay → actions" rules would add a second engine beside
    the state machine. So the rules run in the **new AutomationKit** (Q-254-7 stands; the repo is created, contract
    first), and Workflow is **reused as is** through an AutomationKit action that applies one of its transitions.
    No new owner question.
  - Delays: Symfony Messenger (`DelayStamp`) and Scheduler. Failed runs: **reuse MessengerMonitorKit as is**.
    Webhooks: **reuse OutboundUrlGuard as is**. Conditions UI: the UiKit rule builder of block E.
  - Sending domains: **extend MailKit** with `sending_domains` (F-0).
  - Sequences: **reuse AutomationKit** (Q-AK-1, Decision 2026-11-23): steps, delays, business-day windows, daily caps
    and enrolments live in AutomationKit. CrmKit only contributes the *send from the person's mailbox* e-mail action
    (MailKit as is after block B, plus the verified-domain check) and the stop signals (reply, booked meeting,
    unsubscribe), which end an enrolment.
  - Scoring: **new in CrmKit**, because no bundle scores records.
- **Depends on.** **New `nowo-tech/automation-kit-bundle`** (repo created, contract first; generic: trigger / condition / action registry by service
  tag, delays on Messenger, idempotency per event, loop depth, dry run, run log, audit, UiKit pages; no CRM dependency).
  WorkflowBundle stays the state-machine engine and is reached through an AutomationKit action that applies one of its
  transitions; it is not extended into a rule engine (it has no triggers, delays or actions). **MailKit
  `sending_domains`** (F-0, FR-064): per-organisation domain, SPF / DKIM / DMARC DNS checks (Resend domains API when
  the Resend transport is used, DNS lookups otherwise), refused sends with the reason. Block B (reply detection,
  personal mailboxes) and E-1 (segments) for F-3.
- **New ports.** `CrmSendingDomainGuardInterface` — `check(TenantRef, EmailAddress $from): SendingDomainStatus` (MailKit
  adapter); `CrmMailboxSenderInterface` — send one e-mail from the enrolling person's linked mailbox (MailKit B1 /
  B2 adapter), used by CrmKit's AutomationKit e-mail action. CRM triggers, the e-mail action and the stop signals are
  AutomationKit services in `Bridge/AutomationKit`, not CrmKit ports.
- **New VOs / enums.** `ScoreRule`, `ScoreBand` (hot / warm / cold, thresholds), `ScoreBreakdown`; `SendingDomainStatus`
  (verified, failing checks with reasons, checked at); `EnrolmentStopReason` (reply, booking, unsubscribe; the signal
  CrmKit sends). Sequence steps, sending windows and daily caps are AutomationKit value objects (Q-AK-1), not CrmKit's.
- **Immediate audit (F-2 / F-3: automation sending mail).** Mail sent by rules and sequences (verified domain checked
  before every campaign and daily for active sequences; daily cap per mailbox; stop on reply / booking / unsubscribe;
  marketing consent; unsubscribe one click); idempotency under two workers and loop depth (SC-005); webhooks only
  through OutboundUrlGuard; a rule of another organisation is never visible or runnable; run log without personal data
  beyond ids.
- **HubSpot import.** None (FR-101: workflows, sequences and scores are rebuilt, not imported).

### Block G — forecast, goals and teams (CrmKit 0.26.0)

- **Reuse (owner rule).**
  - Forecast categories, overrides and goals: **new in CrmKit**, because no bundle models them.
  - Report pages, PDF / XLSX / CSV, scheduled mail: **reuse ReportKit as is**; CrmKit ships the forecast, goals,
    activity and SLA report types in `Bridge/ReportKit` (types are code), and Beacon enables them.
  - Teams: **reuse TaskBoard teams as is** (block D). Owners: **reuse the host port** `CrmActorDirectoryInterface` as is.
- **Depends on.** Phase B; `CrmTeamDirectoryInterface` from D-1 (or introduced here if G ships before D); block C for
  MRR in the forecast (optional). ReportKit report types ship in CrmKit's `Bridge/ReportKit`; Beacon enables them
  (spec 252).
- **New VOs / enums.** `ForecastCategory` (pipeline, best case, commit, closed; default from the stage),
  `ForecastOverride` (with history), `ForecastPeriod` (month / quarter), `GoalMetric` (won amount, deals created,
  activities logged, tickets closed), `GoalTarget`, `GoalProgress`. New permissions `forecast_view`, `goals_manage`.
- **Audit before tag (normal).** A rep sees only their own figures without `forecast_view`; amounts by permission.
- **HubSpot import.** Goals and owners' teams.

### Block H — knowledge base (WikiBundle `published_spaces`; CrmKit bridge in 0.20.0)

- **Reuse (owner rule).** Knowledge base: **extend WikiBundle** with `published_spaces`, built on the
  "Public links" design Wiki's roadmap already has (off until an admin enables it per space, D-W23; no-index;
  `frame-ancestors` allow-list for embeds). Suggestions: **reuse Wiki's search as is** through CrmKit's bridge.
- **Depends on.** **WikiBundle `published_spaces`** (next Wiki minor; Wiki's roadmap lists public links as "later"):
  an organisation publishes a space; only pages marked published are public and read-only, without comments,
  revisions or author names; search over published pages only. CrmKit only adds the suggester (D-2).
- **Immediate audit (Wiki: anonymous public surface).** Unpublished and draft pages 404 anonymously, another
  organisation's space never listed, no author names or revision data, attachments of published pages only, CSP,
  no-index option. Published articles are not imported from HubSpot (`cms.knowledge_base.articles.read` exists but
  spec 254 does not ask for it).

### Block I — meeting scheduling links (CrmKit 0.27.0)

- **Reuse (owner rule).**
  - Booking pages: **new in CrmKit**, because no bundle has public booking.
  - Availability from Outlook / Google: **extend TimeTrack** with a busy-window reader for future windows (start /
    end only) next to its read-only past-event readers (Google free/busy, Graph getSchedule; the provider scopes are
    settled in the TimeTrack contract revision and are not verified here). Planning calendar: TaskBoard event
    sources through Beacon's adapter (as is).
  - Meeting, ICS and calendar event: as in block A (TaskBoard as is, MailKit ICS extension).
- **Depends on.** Block A (meetings, ICS); TaskBoard event source; **TimeTrack busy-window reader** (Planning alone
  works before). The provider permissions for free / busy are not verified here; they are settled in the TimeTrack
  contract revision.
- **New ports.** `CrmAvailabilityInterface` — `freeSlots(TenantRef, ActorRef, AvailabilityWindow): list<FreeSlot>`
  (free / busy only, never event details).
- **New VOs.** `BookingSlug` (random), `BookingPolicy` (duration, buffers, minimum notice, horizon, hours, questions),
  `AvailabilityWindow`, `FreeSlot`, `BookingToken` (signed cancel / reschedule).
- **Immediate audit (anonymous public surface).** Booking page: no event detail leaked, double booking refused under
  concurrency, tampered link 404, rate limits, no enumeration of slugs or people.
- **HubSpot import.** None (`scheduler.meetings.meeting-link.read` exists, but booking pages are rebuilt).

### HubSpot scopes (verification)

Checked on 2026-11-23 against HubSpot's public scope page
(`developers.hubspot.com/docs/apps/developer-platform/build-apps/authentication/scopes`) and the API reference
(legacy v3 and the date-versioned `2026-09` pages). "Verified" means the scope string exists with that meaning;
**unverified** means the docs did not let us confirm the exact minimum for the endpoint.

| Block | Objects | Spec 254 proposal | Finding | Proposed for the build |
| --- | --- | --- | --- | --- |
| A | notes, calls, meetings, tasks | parent read scopes + `crm.objects.owners.read` | Verified: the legacy v3 `GET /crm/v3/objects/calls` accepts `crm.objects.contacts.read`. Granular `crm.objects.notes.read`, `crm.objects.calls.read`, `crm.objects.meetings.read`, `crm.objects.tasks.read` exist in the `2026-09` API (accepted by the object endpoints); they are **not** on the scope page. Minimum per endpoint for the date-versioned API: **unverified** | Parent `.read` scopes (as in H1 / H2) + `crm.objects.owners.read`; accept the granular ones when present; confirm per endpoint in the A-1 build task |
| B | e-mails | `sales-email-read`, `crm.objects.contacts.read` | `sales-email-read` verified, described as "read **and manage** one-to-one email engagements", and it does not end in `.read`, so the H1 read-only rule (Q-H2) refuses it. `crm.objects.emails.read` exists in the `2026-09` API (not on the scope page) | `crm.objects.emails.read` + parent scopes; not `sales-email-read` (Q-254-3, decided). Endpoint minimum: **unverified** |
| C | products, line items, quotes | `e-commerce`, `crm.objects.line_items.read`, `crm.objects.quotes.read` | `e-commerce` is **deprecated** ("should not be used for new apps") and is not read-only. `crm.objects.line_items.read`, `crm.schemas.line_items.read`, `crm.objects.quotes.read`, `crm.schemas.quotes.read`, `tax_rates.read` verified. `crm.objects.products.read` exists in the `2026-09` API but is not on the scope page (**unverified** for v3) | `crm.objects.products.read`, `crm.objects.line_items.read`, `crm.objects.quotes.read` (+ the `crm.schemas.*.read` for the mapping step, `tax_rates.read` if tax rates are mapped); never `e-commerce` |
| D | tickets, ticket pipelines | `tickets` | `tickets` is **legacy** ("migrate to the granular scopes") and is not read-only. `crm.objects.tickets.read` verified. Scope for `GET /crm/v3/pipelines/tickets`: **unverified** (v1 docs name `tickets`) | `crm.objects.tickets.read`; confirm the pipelines endpoint accepts it in the D-1 build task |
| E | lists, form definitions | `crm.lists.read`, `forms` | `crm.lists.read` verified ("view contact lists"). `forms` verified for `GET /marketing/v3/forms`, but it covers the forms APIs as a whole (no read-only variant), so the H1 rule refuses it. Whether active-list filter definitions are readable with `crm.lists.read`: **unverified** | `crm.lists.read`; no `forms` (Q-254-3, decided: form definitions not imported in v1) |
| G | goals, owners' teams | `crm.objects.goals.read`, `crm.objects.owners.read` | Both verified (`crm.objects.goals.read`: Sales Hub Starter or higher). `settings.users.teams.read` exists for teams; whether the owners endpoint returns teams with `crm.objects.owners.read` alone: **unverified** | Both, plus `settings.users.teams.read` only if the owners response lacks teams |

Also found: HubSpot's reference now documents date-versioned paths (`/crm/objects/2026-09/…`, `/crm/owners/2026-03`)
and files the `v3` paths under "legacy". H1–H3 use `v3` as designed; a move is a separate slice when HubSpot announces
a sunset date (to be re-checked in each block's build task). The H1 read-only rule stays: every new scope above is a
`.read` scope; `sales-email-read` and `forms` are not requested (Q-254-3, decided 2026-11-23).

### Owner questions (Q-254)

Q-254-1..14 were decided by the owner on 2026-11-23: every recommendation was accepted. The options stay listed for
the record. Q-254-15 and Q-254-16 come from the owner's reuse rule of the same day and were decided the same day as
recommended. AutomationKit's own questions Q-AK-1..17 were all accepted as recommended (2026-11-23); Q-AK-1 puts the
sequences engine in AutomationKit.

| # | Question | Options | Recommendation and decision |
| --- | --- | --- | --- |
| Q-254-1 | Ship block A as one minor or two? | (a) one minor (spec size M); (b) A-1 activities 0.13.0 + A-2 templates 0.14.0 | Decision 2026-11-23: **(b)**: smaller PRs and audits, as for H1 / H2; A-2 can wait for spec 248's *Write e-mail* without holding A-1 |
| Q-254-2 | Phase B2's "my agenda" and NotifyKit reminders: with A-1 or later? | (a) inside A-1; (b) a later B2 slice | Decision 2026-11-23: **(a) only the reminders** (A-1 already brings NotifyKit for mentions); "my agenda" as a later slice when Beacon asks |
| Q-254-3 | HubSpot scopes that are not `.read` (`sales-email-read` "read and manage", `forms` covers all forms APIs): how to import e-mails and form definitions? | (a) allow-list these two as exceptions to Q-H2; (b) use `crm.objects.emails.read` for e-mails and skip form definitions in v1; (c) a second, separately sealed token only for these objects | Decision 2026-11-23: **(b)**: keeps the rule "a stolen token cannot write to the customer's HubSpot"; forms are quick to rebuild; revisit if `crm.objects.emails.read` proves insufficient in the B build |
| Q-254-4 | Gmail: if Google's security assessment (needed for the restricted `gmail.readonly`) lags or its cost is too high, ship Gmail send-only first? `gmail.send` is only sensitive | (a) wait for the full approval; (b) send-only Gmail first (logging by BCC address of spec 248), reading when approved | Decision 2026-11-23: **(b)** as a fallback the owner can trigger; Outlook stays first either way |
| Q-254-5 | Web forms engine | (a) CrmKit's own `forms` option on AltchaType + LegalKit; (b) extend ContactFormBundle with embed, origins, signed token, mapping and double opt-in, CrmKit only maps | Decision 2026-11-23: **(a)**: CRM mapping, consent and double opt-in live with the contact and its GDPR; ContactForm keeps its hosted forms. Extract a shared embed guard later if a second bundle needs it |
| Q-254-6 | Where does sending-domain verification (FR-064) live, and does the double opt-in mail of E-2 need it too? | (a) MailKit `sending_domains` used by every kit; (b) Beacon only; (c) inside CrmKit | Decision 2026-11-23: **(a)**, reusable by other projects; and **yes** for double opt-in once F-0 exists (until then E-2 sends it from Beacon's platform domain as a service message, not marketing) |
| Q-254-7 | Create `nowo-tech/AutomationKitBundle` (package `nowo-tech/automation-kit-bundle`)? | (a) create the public repo now, contract first, Packagist by the owner before the first tag; (b) build rules inside CrmKit first and extract later; (c) extend WorkflowBundle | Decision 2026-11-23: **(a)**, contract written while E-1 is built; WorkflowBundle stays the state machine (an action applies its transitions) |
| Q-254-8 | Chat channel of block D (signed-in customers) | (a) defer D-3 until phase F (portal) and a ChatKit customer-channel contract; (b) a CrmKit-native message thread on the ticket for portal users, no ChatKit | Decision 2026-11-23: **(a)**: D ships with the e-mail channel; ChatKit's roadmap keeps external accounts out today |
| Q-254-9 | Ticket inbound transport on Beacon's inbound domain | (a) one Graph shared mailbox with plus addresses `support+<token>@…` (MailKit 1.9 parses them); (b) a catch-all domain on Resend inbound or another webhook provider; (c) one mailbox per organisation | Decision 2026-11-23: **(a)** for v1 (no new provider, tokens rotatable); (b) if volume or Exchange limits require it |
| Q-254-10 | Quote acceptance evidence: full IP or truncated? | (a) full IP and user agent in the immutable record, kept with the quote; (b) truncated as for tracking | Decision 2026-11-23: **(a)**: it is evidence of a contract (legal basis: contract), not tracking; disclosed in the privacy text and erased with the quote's retention, not the contact's |
| Q-254-11 | `CrmTeamDirectoryInterface` in D-1 (needed by "agents see their teams' tickets") instead of G | (a) in D-1; (b) in G, D-1 with owner-only scoping | Decision 2026-11-23: **(a)** |
| Q-254-12 | Accepted quote moves the deal to won automatically (FR-034): also when the deal has other open quotes? | (a) yes, others become superseded; (b) only when it is the last open quote | Decision 2026-11-23: **(a)**, with the other quotes superseded in the same write unit |
| Q-254-13 | Lead scoring (F-1) does not need blocks B or E: pull it forward if a release slot is free? | (a) keep the spec order; (b) allow it earlier | Decision 2026-11-23: **(b)**, only when no block of the agreed order is ready |
| Q-254-14 | When is CrmKit 1.0.0 now that spec 254 adds nine blocks? | (a) after every block and phases D / F / G; (b) after blocks A–D are released and audited, later blocks as additive 1.x minors; (c) unchanged (after phase G) | Decision 2026-11-23: **(b)**: A–D settle the core schema; every later block is opt-in and additive |
| Q-254-15 | Who runs the OAuth flow for personal mailboxes (block B) now that TimeTrack already connects Google / Microsoft calendars? | (a) MailKit runs its own OAuth flow and stores tokens; (b) extend TimeTrack's OAuth helpers with configurable extra scopes, Beacon keeps one connection per person and provider (host-held, sealed with DoctrineEncrypt), and MailKit reads tokens through a credential port; (c) extract the OAuth helpers into a new shared bundle | Decision 2026-11-23: **(b)**. It reuses tested PKCE code, gives one consent per person instead of two, and needs no new bundle; (c) only if a third bundle needs the helpers |
| Q-254-16 | Static lists (FR-050): a new list object or CrmKit tags? | (a) a static list is a tag with a `list` flag (H1 already maps HubSpot lists to tags); (b) a separate `crm_list` table | Decision 2026-11-23: **(a)**. It reuses tags, bulk tagging and the tag filters, and needs no second membership model |

### Sequencing

- **Now, in parallel with H1 / H2 / H3 (docs only, no file overlap with the code PRs):** the contract revision of A-1
  (and A-2) in [CONTRACT.md](CONTRACT.md); the Google OAuth app and assessment (T009, owner / ops, weeks of lead time);
  the MailKit B1 contract revision (Graph delegated mailboxes) in MailKitBundle.
- **Bundle extensions first (reuse rule).** Each extension in the [reuse matrix](#reuse-matrix) is built in its own
  repository, in parallel with CrmKit work, and released before the CrmKit slice that consumes it: MailKit ICS
  invites (2.3.0) before A-1 is tagged, MailKit templates (2.4.0) before A-2; TimeTrack extra OAuth scopes (1.40.0) and
  MailKit delegated mailboxes (2.5.0) before B; WorkingTime business time (0.4.0) before D-1; Wiki `published_spaces`
  (1.55.0) before D-2; UiKit rule builder (2.72.0) before E-1; FormKit honeypot and embed mode (2.26.0) before E-2;
  MailKit `sending_domains` (2.7.0) before F-2; AutomationKit 0.1.0 before F-3; TimeTrack busy reader (1.41.0) before I.
  A-1 may be built against a MailKit branch, but it is tagged only once MailKit 2.3.0 is out.
- **Files shared with H2 / H3.** `Infrastructure/Symfony/DependencyInjection` (configuration tree and extension), the
  seven translation files, `Domain/Enum`, the Doctrine mapping registration, CHANGELOG / UPGRADING. This overlap is
  mechanical (one PR rebases on the other). The **HubSpot follow-up of each block** touches `Application/HubSpot`,
  `Infrastructure/HubSpot`, `Domain/Read/HubSpot` and `HubSpotObject`, the same files as H2 / H3: it is the last
  commit of each block's PR and is built only after H2 (and H3 if open) is merged.
- **Block A can start** its code (A-1 builder) as soon as **H1 (PR #23) is merged and 0.10.0 is tagged**, i.e. while
  Beacon works on v1.175 (architecture debt); it runs in parallel with H2 / H3 except its HubSpot engagements commit,
  which waits for H2's merge. Target: A-1 (0.11.0 since 2026-11-26) audited and tagged in time for **Beacon v1.176**; A-2 follows in
  v1.176 or v1.177.
- **Block B** CrmKit side starts after A-1 is merged; MailKit B1 can be built in parallel with A (another repository).
  Gmail (MailKit B2) is built behind its switch and enabled when Google approves.
- **Blocks C, D, E** each start after the previous block's PR is merged (they share the timeline, the configuration
  tree and the translations); C, E and G may swap with a neighbour on a customer's request. D-2 and Wiki
  `published_spaces` (H) can run in parallel (different repositories).
- **Block F** starts after B and E-1; AutomationKit's contract and 0.1.0 can be built in its own repository in
  parallel with E. **G** and **I** follow; I needs B1 for Outlook free / busy.
- Parallel builders on this repository: at most one code PR per shared area at a time (configuration, translations);
  other repositories (MailKit, Wiki, AutomationKit, ChatKit) run in parallel.

## Not planned

Telephony (CTI, call recording), SMS from records (the activity kind is reserved), social media inboxes and
publishing, ads, landing-page / website builders, A/B tests of e-mails, AI scoring and AI-written sequences (rule-based
scoring is phase S), visual multi-branch marketing journeys (spec 254 plans rules and linear sequences only),
multi-currency forecast conversion, revenue attribution, two-way HubSpot sync, an accounting ledger, payment card
processing, native apps.

## Phase A follow-ups

- The public JSON API `/crm/v1` (contract Q-O6 / Q-O16: decided for 0.3.0 by the owner on 2026-11-19).
- A Messenger handler that runs large imports outside the request (0.1.0 runs them in requests and in
  `nowo:crm-kit:import:resume`).
- ~~A link picker on the 360° page that uses `CrmHostObjectResolverInterface::search()`~~ — done in 0.7.0 (optional `CrmHostObjectSearchInterface`).

## Road to 1.0.0

1.0.0 follows the last phase (G), `bundle-req-audit` at 100 % of the applicable REQs and the REQ-SEC-004 audits;
with spec 254, 1.0.0 comes once blocks A–D are released and audited, later blocks as additive 1.x minors (Q-254-14, decided 2026-11-23). Status as of 2026-11-23, latest tag v0.8.0.

- **HubSpot import (H1–H3)** is designed in [design/HUBSPOT_IMPORT.md](design/HUBSPOT_IMPORT.md): H1 0.10.0 (PR #23),
  H2 0.12.0, H3 0.14.0 (since 2026-11-26: A-1 is 0.11.0 and Z1 0.13.0); numbers move up if another release lands first. Owner questions Q-H1..Q-H13 are listed there.
- **HubSpot parity (Beacon spec 254)** adds nine blocks A–I after H3 (section below); its owner questions
  Q-254-1..Q-254-14 were decided on 2026-11-23 (all recommendations accepted). Q-254-15 (mailbox OAuth through TimeTrack) and Q-254-16
  (static lists as tags) were decided the same day, as were AutomationKit's Q-AK-1..17.
- **Phases left:** B2 tasks and agenda, C quotes, D invoices, F customer portal, S scoring, E1 lists and segments,
  E2 informative circulars, G reports; plus the configurable numbering and import key (QN-1..10). Each phase needs its
  own contract revision first. This is the large part of the distance to 1.0.0.
- **Open REQ gaps** (re-audit 2026-11-22, graded at v0.6.0; the next regrade will show the current state):
  REQ-DEMO-013 (demo WebP screenshots and README gallery), REQ-DEMO-015 (`#[FormKitConfig]` on the demo `LoginType`),
  REQ-DOCS-005 (table of contents in CONTRACT.md), REQ-DOCS-018 (GitHub About website), REQ-DOCS-019 (README order),
  REQ-SF-004 (attribute routes instead of `UiRouteLoader`). REQ-SPECKIT-001 / 003 (inventory drift) and REQ-REL-003
  (PR #12) were addressed after the audit by pull requests #13 and #12, both merged.
- **Already in an open pull request:** #16 (`fix/req-gaps`) closes REQ-DEMO-013, REQ-DOCS-005 and the REQ-SF-004
  rule 8 exception. The other gaps above have no open pull request yet.
- **REQ-SEC-004:** the last appendix is YN (0.5.0, notes search). No appendix covers 0.6.0, 0.6.1 or 0.7.0 yet
  (the 0.7.0 audit was launched per the handover; no appendix found yet). Every phase still to come needs its own audit, then the batch audit
  before 1.0.0.
- **Owner decisions still open:** a tax advisor must confirm the strict invoice numbering rules (QN-9) before phase D;
  the contract revision of each remaining phase raises its own Q-O questions.
