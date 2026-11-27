# Implementation plan: activities (A-1, 0.11.0)

**Spec**: [spec.md](spec.md)

## Architecture

- **Domain**: `ActivityKind` (+ `call`, `meeting`, `email`, `activity_edited`, `activity_deleted`, reserved
  `form_submitted`, `sms`), `ActivityDirection`, `FeedKind`; VOs `CallOutcomeKey`, `ActivityDuration`, `MeetingSlot`,
  `MeetingAttendee`, `MeetingPlace`, `EmailExcerpt`, `TimelineFilter`, `TimelineWindow`, `HostActivityItem`,
  `MentionRef`, `CrmNotification`, `IcsInvite`; entities under `Domain\Model\Engagement`; read models; ports
  `CrmHostActivitySourceInterface`, `CrmNotifierInterface`, `CrmRecordAudienceInterface`; services
  `EngagementServiceInterface`, `ActivityFeedInterface`, `MentionDirectoryInterface`, `PinnedNotesInterface`; events.
- **Application** (`Application\Engagement`): `EngagementService`, `ActivityFeed`, `Mentions`, `MentionNotifier`
  (after-commit listener), `EngagementHousekeeping` (purge, erase, merge, export, retention), `IcsWriter`; `NoteService`
  gains pins and mentions.
- **Infrastructure**: `DoctrineEngagementStore`, XML mappings in `doctrine-activities/`, default ports in
  `Symfony\Port`, `ActivityController`, `ActivityRecordBlock`, forms, routes, the `_activities` template.
- **Bridges**: `Bridge/TaskBoard/CrmMeetingCalendarSource`, `Bridge/NotifyKit/*`, `Bridge/SearchKit/CrmEngagementSearchDefinition`.
- **Concurrency**: every write runs in `WriteSupport::write()` (transaction-doctrine write unit, replay on deadlocks),
  locks the primary record `FOR UPDATE`; versions on `Engagement` and `Note`; `DoctrineTransactionRunner` rolls back to
  the entry level and closes the connection when that rollback fails (lost savepoint), then rethrows the original.

## Decisions taken in the build

- One table for the three kinds (nullable columns per kind) instead of three tables: one feed query, one keyset.
- Associations stored when logged (HubSpot's model), not computed at read time.
- Mention handles are keyed hashes (MilkdownEditor's handle grammar is 1–40 of `[a-zA-Z0-9_.-]`; actor ids may be
  e-mail addresses).
- ICS as an authorised download (`PUBLISH`), not a signed public link: no unauthenticated access to a meeting.
- The default audience follows `security.resource_scoping`; hosts with record listeners implement the port.

## Testing

Unit (values, writer, helpers, bridges, runner rollback), Database suite on SQLite / MySQL / MariaDB / PostgreSQL
(services, UI, races on two connections), SearchKit contract test on PHP >= 8.3.
