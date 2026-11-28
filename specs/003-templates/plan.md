# Plan: e-mail templates (A-2, 0.13.0)

## Technical context

PHP 8.2–8.5, Symfony 7.4 / 8, Doctrine ORM 3 on the CRM's private entity manager, MailKit 2.7 (optional: only
`src/Bridge/MailKit` uses it; the tests run on the real `nowo-tech/mail-kit-bundle` ^2.7 from require-dev, with an
in-memory library for most of them and MailKit's own services in `RealMailKitTest`), UiKit remote dialogs, FormKit
forms.

## Design

| Layer | Pieces |
| --- | --- |
| Domain | `EmailTemplateKind`, `TemplateRef`, `CrmOutgoingMail`, `CrmSentMailRef`, `CrmMailSenderInterface`, `CrmMailSent`, `TemplatePurpose` (entity), `EmailTemplateView`, `ComposedText`, `MailOutcome`, `EmailTemplateInput`, `EmailTemplatesInterface`, `MailDraft`, `MailComposerInterface`, `ConflictException::MAIL_SENT` |
| Application | `Templates\Port\TemplateMailStoreInterface` |
| Infrastructure | `DoctrineTemplateMailStore` (classification rows; send window and send keys in `crm_counter`), mapping `doctrine-templates` |
| Bridge | `Bridge/MailKit`: `CrmTemplateActors`, `CrmTemplatePermission`, `CrmPlaceholders` + `Contact` / `Company` / `Deal` / `MePlaceholders`, `RecordPlaceholderValues`, `TemplatePurposeCleaner`, `MailKitEmailTemplates`, `MailKitMailComposer`, `MailKitCrmMailSender` |
| UI | `TemplateController`, `EmailTemplateType`, `WriteEmailType`, `templates/*.html.twig`, the opener in `ActivityRecordBlock`, the area tab |
| DI | `templates.*` configuration, `ServiceDefinitions::templates()` / `templatesUi()`, `CrmPortsPass::templates()` |

## Decisions

- No Twig and no evaluation anywhere: MailKit's closed registry renders; the CRM sends text only.
- The checks and the send are split (REQ-RUNTIME-003): a write unit may be replayed, a mail may not.
- Deny by default: unclassified templates cannot be sent; MailKit actors other than the call's are refused.
