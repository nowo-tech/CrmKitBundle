# Roadmap

The bundle is built phase by phase from [CONTRACT.md](CONTRACT.md) (section 1). Each phase is opt-in by
configuration (`<phase>.enabled`), shippable alone, and when off maps no tables, registers no services or routes and
renders nothing. Each phase is one pull request with tests, docs and a green CI, released as a 0.x minor after its
audit; **1.0.0** follows phase G, `bundle-req-audit` at 100 % and the REQ-SEC-004 audits. Every phase gets its own
contract revision (tables, services, concurrency) before it is built.

## Phases

| Phase | Version | Content | Switch | Status |
| --- | --- | --- | --- | --- |
| **A** companies and contacts | 0.1.0 | Companies; contacts with affiliations (role, one primary company); owners; tags; custom fields; notes; activity timeline; host links and host panels on a 360° page; CSV and vCard import with preview; duplicate hints and merging; CSV / vCard export; GDPR (consent per purpose, export, erasure, retention); trash with restore; plan-limit counters; SearchKit providers; audit events | always on | Built, in review (not tagged) |
| **B** pipeline | 0.2.0 | Pipelines with stages per tenant, deals (amount, currency, probability, expected close, owner, company, contacts), board by stage, won / lost with reason, next action with reminders (NotifyKit), activities (call, meeting, e-mail, task) on deals and contacts, calendar link (ICS); public JSON API `/crm/v1` (off by default, `If-Match` versions, `Idempotency-Key`); note attachments once StorageKit stores non-image files | `pipeline.enabled` | Planned |
| **C** quotes | 0.3.0 | Product / service catalogue, quotes with lines, taxes, discounts and versions, PDF with the tenant's brand (brand-contracts, closed Dompdf rendering), public accept link (signed token) and e-sign hook; an accepted quote wins the deal and calls a host hook (project / board from a template) | `quotes.enabled` (needs B) | Planned |
| **D** invoices | 0.4.0 | Invoices from quotes or host billable lines (hours), numbering per series, VeriFactu records (VerifactuBundle, after commit), SEPA mandates and direct-debit batches (SepaPaymentBundle), payment status, payment reminders | `invoices.enabled` (needs C) | Planned |
| **E** campaigns | 0.5.0 | Segments (tags, fields, activity), e-mail campaigns through MailKit with consent, suppression list, one-click unsubscribe, optional open / click tracking under tracking consent, templates | `campaigns.enabled` | Planned |
| **F** customer portal | 0.6.0 | A contact signs in by magic link and sees their quotes, invoices and the host panels the host allows (tickets, wiki pages, project status), permission-limited | `portal.enabled` | Planned |
| **G** reports | 0.7.0 → 1.0.0 | Pipeline value, forecast, win rate, revenue by client / host project, activity per person, CSV exports, host report hook, inactive-contact review | `reports.enabled` | Planned |

## Not planned

Telephony (CTI), social media inboxes, AI scoring, multi-step marketing journeys, an accounting ledger, payment card
processing, native apps.

## Phase A follow-ups

- A Messenger handler that runs large imports outside the request (0.1.0 runs them in requests and in
  `nowo:crm-kit:import:resume`).
- A link picker on the 360° page that uses `CrmHostObjectResolverInterface::search()` (0.1.0 asks for the host id).
