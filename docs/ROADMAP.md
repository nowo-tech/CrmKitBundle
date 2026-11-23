# Roadmap

The bundle is built phase by phase from [CONTRACT.md](CONTRACT.md) (section 1). Each phase is opt-in by
configuration (`<phase>.enabled`), shippable alone, and when off maps no tables, registers no services or routes and
renders nothing. Each phase is one pull request with tests, docs and a green CI, released as a 0.x minor after its
audit; **1.0.0** follows phase G, `bundle-req-audit` at 100 % and the REQ-SEC-004 audits. Every phase gets its own
contract revision (tables, services, concurrency) before it is built.

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
| **H1** HubSpot import: connection, companies, contacts | 0.9.0 (proposed) | HubSpot as a third import source next to CSV and vCard (same `crm_import` queue, mapping, duplicate policy, limits, progress): a per-tenant private app token, validated (account id, read-only scopes) and sealed; companies, contacts, contact ↔ company associations, owners → members by e-mail, lists → tags; HubSpot ids kept as external refs so a re-run updates; paging, 429 backoff, resume; optional Messenger run. Design: [design/HUBSPOT_IMPORT.md](design/HUBSPOT_IMPORT.md) (Beacon spec 253, D-20261123-04) | `import.hubspot.enabled` | Designed, not started |
| **H2** HubSpot import: deals | 0.10.0 (proposed) | Pipelines → deal funnels, stages → stages (created on confirmation), deals with amount, currency, close date and open / won / lost; deal ↔ company / contact | `import.hubspot.deals` (needs `pipeline.enabled`, phase B released in 0.3.0) | Designed, not started |
| **H3** HubSpot import: OAuth | 0.11.0 (proposed) | "Connect to HubSpot" with state + PKCE, sealed refresh token, revoke on disconnect; the token path keeps working | `import.hubspot.oauth.enabled` | Designed, not started |

Status as of 2026-11-23 (latest tag v0.7.0). Tags v0.4.0 to v0.7.0 were not the phases of the plan: they are UI and
reader releases requested by Beacon (see [CHANGELOG.md](CHANGELOG.md)): 0.4.0 / 0.4.1 / 0.4.2 import modal, 0.5.0 notes
search (`CrmReaderInterface::searchNotes`, `notesOf`), 0.6.0 / 0.6.1 UX options (`ui` layout, list tabs, create dialogs),
0.7.0 area tab icons and the host link picker. So the version numbers in the Version column for B2 to E2 are the plan of
2026-11-19 and are already taken; each phase gets its number in its own contract revision. Phases B2 to G have no code
on `main` and no open pull request. Configurable numbering of customers, quotes and invoices with an import key (owner
decision QN-1..10, 2026-11-20, planned as 0.4.0 at the time) is also not started.

## Not planned

Telephony (CTI), social media inboxes, AI scoring (rule-based scoring is phase S), multi-step marketing journeys, an accounting ledger, payment card
processing, native apps.

## Phase A follow-ups

- The public JSON API `/crm/v1` (contract Q-O6 / Q-O16: decided for 0.3.0 by the owner on 2026-11-19).
- A Messenger handler that runs large imports outside the request (0.1.0 runs them in requests and in
  `nowo:crm-kit:import:resume`).
- ~~A link picker on the 360° page that uses `CrmHostObjectResolverInterface::search()`~~ — done in 0.7.0 (optional `CrmHostObjectSearchInterface`).

## Road to 1.0.0

1.0.0 follows the last phase (G), `bundle-req-audit` at 100 % of the applicable REQs and the REQ-SEC-004 audits.
Status as of 2026-11-23, latest tag v0.7.0.

- **HubSpot import (H1–H3)** is designed in [design/HUBSPOT_IMPORT.md](design/HUBSPOT_IMPORT.md); its version numbers
  follow 0.8.0 (PR #19) and move up if another release lands first. Open owner questions Q-H1..Q-H13 are listed there.
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
