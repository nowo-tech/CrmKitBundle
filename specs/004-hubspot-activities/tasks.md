# Tasks: HubSpot activities import (0.12.0, Beacon spec 254 T004)

- [x] T004.1 Verify HubSpot's engagement endpoints and scopes (legacy v3: `crm.objects.contacts.read`; `2026-09`: any
      CRM read scope incl. granular `crm.objects.{notes,calls,meetings,tasks}.read`) — no new scope
- [x] T004.2 Domain: `HubSpotActivity`, scope activities, read models, `NextAction::revise()`
- [x] T004.3 Reader: `engagements()` with associations, `count()` per kind; fake HubSpot engagements
- [x] T004.4 `HubSpotText` (HTML ⇒ plain text, titles, times, minutes)
- [x] T004.5 `HubSpotEngagementWriter` (notes, calls, meetings, tasks ⇒ next actions; references, dedupe key, locks,
      access, add-only associations and attendees)
- [x] T004.6 Runner stages, start-step gating (`activities.enabled`, `pipeline.enabled`), skip row at run
- [x] T004.7 UI: start step offers the kinds; 7 locales
- [x] T004.8 Tests: unit (text, kinds, scope, cursor, reader), database (create, re-run, trashed / merged / erased,
      access attended and unattended, switches, `max_records`); 100% coverage
- [x] T004.9 Docs: CHANGELOG, UPGRADING, USAGE, CONFIGURATION, design, ROADMAP row, code inventory
- [ ] T004.10 Pre-tag REQ-SEC-004 audit (coordinator)
