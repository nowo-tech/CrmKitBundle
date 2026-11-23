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
| **A** companies and contacts | 0.1.0 | Companies; contacts with affiliations (role, one primary company); owners; tags; custom fields; notes; activity timeline; host links and host panels on a 360° page; CSV and vCard import with preview; duplicate hints and merging; CSV / vCard export; GDPR (consent per purpose, export, erasure, retention); trash with restore; plan-limit counters; SearchKit providers; audit events | always on | Released |
| **A2** roles and purposes | 0.2.0 | Party roles (many per company or contact, configurable catalogue: customer, supplier, partner; distributor opt-in); the company's primary contact; affiliation purposes (billing, orders, technical, general); role / purpose filters; import and export columns; UD-2 concurrency follow-ups | always on | In review |
| **B** funnels | 0.3.0 | Deal funnels and party lifecycle funnels (lead → prospect → customer → recurring → former) with ordered stages, default probabilities, rotting age, won / lost terminal stages; stage history; per-stage statistics (count now and at a past date, sum and weighted value, conversion, time in stage, stale items, won / lost with reasons; filters period, owner, role, tag; chart, table, CSV); next actions through `CrmTaskPort` (TaskBoard adapter, built-in fallback); the public JSON API `/crm/v1` | `pipeline.enabled` | Planned |
| **B2** tasks and agenda | 0.4.0 | Tasks from any record; opt-in automations from TaskBoard templates; meetings and calls with attendees in TaskBoard's calendar (timed event source, TaskBoard 2.24); "my agenda"; NotifyKit reminders; ICS invites; done meetings on the timeline; links never grant access | own switch | Planned |
| **C** quotes | 0.5.0 | Catalogue; quotes from a deal or on a company / contact; versions, PDF, signed expiring accept / reject link, e-sign hook; accepted ⇒ deal won, rejected ⇒ lost reason; shown on the deal and the 360° page | `quotes.enabled` | Planned |
| **D** invoices | 0.6.0 | From accepted quotes, host billable lines or by hand; atomic numbering per series; corrective invoices (issued invoices never edited or deleted); VeriFactu and SEPA after commit; payment status and reminders; overdue ⇒ collection task (opt-in); totals per customer; create / issue / void permissions; GDPR keeps issued invoices for the legal period | `invoices.enabled` | Planned |
| **F** customer portal | 0.7.0 | Contacts sign in (invitation, magic link / passwordless through AuthKit); own party's invoices (company invoices when the affiliation purpose allows), quotes (accept / reject), pending amount, pay-link hook, SEPA mandate status, contact data, consents and subscriptions, self-service GDPR export; signed expiring downloads, rate limits, audit; tenant brand, 7 locales, mobile-first | `portal.enabled` | Planned |
| **S** scoring | 0.8.0 | Explainable rule-based contact and company scoring (e-mail, role, activity, field value, funnel stage, meetings, quotes, invoices); breakdown on the record; hot / warm / cold bands; incremental plus a worker-safe Messenger recompute; audited rule changes | own switch | Planned |
| **E1** lists and segments | 0.9.0 | Static lists and dynamic segments (role, tag, affiliation purpose, has e-mail, consent purpose, score band, funnel stage); bulk add; segments filter the funnel statistics | own switch | Planned |
| **E2** informative circulars | 0.10.0 | One e-mail per recipient through MailKit's queue with a rate limit; per-purpose unsubscribe; `service_notice` purpose; commercial circulars need marketing consent; bounces mark the address; send log; open signal to scoring; payment reminders through this channel | `campaigns.enabled` | Planned |
| **G** reports | later → 1.0.0 | Deal scoring, deeper forecasts, velocity and cohorts, open / click tracking, two-way sync with external calendars, inactive-contact review | `reports.enabled` | Planned |

## Not planned

Telephony (CTI), social media inboxes, AI scoring (rule-based scoring is phase S), multi-step marketing journeys, an accounting ledger, payment card
processing, native apps.

## Phase A follow-ups

- The public JSON API `/crm/v1` (contract Q-O6 / Q-O16: decided for 0.3.0 by the owner on 2026-11-19).
- A Messenger handler that runs large imports outside the request (0.1.0 runs them in requests and in
  `nowo:crm-kit:import:resume`).
- ~~A link picker on the 360° page that uses `CrmHostObjectResolverInterface::search()`~~ — done in 0.7.0 (optional `CrmHostObjectSearchInterface`).
