# Feature specification: e-mail templates (A-2, 0.13.0)

**Feature branch**: `feat/0.13.0-templates`
**Created**: 2026-11-27
**Status**: Built (draft PR), pre-tag audit pending
**Source**: Beacon spec 254 "CRM HubSpot parity", block A-2, task T003 (FR-015, FR-016; spec 248 FR-006 / FR-007);
CrmKit [ROADMAP](../../docs/ROADMAP.md#block-a--activities-and-templates-a-1-0110-a-2-0130); MailKit 2.7 stored
templates (contract C1–C12); owner D-20261127-02 (0.13.0 for Beacon v1.182). Out of scope: HubSpot e-mail import
(block B), spec 248's full composer (attachments, cc, company / deal pages, the person's mailbox: Beacon).

## Summary

Members keep personal e-mail templates and snippets and use the organisation's; a commercial template carries the
consent purpose its recipients must have granted. On a contact's page *Write e-mail* inserts a template or a snippet
with the contact's, company's, deal's and the writer's data (closed placeholders, never code), sends the message and
logs it as an `email` activity. Opt-in (`templates.enabled`), tenant-isolated, deny by default.

## User scenarios

### US-T1 - Keep templates (P1)

A member creates a template (key, name, language, subject, text with `{{ contact.first_name }}`-style placeholders,
consent: a purpose or "service message") or a snippet on the Templates page; personal by default, for the organisation
with `manage`. Errors come back on their fields (422); a stale edit is a conflict.

### US-T2 - Write e-mail (P1)

On a contact's page "Write e-mail" opens a dialog (loaded on demand). Insert template fills the subject and the text
rendered for the contact; Insert snippet appends; placeholders without a visible value stay as text and are listed.
Send delivers the message and logs an `email` activity on the contact (shown on its companies and open deals).

### US-T3 - Consent is respected (P1)

Sending from a commercial template to a contact without a granted decision for its purpose is refused with the
reason; a service template or a message written by hand needs no consent; an unclassified template cannot be sent.

## Requirements

- **FR-T1** `templates.enabled` (default false) needs `activities.enabled` and MailKit >= 2.7 (optional dependency);
  with it off no table, service, route or provider exists.
- **FR-T2** Templates and snippets are MailKit's rows; every call acts as the CRM's tenant and person; personal rows
  only for their owner; shared rows used with `edit`, managed with `manage` (`nowo_crm_kit.template_permission`).
- **FR-T3** Providers `contact`, `company` (the contact's primary company), `deal` (chosen in *Write e-mail*,
  `amount` only with `amounts_view`) and `me`: only for the exact CRM tenant and person, only records the person may
  view, only active records, only core fields; nothing else resolves.
- **FR-T4** Consent classification per template id (`crm_template_purpose`), written with every save in the CRM,
  deleted with the template (`TemplateDeletedEvent`); unclassified templates are not offered and cannot be sent.
- **FR-T5** *Write e-mail*: `edit` on the contact; server-side inserts; Send checks (address, consent of the last
  inserted template, per-person rate limit, one send per form) under the contact's lock, sends outside any
  transaction through `CrmMailSenderInterface`, logs an `email` activity (subject, excerpt of 500 characters) and
  dispatches `CrmMailSent`.
- **FR-T6** Pages: the Templates list (area tab), create / edit dialogs on demand, delete with a confirm; Send first
  in the dialog; Enter in a field never sends or inserts; seven locales.

## Success criteria

- 100 % line coverage, PHPStan max, CS, Deptrac (MailKit only in `Bridge/MailKit`), 7 locales in parity.
- Race tests: a concurrent first classification is a conflict; a reused send key sends once.
