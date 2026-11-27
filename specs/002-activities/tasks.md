# Tasks: activities (A-1, 0.11.0)

- [x] T001.1 Domain values, enums, entities, ports and service interfaces
- [x] T001.2 XML mappings (three tables), configuration tree `activities.*`, prepend, services, routes
- [x] T001.3 `EngagementService` (log / edit / delete, validation, fan-out, permissions, locks, audit, events)
- [x] T001.4 `ActivityFeed` (filter, keyset pages, visibility, attendees, meetings for the calendar)
- [x] T001.5 Pinned notes and the pin limit in `NoteService`
- [x] T001.6 @mentions (`Mentions`, MilkdownEditor field, `NoteMentioned`, `MentionNotifier`, NotifyKit bridge)
- [x] T001.7 GDPR export / erasure, purge, retention, merges, actor anonymisation (`EngagementHousekeeping`)
- [x] T001.8 TaskBoard calendar source, `.ics` download (`IcsInvite`, `IcsWriter`)
- [x] T001.9 SearchKit group `crm_activities`
- [x] T001.10 UI: activity card, log / edit dialogs, filter, delete, pin, mention endpoints; 7 locales
- [x] T001.11 REQ-RUNTIME-003: lost-savepoint pattern in the runner, two-session race tests
- [x] T002 `CrmHostActivitySourceInterface` with the default and the kit's filtering
- [x] Docs: CHANGELOG, UPGRADING "To 0.11.0", CONFIGURATION, USAGE, ROADMAP, code inventory
- [ ] Follow-up (MailKit 2.3): ICS invite part on outgoing mail, `CrmMailSenderInterface`
- [x] Follow-up (HubSpot part 4): import of notes, calls, meetings and tasks (`specs/004-hubspot-activities/`, 0.12.0)
