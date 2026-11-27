# Feature specification: HubSpot activities import (0.13.0)

**Feature branch**: `feat/hubspot-activities`
**Created**: 2026-11-27
**Status**: Built (draft PR), pre-tag audit pending
**Source**: Beacon spec 254 "CRM HubSpot parity", task T004 (FR-100 block A); CrmKit
[ROADMAP](../../docs/ROADMAP.md#block-a--activities-and-templates-a-1-0110-a-2-0150) A-1 row, design
[HUBSPOT_IMPORT.md](../../docs/design/HUBSPOT_IMPORT.md) (Q-H1..13 as recommended). Builds on
[002-activities](../002-activities/spec.md) (A-1, 0.11.0) and the H1 import (0.10.0).

## Summary

A team moving from HubSpot keeps its history: the notes, calls, meetings and tasks of the companies and contacts it
imports come along, as notes, logged activities and next actions, on the same records. A second run updates them and
never duplicates. Nothing is written to HubSpot.

## User scenarios

### US-H1 - Import the activities with the records (P1)

A CRM manager starts a HubSpot import, ticks contacts and the activities, confirms the mapping; the records are read
first, then the notes, calls, meetings and tasks, each on the first associated contact (else company) of the same
account. Calls and meetings also show on the other associated records; meeting contacts are attendees.

### US-H2 - Import the activities later (P2)

An import may read activities only: they attach to records an earlier run of the same account imported.

### US-H3 - Re-run (P1)

A second run updates the items it made (empty values never clear, associations only add, a done next action stays
done) and creates none twice (SC-007 of Beacon spec 254).

### US-H4 - Switched off (P2)

With `activities.enabled` off the start step offers no activities and refuses them; tasks also need
`pipeline.enabled`. A kind switched off after the start is skipped with one row saying why.

## Requirements

- **FR-HA-001** Kinds: notes ⇒ notes; calls, meetings ⇒ logged activities (`activities.enabled`); tasks ⇒ next actions
  on the built-in list (`pipeline.enabled` too), done when HubSpot's status is `COMPLETED`.
- **FR-HA-002** Read with fixed properties (data minimisation) and `associations=contacts,companies`, one request per
  page, at most 100 associated ids of each kind; no new scope (`crm.objects.contacts.read` covers the endpoints;
  granular read scopes accepted).
- **FR-HA-003** Idempotent: an external reference per item (`note`, `call`, `meeting`, `task`), a next action's dedupe
  key `sha256("hubspot|<account>|task|<id>")`; a racing insert replays the page once.
- **FR-HA-004** Tenant-scoped: every reference, record and item carries the tenant; associations resolve only through
  references of the same tenant and account.
- **FR-HA-005** Authorship as H1: HubSpot owner ⇒ actor by e-mail (author of notes and activities, assignee of next
  actions); unmatched ⇒ none; a re-run changes an assignee only when a person runs the page.
- **FR-HA-006** HTML bodies ⇒ plain text (scripts and styles dropped, entities decoded, control and format characters
  removed, at most 10 000 characters); titles one line, at most 200; times UTC, 1971–2100.
- **FR-HA-007** Access as H1: attended pages need `edit` on the primary record (the first one the person may edit);
  unattended pages under resource scoping wait for a person on someone else's record.
- **FR-HA-008** Bounded memory and calls: pages of `import.hubspot.page_size`, one unit per page, `max_records` per
  kind, the same pauses, backoff and resume.
- **FR-HA-009** No per-item events or timeline entries; `ImportCompleted` and `crm.import.completed` cover the run.

## Success criteria

- **SC-HA-001** A second run of every kind creates 0 notes, activities or next actions.
- **SC-HA-002** No body text in `crm_import_row` or the run cursor.
- **SC-HA-003** 100% line coverage of the new code; PHPStan max; CS.

## Out of scope

E-mail engagements (block B), deal associations (until the H2 deals import), HubSpot call dispositions (outcome keys),
task bodies and priorities, attachments, archived engagements.
