# Feature specification: activities (A-1, 0.11.0)

**Feature branch**: `feat/0.11.0-activities`
**Created**: 2026-11-26
**Status**: Built (draft PR), pre-tag audit pending
**Source**: Beacon spec 254 "CRM HubSpot parity", block A-1, tasks T001 (activities) and T002 (host activity source);
CrmKit [ROADMAP](../../docs/ROADMAP.md#block-a--activities-and-templates-a-1-0110-a-2-0150), Q-254-1..16 (owner,
2026-11-23). Out of scope: T003 templates (A-2), T004 HubSpot import of notes, calls, meetings and tasks (HubSpot
part 4).

## Summary

A small team logs what happened with a customer: calls, meetings and e-mails, next to the notes of a company or a
contact. A contact's activity also shows on its companies and open deals. Each record page has an activity feed
filtered by kind and person, notes can be pinned, members can be @mentioned in notes and are notified, the host adds
its own items (tasks, time entries, mail, tickets) to the feed, meetings appear on TaskBoard's calendar and can be
downloaded as `.ics`. Everything is opt-in (`activities.enabled`), tenant-isolated and deny by default.

## User scenarios

### US-A1 - Log a call, a meeting or an e-mail (P1)

A member who may edit a company or contact opens "Log call", "Log meeting" or "Log e-mail" (a dialog loaded on
demand, or a plain page without JavaScript), fills the form and saves. Invalid values come back on their fields (422);
a stale edit says someone changed it (409 on the page, 422 in the dialog). Someone else's activity needs `manage` to
change or delete.

### US-A2 - See what happened (P1)

The record page shows the feed newest first with "Show older"; the filter narrows it to one kind (note, call,
meeting, e-mail, host item) and one person. A contact's activities also show on its companies and open deals, never
for a viewer who may not view the contact.

### US-A3 - Pin a note and mention a colleague (P2)

A member pins the important note (one pinned note per record by default). In a note, `@` offers the colleagues of
the same organisation who may view the record; saving notifies the newly mentioned ones (NotifyKit bell), without the
record's name or the note's text in the notification.

### US-A4 - Meetings in the calendar (P2)

Meetings a member logged or attends appear on TaskBoard's calendar (read-only, on records the member may view);
"Add to calendar (.ics)" downloads the meeting with a stable UID and a SEQUENCE that moves when it changes.

### US-A5 - Host items in the feed (T002, P2)

The host implements `CrmHostActivitySourceInterface` and returns the viewer's items of the record; the kit drops items
of another record or outside the page.

### US-A6 - Personal data (P1)

A contact's GDPR export lists its activities and the meetings it attends; erasure deletes them and its attendance;
retention deletes activities older than `timeline_years`; merges move them; a member's account anonymisation covers
activity authors and member attendees.

## Requirements

- **FR-ACT-001**: `activities.enabled` (off by default) maps three tables (`crm_engagement`, `crm_engagement_subject`,
  `crm_engagement_attendee`, mapping group `NowoCrmKitBundleActivities`), registers the services and routes; off =
  nothing of it exists. Configuration keys `activities.*` (strict tree).
- **FR-ACT-002**: `EngagementServiceInterface` logs, edits and deletes calls (direction, outcome from
  `call_outcomes`, duration 0–1440, summary ≤ 200, notes ≤ 10 000), meetings (title, `MeetingSlot` in an IANA zone,
  ≤ 7 days; `MeetingPlace` ≤ 200 or https; ≤ 50 attendees: tenant members and contacts the actor may view; notes;
  cancelled) and e-mails (direction, subject, `EmailExcerpt` ≤ 500). `edit` on the record, `manage` for someone else's;
  optimistic versions; the record locked in the write; all input errors at once per field.
- **FR-ACT-003**: fan-out when logged: a contact's activity to its companies and open deals, a company's to its open
  deals (subject rows; later edits do not change them).
- **FR-ACT-004**: `ActivityFeedInterface::feed()` of a company, contact or deal: notes, activities and host items,
  `TimelineFilter` (kinds, person), `TimelineWindow` keyset pages (time, id); an activity shows only while its primary
  record is viewable (batch decision); attendee contacts the viewer may not view are left out.
- **FR-ACT-005**: `PinnedNotesInterface::pin()` (`edit` on the record, version); `max_pinned` (default 1, 0 = no
  limit) unpins the oldest pinned notes in the same write under the record's lock.
- **FR-ACT-006**: @mentions: handles `u` + 24 hex of an HMAC (kernel secret, tenant, actor); candidates and names
  through `MentionDirectoryInterface` (members of the tenant who may view the record, `CrmRecordAudienceInterface`);
  the newly mentioned members (never the author, ≤ `max_per_note`) are announced after the commit (`NoteMentioned`) and
  handed to `CrmNotifierInterface` (NotifyKit bridge, type `crm.mention`, no record name or text). MilkdownEditor's
  field when installed.
- **FR-ACT-007**: `CrmHostActivitySourceInterface::items()` (`activities.host_source`); default none; the kit keeps only
  items of the asked record, inside the window, by the filtered person, at most the page.
- **FR-ACT-008**: `Bridge/TaskBoard/CrmMeetingCalendarSource` (TaskBoard >= 2.24, `taskboard_calendar`): the session
  actor's meetings (logged or attended, not cancelled) on viewable records, read-only, tenant key of the viewer only
  when it is the CRM's tenant (or none).
- **FR-ACT-009**: authorised `.ics` download (`view` on the record): `IcsInvite` (UID `<id>@<host>`, SEQUENCE,
  METHOD:PUBLISH, STATUS), RFC 5545 escaping and folding; no notes, no addresses. MailKit invites are a follow-up.
- **FR-ACT-010**: SearchKit group `crm_activities` (title only; primary record active and visible).
- **FR-ACT-011**: GDPR export, erasure, purge, retention (`timeline_years`), merges and actor anonymisation cover the
  activities, their subject rows and attendees.
- **FR-ACT-012**: audit entries `crm.activity.logged|edited|deleted`, `crm.note.pinned|unpinned`; timeline entries
  `call`, `meeting`, `email`, `activity_edited`, `activity_deleted`; events after the commit.
- **FR-ACT-013**: UI: the "Activities" card (openers, filter, feed, older page), the log / edit pages (UiKit remote
  dialog, FormKit, primary button first), delete, pin, `.ics`, the mention endpoints (JSON, private, no-store);
  7 locales.
- **FR-ACT-014**: REQ-RUNTIME-003: stateless services (worker mode, kernel not reset), writes in the house runner with
  the lost-savepoint pattern, two-session race tests for pins, activity edits and logging against an erasure.

## Success criteria

- 100 % PHP coverage of the new code; Deptrac / ARCH-001 clean; PHPStan max clean.
- With `activities.enabled: false` the container, schema and pages are unchanged.
- A viewer never sees an activity, a host item or a mention candidate of a record they may not view, nor anything of
  another tenant.

## Key entities

`Engagement` (a logged call, meeting or e-mail), `EngagementSubject` (a record it shows on), `EngagementAttendee` (a
meeting's member or contact).
