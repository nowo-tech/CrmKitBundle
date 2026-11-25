# Code inventory - 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/crm-kit-bundle`  
**Last audited**: 2026-11-24 (0.10.1: HubSpot import, secret box, Messenger runs, host object picker and UI files mapped)

Scope: every file under `src/`. Audit command: `find src -type f | wc -l` → **506** (428 PHP files, 26 Doctrine XML
mappings, 36 Twig views, 7 translation files, assets and CSS). Sections headed `0.3.0-0.6.0` were added by the
2026-11-22 refresh, sections headed `0.7.0-0.10.x` by the 2026-11-24 refresh.

Requirement ids are those of [`spec.md`](spec.md#requirements).

### Bundle (1)

| Source file | Requirement IDs |
| --- | --- |
| `src/NowoCrmKitBundle.php` | FR-CONFIG-001, FR-EM-001 |

### Domain: models (17)

| Source file | Requirement IDs |
| --- | --- |
| `src/Domain/Model/Activity.php` | FR-TL-001 |
| `src/Domain/Model/Affiliation.php` | FR-AFF-001 |
| `src/Domain/Model/Company.php` | FR-REC-001 |
| `src/Domain/Model/Consent.php` | FR-GDPR-001 |
| `src/Domain/Model/Contact.php` | FR-REC-001, FR-REC-002, FR-GDPR-002 |
| `src/Domain/Model/Counter.php` | FR-LIMIT-001 |
| `src/Domain/Model/CrmRecord.php` | FR-REC-001 |
| `src/Domain/Model/CrmSettings.php` | FR-SET-001 |
| `src/Domain/Model/FieldDefinition.php` | FR-FIELD-001 |
| `src/Domain/Model/HostLink.php` | FR-LINK-001 |
| `src/Domain/Model/Import.php` | FR-IMP-001 |
| `src/Domain/Model/ImportRow.php` | FR-IMP-001 |
| `src/Domain/Model/MergeRecord.php` | FR-MERGE-001 |
| `src/Domain/Model/Note.php` | FR-NOTE-001 |
| `src/Domain/Model/RecordFields.php` | FR-REC-001 |
| `src/Domain/Model/Tag.php` | FR-TAG-001 |
| `src/Domain/Model/Tagging.php` | FR-TAG-001 |

### Domain: enums (11)

| Source file | Requirement IDs |
| --- | --- |
| `src/Domain/Enum/ActivityKind.php` | FR-TL-001 |
| `src/Domain/Enum/ConsentSource.php` | FR-GDPR-001 |
| `src/Domain/Enum/CrmPermission.php` | FR-GATE-001 |
| `src/Domain/Enum/CrmResource.php` | FR-LIMIT-001 |
| `src/Domain/Enum/DuplicatePolicy.php` | FR-IMP-001 |
| `src/Domain/Enum/FieldType.php` | FR-FIELD-001 |
| `src/Domain/Enum/ImportFormat.php` | FR-IMP-001 |
| `src/Domain/Enum/ImportStatus.php` | FR-IMP-001 |
| `src/Domain/Enum/RecordStatus.php` | FR-REC-001 |
| `src/Domain/Enum/RowOutcome.php` | FR-IMP-001 |
| `src/Domain/Enum/SubjectType.php` | FR-REC-001 |

### Domain: value objects (17)

| Source file | Requirement IDs |
| --- | --- |
| `src/Domain/Value/ActorProfile.php` | FR-PORT-001 |
| `src/Domain/Value/ActorRef.php` | FR-SEC-006 |
| `src/Domain/Value/AuditEntry.php` | FR-EVT-001 |
| `src/Domain/Value/ConsentPurpose.php` | FR-GDPR-001 |
| `src/Domain/Value/EmailAddress.php` | FR-REC-002 |
| `src/Domain/Value/ExactId.php` | FR-SEC-006 |
| `src/Domain/Value/FieldKey.php` | FR-FIELD-001 |
| `src/Domain/Value/HostObjectRef.php` | FR-LINK-001, FR-SEC-006 |
| `src/Domain/Value/HostObjectView.php` | FR-LINK-001 |
| `src/Domain/Value/OpaqueRefTrait.php` | FR-SEC-006 |
| `src/Domain/Value/Panel.php` | FR-PANEL-001 |
| `src/Domain/Value/PanelContext.php` | FR-PANEL-001 |
| `src/Domain/Value/PanelItem.php` | FR-PANEL-001 |
| `src/Domain/Value/RecordId.php` | FR-SEC-006 |
| `src/Domain/Value/SubjectRef.php` | FR-GATE-001 |
| `src/Domain/Value/TagName.php` | FR-TAG-001 |
| `src/Domain/Value/TenantRef.php` | FR-SEC-006 |

### Domain: ports (9)

| Source file | Requirement IDs |
| --- | --- |
| `src/Domain/Port/CrmAccessCheckerInterface.php` | FR-PORT-001, FR-GATE-001 |
| `src/Domain/Port/CrmActorDirectoryInterface.php` | FR-PORT-001 |
| `src/Domain/Port/CrmAuditSinkInterface.php` | FR-PORT-001, FR-EVT-001 |
| `src/Domain/Port/CrmFeatureGateInterface.php` | FR-PORT-001, FR-GATE-001 |
| `src/Domain/Port/CrmHostObjectResolverInterface.php` | FR-PORT-001, FR-LINK-001 |
| `src/Domain/Port/CrmPanelProviderInterface.php` | FR-PORT-001, FR-PANEL-001 |
| `src/Domain/Port/CrmPlanLimitsInterface.php` | FR-PORT-001, FR-LIMIT-001 |
| `src/Domain/Port/CrmTenantResolverInterface.php` | FR-PORT-001, FR-GATE-001 |
| `src/Domain/Port/IdGeneratorInterface.php` | FR-PORT-001 |

### Domain: public services (15)

| Source file | Requirement IDs |
| --- | --- |
| `src/Domain/Service/ConsentServiceInterface.php` | FR-GDPR-001 |
| `src/Domain/Service/CrmExporterInterface.php` | FR-EXP-001 |
| `src/Domain/Service/CrmPersonalDataInterface.php` | FR-GDPR-002 |
| `src/Domain/Service/CrmReaderInterface.php` | FR-READ-001 |
| `src/Domain/Service/CrmSettingsInterface.php` | FR-SET-001 |
| `src/Domain/Service/FieldServiceInterface.php` | FR-FIELD-001 |
| `src/Domain/Service/HostLinkServiceInterface.php` | FR-LINK-001 |
| `src/Domain/Service/ImportServiceInterface.php` | FR-IMP-001 |
| `src/Domain/Service/ListQuery.php` | FR-READ-001 |
| `src/Domain/Service/MergeServiceInterface.php` | FR-MERGE-001 |
| `src/Domain/Service/NoteServiceInterface.php` | FR-NOTE-001 |
| `src/Domain/Service/RecordInput.php` | FR-REC-001 |
| `src/Domain/Service/RecordServiceInterface.php` | FR-REC-001, FR-AFF-001 |
| `src/Domain/Service/TagServiceInterface.php` | FR-TAG-001 |
| `src/Domain/Service/TimelineInterface.php` | FR-TL-001 |

### Domain: read models (13)

| Source file | Requirement IDs |
| --- | --- |
| `src/Domain/Read/ActivityView.php` | FR-TL-001 |
| `src/Domain/Read/AffiliationView.php` | FR-AFF-001 |
| `src/Domain/Read/ConsentView.php` | FR-GDPR-001 |
| `src/Domain/Read/FieldView.php` | FR-FIELD-001 |
| `src/Domain/Read/ImportRowView.php` | FR-IMP-001 |
| `src/Domain/Read/ImportView.php` | FR-IMP-001 |
| `src/Domain/Read/LinkView.php` | FR-LINK-001 |
| `src/Domain/Read/ListResult.php` | FR-READ-001 |
| `src/Domain/Read/MergeField.php` | FR-MERGE-001 |
| `src/Domain/Read/MergePreview.php` | FR-MERGE-001 |
| `src/Domain/Read/NoteView.php` | FR-NOTE-001 |
| `src/Domain/Read/RecordView.php` | FR-READ-001 |
| `src/Domain/Read/TagView.php` | FR-TAG-001 |

### Domain: events (14)

| Source file | Requirement IDs |
| --- | --- |
| `src/Domain/Event/ConsentRecorded.php` | FR-EVT-001 |
| `src/Domain/Event/ContactErased.php` | FR-EVT-001 |
| `src/Domain/Event/CrmEvent.php` | FR-EVT-001 |
| `src/Domain/Event/HostLinkAdded.php` | FR-EVT-001 |
| `src/Domain/Event/HostLinkRemoved.php` | FR-EVT-001 |
| `src/Domain/Event/ImportCompleted.php` | FR-EVT-001 |
| `src/Domain/Event/NoteAdded.php` | FR-EVT-001 |
| `src/Domain/Event/RecordCreated.php` | FR-EVT-001 |
| `src/Domain/Event/RecordPurged.php` | FR-EVT-001 |
| `src/Domain/Event/RecordRestored.php` | FR-EVT-001 |
| `src/Domain/Event/RecordTrashed.php` | FR-EVT-001 |
| `src/Domain/Event/RecordUpdated.php` | FR-EVT-001 |
| `src/Domain/Event/RecordsMerged.php` | FR-EVT-001 |
| `src/Domain/Event/TagsChanged.php` | FR-EVT-001 |

### Domain: exceptions (5)

| Source file | Requirement IDs |
| --- | --- |
| `src/Domain/Exception/AccessDeniedException.php` | FR-GATE-001 |
| `src/Domain/Exception/ConflictException.php` | FR-GATE-001, FR-CONC-001 |
| `src/Domain/Exception/CrmException.php` | FR-GATE-001 |
| `src/Domain/Exception/InvalidInputException.php` | FR-GATE-001 |
| `src/Domain/Exception/NotAvailableException.php` | FR-GATE-001 |

### Domain: import parsers (4)

| Source file | Requirement IDs |
| --- | --- |
| `src/Domain/Import/CsvParser.php` | FR-IMP-001 |
| `src/Domain/Import/ImportFileException.php` | FR-IMP-001 |
| `src/Domain/Import/ParsedFile.php` | FR-IMP-001 |
| `src/Domain/Import/VcardParser.php` | FR-IMP-001 |

### Application: services and helpers (21)

| Source file | Requirement IDs |
| --- | --- |
| `src/Application/ConsentService.php` | FR-GDPR-001, FR-EVT-001 |
| `src/Application/CrmAccess.php` | FR-GATE-001 |
| `src/Application/CrmContext.php` | FR-GATE-001, FR-WORKER-001 |
| `src/Application/CrmExporter.php` | FR-EXP-001 |
| `src/Application/CrmPersonalData.php` | FR-GDPR-002 |
| `src/Application/CrmReader.php` | FR-READ-001, FR-LINK-001, FR-PANEL-001, FR-SEARCH-001 |
| `src/Application/FieldService.php` | FR-FIELD-001 |
| `src/Application/HostLinkService.php` | FR-LINK-001, FR-TL-001 |
| `src/Application/ImportMapping.php` | FR-IMP-001 |
| `src/Application/ImportService.php` | FR-IMP-001, FR-CONC-001 |
| `src/Application/MergeService.php` | FR-MERGE-001, FR-CONC-001 |
| `src/Application/NoteService.php` | FR-NOTE-001 |
| `src/Application/PlanCounter.php` | FR-LIMIT-001 |
| `src/Application/RecordPurger.php` | FR-REC-001, FR-GDPR-003 |
| `src/Application/RecordService.php` | FR-REC-001, FR-REC-002, FR-AFF-001 |
| `src/Application/RecordValidator.php` | FR-REC-001, FR-FIELD-001 |
| `src/Application/RecordViews.php` | FR-READ-001 |
| `src/Application/SettingsService.php` | FR-SET-001 |
| `src/Application/TagService.php` | FR-TAG-001 |
| `src/Application/Taggings.php` | FR-TAG-001 |
| `src/Application/WriteSupport.php` | FR-GATE-001, FR-CONC-001, FR-EVT-001, FR-TL-001 |

### Application: persistence port and shared units (5)

| Source file | Requirement IDs |
| --- | --- |
| `src/Application/Port/CrmStoreInterface.php` | FR-ARCH-001 |
| `src/Application/Shared/DuplicateRowException.php` | FR-CONC-001 |
| `src/Application/Shared/Effects.php` | FR-EVT-001, FR-WORKER-001 |
| `src/Application/Shared/StaleRowException.php` | FR-CONC-001 |
| `src/Application/Shared/TransactionRunnerInterface.php` | FR-CONC-001 |

### Infrastructure: Doctrine (5)

| Source file | Requirement IDs |
| --- | --- |
| `src/Infrastructure/Doctrine/BinaryCollation.php` | FR-SEC-006 |
| `src/Infrastructure/Doctrine/CrmMetadataListener.php` | FR-SEC-006, FR-EM-001 |
| `src/Infrastructure/Doctrine/DoctrineCrmStore.php` | FR-ARCH-001, FR-SEC-006, FR-LIMIT-001, FR-IMP-001 |
| `src/Infrastructure/Doctrine/DoctrineTransactionRunner.php` | FR-CONC-001, FR-EM-001, FR-WORKER-001 |
| `src/Infrastructure/Doctrine/Type/UtcDateTimeImmutableType.php` | FR-EM-001 |

### Infrastructure: Symfony DI (5)

| Source file | Requirement IDs |
| --- | --- |
| `src/Infrastructure/Symfony/DependencyInjection/Compiler/CrmPortsPass.php` | FR-PORT-001, FR-CONC-001, FR-SEARCH-001 |
| `src/Infrastructure/Symfony/DependencyInjection/Compiler/PrivateEntityManagerPass.php` | FR-EM-001 |
| `src/Infrastructure/Symfony/DependencyInjection/ConfigurationTree.php` | FR-CONFIG-001 |
| `src/Infrastructure/Symfony/DependencyInjection/ParameterFlattener.php` | FR-CONFIG-001 |
| `src/Infrastructure/Symfony/DependencyInjection/ServiceDefinitions.php` | FR-CONFIG-001 |

### Infrastructure: port defaults (6)

| Source file | Requirement IDs |
| --- | --- |
| `src/Infrastructure/Symfony/Port/ConfiguredFeatureGate.php` | FR-PORT-001 |
| `src/Infrastructure/Symfony/Port/ConfiguredPlanLimits.php` | FR-PORT-001 |
| `src/Infrastructure/Symfony/Port/PsrLogAuditSink.php` | FR-PORT-001 |
| `src/Infrastructure/Symfony/Port/SecurityActorDirectory.php` | FR-PORT-001 |
| `src/Infrastructure/Symfony/Port/SingleTenantResolver.php` | FR-PORT-001 |
| `src/Infrastructure/Symfony/Port/SymfonyUuidGenerator.php` | FR-PORT-001 |

### Infrastructure: HTTP listeners (5)

| Source file | Requirement IDs |
| --- | --- |
| `src/Infrastructure/Symfony/Http/ConflictFallbackListener.php` | FR-CONC-001 |
| `src/Infrastructure/Symfony/Http/CrmConflictFormatter.php` | FR-CONC-001 |
| `src/Infrastructure/Symfony/Http/DomainExceptionListener.php` | FR-GATE-001, FR-CONC-001 |
| `src/Infrastructure/Symfony/Http/EntityManagerHygieneListener.php` | FR-WORKER-001 |
| `src/Infrastructure/Symfony/Http/ImportDialog.php` (0.4.0) | FR-UI-002 |
| `src/Infrastructure/Symfony/Http/ImportDialogPageResolver.php` (0.10.3) | FR-UI-002 |

### Infrastructure: console (3)

| Source file | Requirement IDs |
| --- | --- |
| `src/Infrastructure/Symfony/Command/CountersRebuildCommand.php` | FR-LIMIT-001 |
| `src/Infrastructure/Symfony/Command/ImportResumeCommand.php` | FR-IMP-001 |
| `src/Infrastructure/Symfony/Command/PurgeCommand.php` | FR-GDPR-003 |

### Infrastructure: UI (routing, controllers, forms) (23)

| Source file | Requirement IDs |
| --- | --- |
| `src/Infrastructure/Symfony/Controller/AdminController.php` | FR-UI-001 |
| `src/Infrastructure/Symfony/Controller/ImportController.php` | FR-UI-001 |
| `src/Infrastructure/Symfony/Controller/RecordController.php` | FR-UI-001 |
| `src/Infrastructure/Symfony/Controller/RecordForms.php` | FR-UI-001 |
| `src/Infrastructure/Symfony/Controller/RecordPage.php` | FR-UI-001 |
| `src/Infrastructure/Symfony/Controller/RecordPartsController.php` | FR-UI-001 |
| `src/Infrastructure/Symfony/Controller/UiSupport.php` | FR-UI-001 |
| `src/Infrastructure/Symfony/Form/ActionType.php` | FR-UI-001 |
| `src/Infrastructure/Symfony/Form/AffiliationType.php` | FR-UI-001 |
| `src/Infrastructure/Symfony/Form/ConsentType.php` | FR-UI-001 |
| `src/Infrastructure/Symfony/Form/FieldDefinitionType.php` | FR-UI-001 |
| `src/Infrastructure/Symfony/Form/FilterType.php` | FR-UI-001 |
| `src/Infrastructure/Symfony/Form/HostLinkType.php` | FR-UI-001 |
| `src/Infrastructure/Symfony/Form/ImportConfirmType.php` | FR-UI-001 |
| `src/Infrastructure/Symfony/Form/ImportUploadType.php` | FR-UI-001 |
| `src/Infrastructure/Symfony/Form/MergePickType.php` | FR-UI-001 |
| `src/Infrastructure/Symfony/Form/MergeType.php` | FR-UI-001 |
| `src/Infrastructure/Symfony/Form/NoteType.php` | FR-UI-001 |
| `src/Infrastructure/Symfony/Form/RecordType.php` | FR-UI-001 |
| `src/Infrastructure/Symfony/Form/SettingsType.php` | FR-UI-001 |
| `src/Infrastructure/Symfony/Form/TagType.php` | FR-UI-001 |
| `src/Infrastructure/Symfony/Form/TagsAssignType.php` | FR-UI-001 |
| `src/Infrastructure/Symfony/Routing/UiRouteLoader.php` | FR-UI-001 |

### Resources: Doctrine XML mapping (16)

| Source file | Requirement IDs |
| --- | --- |
| `src/Resources/config/doctrine/Activity.orm.xml` | FR-EM-001, FR-SEC-006 |
| `src/Resources/config/doctrine/Affiliation.orm.xml` | FR-EM-001, FR-SEC-006 |
| `src/Resources/config/doctrine/Company.orm.xml` | FR-EM-001, FR-SEC-006 |
| `src/Resources/config/doctrine/Consent.orm.xml` | FR-EM-001, FR-SEC-006 |
| `src/Resources/config/doctrine/Contact.orm.xml` | FR-EM-001, FR-SEC-006 |
| `src/Resources/config/doctrine/Counter.orm.xml` | FR-EM-001, FR-SEC-006 |
| `src/Resources/config/doctrine/CrmRecord.orm.xml` | FR-EM-001, FR-SEC-006 |
| `src/Resources/config/doctrine/CrmSettings.orm.xml` | FR-EM-001, FR-SEC-006 |
| `src/Resources/config/doctrine/FieldDefinition.orm.xml` | FR-EM-001, FR-SEC-006 |
| `src/Resources/config/doctrine/HostLink.orm.xml` | FR-EM-001, FR-SEC-006 |
| `src/Resources/config/doctrine/Import.orm.xml` | FR-EM-001, FR-SEC-006 |
| `src/Resources/config/doctrine/ImportRow.orm.xml` | FR-EM-001, FR-SEC-006 |
| `src/Resources/config/doctrine/MergeRecord.orm.xml` | FR-EM-001, FR-SEC-006 |
| `src/Resources/config/doctrine/Note.orm.xml` | FR-EM-001, FR-SEC-006 |
| `src/Resources/config/doctrine/Tag.orm.xml` | FR-EM-001, FR-SEC-006 |
| `src/Resources/config/doctrine/Tagging.orm.xml` | FR-EM-001, FR-SEC-006 |

### Resources: Twig views (22)

| Source file | Requirement IDs |
| --- | --- |
| `src/Resources/views/_form.html.twig` | FR-UI-001 |
| `src/Resources/views/admin/field.html.twig` | FR-UI-001 |
| `src/Resources/views/admin/fields.html.twig` | FR-UI-001 |
| `src/Resources/views/admin/settings.html.twig` | FR-UI-001 |
| `src/Resources/views/admin/tag.html.twig` | FR-UI-001 |
| `src/Resources/views/admin/tags.html.twig` | FR-UI-001 |
| `src/Resources/views/import/_dialog_fields.html.twig` (0.4.0) | FR-UI-002 |
| `src/Resources/views/import/_dialog_shell.html.twig` (0.4.0) | FR-UI-002 |
| `src/Resources/views/import/_rows.html.twig` (0.4.0) | FR-UI-001, FR-UI-002 |
| `src/Resources/views/import/dialog.html.twig` (0.4.0) | FR-UI-002 |
| `src/Resources/views/import/show.html.twig` | FR-UI-001 |
| `src/Resources/views/import/upload.html.twig` | FR-UI-001 |
| `src/Resources/views/record/form.html.twig` | FR-UI-001 |
| `src/Resources/views/record/list.html.twig` | FR-UI-001 |
| `src/Resources/views/record/merge.html.twig` | FR-UI-001 |
| `src/Resources/views/record/merge_pick.html.twig` | FR-UI-001 |
| `src/Resources/views/record/show.html.twig` | FR-UI-001 |

### Resources: browser assets (0.4.0)

| Source file | Requirement IDs |
| --- | --- |
| `src/Resources/assets/README.md` | FR-UI-002 |
| `src/Resources/assets/src/iife.ts` | FR-UI-002 |
| `src/Resources/assets/src/import-dialog.ts` | FR-UI-002 |
| `src/Resources/assets/src/logger.ts` (0.10.1, the "script loaded" line) | FR-UI-002 |
| `src/Resources/assets/src/script-banner.ts` (0.10.1, once per page) | FR-UI-002 |
| `src/Resources/assets/tests/import-dialog.test.ts` | FR-UI-002 |
| `src/Resources/assets/tests/script-banner.test.ts` | FR-UI-002 |
| `src/Resources/public/js/nowo-crm-import.js` (Vite build of the sources above) | FR-UI-002 |

### Resources: translations (7)

| Source file | Requirement IDs |
| --- | --- |
| `src/Resources/translations/NowoCrmKitBundle.de.yaml` | FR-I18N-001 |
| `src/Resources/translations/NowoCrmKitBundle.en.yaml` | FR-I18N-001 |
| `src/Resources/translations/NowoCrmKitBundle.es.yaml` | FR-I18N-001 |
| `src/Resources/translations/NowoCrmKitBundle.fr.yaml` | FR-I18N-001 |
| `src/Resources/translations/NowoCrmKitBundle.it.yaml` | FR-I18N-001 |
| `src/Resources/translations/NowoCrmKitBundle.nl.yaml` | FR-I18N-001 |
| `src/Resources/translations/NowoCrmKitBundle.pt.yaml` | FR-I18N-001 |

### 0.3.0-0.6.0: API operations and inputs (52)

| Source file | Requirement IDs |
| --- | --- |
| `src/Api/CrmApiCapability.php` | FR-API-001 |
| `src/Api/CrmApiCode.php` | FR-API-001 |
| `src/Api/CrmApiGrant.php` | FR-API-001 |
| `src/Api/CrmApiLevel.php` | FR-API-001 |
| `src/Api/CrmApiOperation.php` | FR-API-001 |
| `src/Api/CrmApiPrincipal.php` | FR-API-001 |
| `src/Api/CrmApiReach.php` | FR-API-001 |
| `src/Api/CrmApiRefusal.php` | FR-API-001 |
| `src/Api/Input/CrmApiAffiliationSpec.php` | FR-API-001 |
| `src/Api/Input/CrmApiCompanyLink.php` | FR-API-001 |
| `src/Api/Input/CrmApiCursor.php` | FR-API-001 |
| `src/Api/Input/CrmApiCustom.php` | FR-API-001 |
| `src/Api/Input/CrmApiDealChanges.php` | FR-API-001 |
| `src/Api/Input/CrmApiDealDraft.php` | FR-API-001 |
| `src/Api/Input/CrmApiDealFields.php` | FR-API-001 |
| `src/Api/Input/CrmApiDealQuery.php` | FR-API-001 |
| `src/Api/Input/CrmApiFields.php` | FR-API-001 |
| `src/Api/Input/CrmApiFunnelStatsQuery.php` | FR-API-001 |
| `src/Api/Input/CrmApiInput.php` | FR-API-001 |
| `src/Api/Input/CrmApiNextActionDraft.php` | FR-API-001 |
| `src/Api/Input/CrmApiNoteDraft.php` | FR-API-001 |
| `src/Api/Input/CrmApiOwner.php` | FR-API-001 |
| `src/Api/Input/CrmApiPageRequest.php` | FR-API-001 |
| `src/Api/Input/CrmApiRecordChanges.php` | FR-API-001 |
| `src/Api/Input/CrmApiRecordDraft.php` | FR-API-001 |
| `src/Api/Input/CrmApiSearchQuery.php` | FR-API-001 |
| `src/Api/Input/CrmApiSort.php` | FR-API-001 |
| `src/Api/Input/CrmApiStageFilter.php` | FR-API-001 |
| `src/Api/Input/CrmApiStageMove.php` | FR-API-001 |
| `src/Api/Input/CrmApiTagRef.php` | FR-API-001 |
| `src/Api/Input/RecordVersion.php` | FR-API-001 |
| `src/Api/Operation/CrmApiCall.php` | FR-API-001 |
| `src/Api/Operation/CrmApiCursorCodec.php` | FR-API-001 |
| `src/Api/Operation/CrmApiDealOperations.php` | FR-API-001 |
| `src/Api/Operation/CrmApiDuplicate.php` | FR-API-001 |
| `src/Api/Operation/CrmApiErrors.php` | FR-API-001 |
| `src/Api/Operation/CrmApiGuard.php` | FR-API-001 |
| `src/Api/Operation/CrmApiNextActionOperations.php` | FR-API-001 |
| `src/Api/Operation/CrmApiOperations.php` | FR-API-001 |
| `src/Api/Operation/CrmApiPipeline.php` | FR-API-001 |
| `src/Api/Operation/CrmApiReadOperations.php` | FR-API-001 |
| `src/Api/Operation/CrmApiRecordOperations.php` | FR-API-001 |
| `src/Api/Operation/CrmApiRecords.php` | FR-API-001 |
| `src/Api/Operation/CrmApiRelationOperations.php` | FR-API-001 |
| `src/Api/Operation/CrmApiUnitHooks.php` | FR-API-001 |
| `src/Api/Operation/CrmApiWriter.php` | FR-API-001 |
| `src/Api/Read/CrmApiItem.php` | FR-API-001 |
| `src/Api/Read/CrmApiList.php` | FR-API-001 |
| `src/Api/Read/CrmApiPage.php` | FR-API-001 |
| `src/Api/Read/CrmApiPresenter.php` | FR-API-001 |
| `src/Api/Read/CrmApiTime.php` | FR-API-001 |
| `src/Api/Read/CrmApiWritten.php` | FR-API-001 |

### 0.3.0-0.6.0: API idempotency (11)

| Source file | Requirement IDs |
| --- | --- |
| `src/Api/Input/IdempotencyKey.php` | FR-API-002 |
| `src/Api/Operation/CrmApiIdempotency.php` | FR-API-002 |
| `src/Api/Operation/CrmApiReplay.php` | FR-API-002 |
| `src/Api/Port/CrmApiCallerFactoryInterface.php` | FR-API-002 |
| `src/Api/Port/CrmApiIdempotencyClaim.php` | FR-API-002 |
| `src/Api/Port/CrmApiIdempotencyRecord.php` | FR-API-002 |
| `src/Api/Port/CrmApiIdempotencyStoreInterface.php` | FR-API-002 |
| `src/Domain/Model/Api/ApiIdempotency.php` | FR-API-002 |
| `src/Infrastructure/Doctrine/Api/DoctrineCrmApiIdempotencyStore.php` | FR-API-002 |
| `src/Infrastructure/Symfony/Command/ApiPurgeIdempotencyCommand.php` | FR-API-002 |
| `src/Resources/config/doctrine-api/ApiIdempotency.orm.xml` | FR-API-002 |

### 0.3.0-0.6.0: Access control, roles and events (23)

| Source file | Requirement IDs |
| --- | --- |
| `src/Application/Access/CrmCallerInterface.php` | FR-ACCESS-001 |
| `src/Application/Access/SessionCaller.php` | FR-ACCESS-001 |
| `src/Application/Roles.php` | FR-ACCESS-001 |
| `src/Domain/Event/RolesChanged.php` | FR-ACCESS-001 |
| `src/Domain/Model/RoleAssignment.php` | FR-ACCESS-001 |
| `src/Domain/Port/CrmBatchAccessCheckerInterface.php` | FR-ACCESS-001 |
| `src/Domain/Port/CrmRecordVisibilityInterface.php` | FR-ACCESS-001 |
| `src/Infrastructure/Symfony/DependencyInjection/Compiler/CrmKitWebUiSecurityPass.php` | FR-ACCESS-001 |
| `src/Infrastructure/Symfony/Event/CrmKitEvents.php` | FR-ACCESS-001 |
| `src/Infrastructure/Symfony/Event/RecordAccessCheckEvent.php` | FR-ACCESS-001 |
| `src/Infrastructure/Symfony/Event/RecordVisibilityEvent.php` | FR-ACCESS-001 |
| `src/Infrastructure/Symfony/Security/AllowAllCrmKitAccessChecker.php` | FR-ACCESS-001 |
| `src/Infrastructure/Symfony/Security/ConfigurableCrmKitAccessChecker.php` | FR-ACCESS-001 |
| `src/Infrastructure/Symfony/Security/CrmActorResolverInterface.php` | FR-ACCESS-001 |
| `src/Infrastructure/Symfony/Security/CrmKitAccessCheckerInterface.php` | FR-ACCESS-001 |
| `src/Infrastructure/Symfony/Security/CrmKitAccessListener.php` | FR-ACCESS-001 |
| `src/Infrastructure/Symfony/Security/CrmKitGuard.php` | FR-ACCESS-001 |
| `src/Infrastructure/Symfony/Security/CrmResourceAccessChecker.php` | FR-ACCESS-001 |
| `src/Infrastructure/Symfony/Security/IdentifierActorResolver.php` | FR-ACCESS-001 |
| `src/Infrastructure/Symfony/Security/ScopedRecordVisibility.php` | FR-ACCESS-001 |
| `src/Infrastructure/Symfony/Security/SecurityCrmAccessChecker.php` | FR-ACCESS-001 |
| `src/Infrastructure/Symfony/Security/TenantAwareCrmKitAccessCheckerInterface.php` | FR-ACCESS-001 |
| `src/Resources/config/doctrine/RoleAssignment.orm.xml` | FR-ACCESS-001 |

### 0.3.0-0.6.0: Actor personal data and purge claim (9)

| Source file | Requirement IDs |
| --- | --- |
| `src/Application/ActorPersonalData.php` | FR-GDPR-004 |
| `src/Application/Port/CrmActorDataStoreInterface.php` | FR-GDPR-004 |
| `src/Application/PurgeClaim.php` | FR-GDPR-004 |
| `src/Application/PurgeClaimLostException.php` | FR-GDPR-004 |
| `src/Domain/Event/ActorAnonymised.php` | FR-GDPR-004 |
| `src/Domain/Event/RecordsPurged.php` | FR-GDPR-004 |
| `src/Domain/Port/CrmActorProfilesInterface.php` | FR-GDPR-004 |
| `src/Domain/Service/CrmActorPersonalDataInterface.php` | FR-GDPR-004 |
| `src/Infrastructure/Doctrine/DoctrineCrmActorDataStore.php` | FR-GDPR-004 |

### 0.3.0-0.6.0: Batch lookup and catalogue reads (7)

| Source file | Requirement IDs |
| --- | --- |
| `src/Application/CrmBatchLookup.php` | FR-READ-002 |
| `src/Application/PartyCatalogue.php` | FR-READ-002 |
| `src/Application/Port/CrmBatchLookupStoreInterface.php` | FR-READ-002 |
| `src/Domain/Read/RolesAndTags.php` | FR-READ-002 |
| `src/Domain/Service/CrmBatchLookupInterface.php` | FR-READ-002 |
| `src/Domain/Service/CrmCatalogueInterface.php` | FR-READ-002 |
| `src/Infrastructure/Doctrine/DoctrineCrmBatchLookupStore.php` | FR-READ-002 |

### 0.3.0-0.6.0: Task bridge (8)

| Source file | Requirement IDs |
| --- | --- |
| `src/Application/Pipeline/BuiltInCrmTaskPort.php` | FR-PIPE-002 |
| `src/Bridge/TaskBoard/CrmTaskBoardUserResolverInterface.php` | FR-PIPE-002 |
| `src/Bridge/TaskBoard/TaskBoardCrmTaskPort.php` | FR-PIPE-002 |
| `src/Domain/Exception/CrmTaskRefusedException.php` | FR-PIPE-002 |
| `src/Domain/Port/CrmTaskPort.php` | FR-PIPE-002 |
| `src/Domain/Value/CrmTaskRef.php` | FR-PIPE-002 |
| `src/Domain/Value/CrmTaskRequest.php` | FR-PIPE-002 |
| `src/Domain/Value/CrmTaskState.php` | FR-PIPE-002 |

### 0.3.0-0.6.0: Pipeline: deals, funnels, next actions (42)

| Source file | Requirement IDs |
| --- | --- |
| `src/Application/Pipeline/DealInput.php` | FR-PIPE-001 |
| `src/Application/Pipeline/DealQuery.php` | FR-PIPE-001 |
| `src/Application/Pipeline/DealReader.php` | FR-PIPE-001 |
| `src/Application/Pipeline/DealService.php` | FR-PIPE-001 |
| `src/Application/Pipeline/Decimal.php` | FR-PIPE-001 |
| `src/Application/Pipeline/FunnelReader.php` | FR-PIPE-001 |
| `src/Application/Pipeline/FunnelService.php` | FR-PIPE-001 |
| `src/Application/Pipeline/FunnelStatistics.php` | FR-PIPE-001 |
| `src/Application/Pipeline/NextActionService.php` | FR-PIPE-001 |
| `src/Application/Pipeline/NextActionViews.php` | FR-PIPE-001 |
| `src/Application/Pipeline/PartyStageService.php` | FR-PIPE-001 |
| `src/Application/Pipeline/PipelineHousekeeping.php` | FR-PIPE-001 |
| `src/Application/Pipeline/PipelineSupport.php` | FR-PIPE-001 |
| `src/Application/Pipeline/Port/PipelineStoreInterface.php` | FR-PIPE-001 |
| `src/Domain/Enum/DealStatus.php` | FR-PIPE-001 |
| `src/Domain/Enum/FunnelKind.php` | FR-PIPE-001 |
| `src/Domain/Enum/NextActionStatus.php` | FR-PIPE-001 |
| `src/Domain/Enum/StageKind.php` | FR-PIPE-001 |
| `src/Domain/Model/Pipeline/Deal.php` | FR-PIPE-001 |
| `src/Domain/Model/Pipeline/Funnel.php` | FR-PIPE-001 |
| `src/Domain/Model/Pipeline/NextAction.php` | FR-PIPE-001 |
| `src/Domain/Model/Pipeline/PartyStage.php` | FR-PIPE-001 |
| `src/Domain/Model/Pipeline/Stage.php` | FR-PIPE-001 |
| `src/Domain/Model/Pipeline/StageChange.php` | FR-PIPE-001 |
| `src/Domain/Read/Pipeline/DealPage.php` | FR-PIPE-001 |
| `src/Domain/Read/Pipeline/DealView.php` | FR-PIPE-001 |
| `src/Domain/Read/Pipeline/FunnelStats.php` | FR-PIPE-001 |
| `src/Domain/Read/Pipeline/FunnelView.php` | FR-PIPE-001 |
| `src/Domain/Read/Pipeline/NextActionView.php` | FR-PIPE-001 |
| `src/Domain/Read/Pipeline/PartyStageView.php` | FR-PIPE-001 |
| `src/Domain/Read/Pipeline/StageChangeView.php` | FR-PIPE-001 |
| `src/Domain/Read/Pipeline/StageStats.php` | FR-PIPE-001 |
| `src/Domain/Read/Pipeline/StageView.php` | FR-PIPE-001 |
| `src/Domain/Value/LostReasonKey.php` | FR-PIPE-001 |
| `src/Domain/Value/Money.php` | FR-PIPE-001 |
| `src/Infrastructure/Doctrine/DoctrinePipelineStore.php` | FR-PIPE-001 |
| `src/Resources/config/doctrine-pipeline/Deal.orm.xml` | FR-PIPE-001 |
| `src/Resources/config/doctrine-pipeline/Funnel.orm.xml` | FR-PIPE-001 |
| `src/Resources/config/doctrine-pipeline/NextAction.orm.xml` | FR-PIPE-001 |
| `src/Resources/config/doctrine-pipeline/PartyStage.orm.xml` | FR-PIPE-001 |
| `src/Resources/config/doctrine-pipeline/Stage.orm.xml` | FR-PIPE-001 |
| `src/Resources/config/doctrine-pipeline/StageChange.orm.xml` | FR-PIPE-001 |

### 0.3.0-0.6.0: SearchKit bridge (4)

| Source file | Requirement IDs |
| --- | --- |
| `src/Bridge/SearchKit/CrmDealSearchDefinition.php` | FR-SEARCH-002 |
| `src/Bridge/SearchKit/CrmRecordSearchDefinition.php` | FR-SEARCH-002 |
| `src/Bridge/SearchKit/CrmSearchDefinition.php` | FR-SEARCH-002 |
| `src/Domain/Read/NoteHit.php` | FR-SEARCH-002 |

### 0.3.0-0.6.0: Exact-identifier value objects and types (6)

| Source file | Requirement IDs |
| --- | --- |
| `src/Domain/Value/AffiliationPurpose.php` | FR-SEC-006 |
| `src/Domain/Value/AuditOrigin.php` | FR-SEC-006 |
| `src/Domain/Value/CompanyDomain.php` | FR-SEC-006 |
| `src/Domain/Value/DateImmutable.php` | FR-SEC-006 |
| `src/Domain/Value/RoleKey.php` | FR-SEC-006 |
| `src/Infrastructure/Doctrine/Type/UtcMicroDateTimeImmutableType.php` | FR-SEC-006 |

### 0.3.0-0.6.0: API HTTP edge (9)

| Source file | Requirement IDs |
| --- | --- |
| `src/Infrastructure/Symfony/Api/ConfiguredCrmApiTenantResolver.php` | FR-API-003 |
| `src/Infrastructure/Symfony/Api/Controller/CrmApiController.php` | FR-API-003 |
| `src/Infrastructure/Symfony/Api/CrmApiAuthenticatorInterface.php` | FR-API-003 |
| `src/Infrastructure/Symfony/Api/CrmApiExceptionListener.php` | FR-API-003 |
| `src/Infrastructure/Symfony/Api/CrmApiRouteLoader.php` | FR-API-003 |
| `src/Infrastructure/Symfony/Api/CrmApiTenantResolverInterface.php` | FR-API-003 |
| `src/Infrastructure/Symfony/Api/PrincipalCaller.php` | FR-API-003 |
| `src/Infrastructure/Symfony/Api/PrincipalCallerFactory.php` | FR-API-003 |
| `src/Infrastructure/Symfony/Api/SecurityUserCrmApiAuthenticator.php` | FR-API-003 |

### 0.3.0-0.6.0: Pipeline UI (23)

| Source file | Requirement IDs |
| --- | --- |
| `src/Infrastructure/Symfony/Controller/PipelineAdminController.php` | FR-UI-003 |
| `src/Infrastructure/Symfony/Controller/PipelineController.php` | FR-UI-003 |
| `src/Infrastructure/Symfony/Controller/PipelineForms.php` | FR-UI-003 |
| `src/Infrastructure/Symfony/Controller/PipelineRecordBlock.php` | FR-UI-003 |
| `src/Infrastructure/Symfony/Controller/UiOptions.php` | FR-UI-003 |
| `src/Infrastructure/Symfony/Form/DealFilterType.php` | FR-UI-003 |
| `src/Infrastructure/Symfony/Form/DealType.php` | FR-UI-003 |
| `src/Infrastructure/Symfony/Form/FunnelType.php` | FR-UI-003 |
| `src/Infrastructure/Symfony/Form/LifecycleType.php` | FR-UI-003 |
| `src/Infrastructure/Symfony/Form/NextActionType.php` | FR-UI-003 |
| `src/Infrastructure/Symfony/Form/StageMoveType.php` | FR-UI-003 |
| `src/Infrastructure/Symfony/Form/StageType.php` | FR-UI-003 |
| `src/Infrastructure/Symfony/Form/StatsFilterType.php` | FR-UI-003 |
| `src/Resources/public/css/nowo-crm-kit.css` | FR-UI-003 |
| `src/Resources/views/partials/_row_actions.html.twig` | FR-UI-003 |
| `src/Resources/views/pipeline/_next_actions.html.twig` | FR-UI-003 |
| `src/Resources/views/pipeline/_record.html.twig` | FR-UI-003 |
| `src/Resources/views/pipeline/board.html.twig` | FR-UI-003 |
| `src/Resources/views/pipeline/deal.html.twig` | FR-UI-003 |
| `src/Resources/views/pipeline/deals.html.twig` | FR-UI-003 |
| `src/Resources/views/pipeline/funnel.html.twig` | FR-UI-003 |
| `src/Resources/views/pipeline/funnels.html.twig` | FR-UI-003 |
| `src/Resources/views/pipeline/stats.html.twig` | FR-UI-003 |

### 0.7.0-0.10.x: HubSpot connection and secret box (22)

| Source file | Requirement IDs |
| --- | --- |
| `src/Application/HubSpot/HubSpotConnectionService.php` | FR-HUB-001 |
| `src/Application/HubSpot/HubSpotTokenOpener.php` | FR-HUB-001, FR-SECRET-001 |
| `src/Bridge/DoctrineEncrypt/DoctrineEncryptCrmSecretBox.php` | FR-SECRET-001 |
| `src/Bridge/OutboundUrlGuard/GuardedHubSpotRequestOptions.php` | FR-HUB-001, FR-HUB-002 |
| `src/Domain/Enum/HubSpotConnectionStatus.php` | FR-HUB-001 |
| `src/Domain/Event/HubSpotConnected.php` | FR-HUB-001, FR-EVT-001 |
| `src/Domain/Event/HubSpotDisconnected.php` | FR-HUB-001, FR-EVT-001 |
| `src/Domain/Exception/SecretKeyUnavailableException.php` | FR-SECRET-001 |
| `src/Domain/Exception/SecretUnsealException.php` | FR-SECRET-001 |
| `src/Domain/Model/HubSpot/HubSpotConnection.php` | FR-HUB-001 |
| `src/Domain/Port/CrmSecretBoxInterface.php` | FR-SECRET-001, FR-PORT-001 |
| `src/Domain/Read/HubSpot/HubSpotConnectionView.php` | FR-HUB-001 |
| `src/Domain/Read/HubSpot/HubSpotTokenInfo.php` | FR-HUB-001 |
| `src/Domain/Service/HubSpotConnectionServiceInterface.php` | FR-HUB-001 |
| `src/Domain/Value/HubSpotAccountId.php` | FR-HUB-001, FR-SEC-006 |
| `src/Domain/Value/HubSpotToken.php` | FR-HUB-001 |
| `src/Domain/Value/SealedSecret.php` | FR-SECRET-001 |
| `src/Domain/Value/SecretContext.php` | FR-SECRET-001 |
| `src/Infrastructure/Crypto/SodiumCrmSecretBox.php` | FR-SECRET-001 |
| `src/Infrastructure/Symfony/Command/SecretsRotateCommand.php` | FR-SECRET-001 |
| `src/Infrastructure/Symfony/Form/HubSpotConnectType.php` | FR-HUB-001, FR-UI-001 |
| `src/Resources/config/doctrine-hubspot/HubSpotConnection.orm.xml` | FR-HUB-001, FR-SEC-006 |

### 0.7.0-0.10.x: HubSpot import run (43)

| Source file | Requirement IDs |
| --- | --- |
| `src/Application/HubSpot/HubSpotErrors.php` | FR-HUB-002 |
| `src/Application/HubSpot/HubSpotException.php` | FR-HUB-002 |
| `src/Application/HubSpot/HubSpotImportService.php` | FR-HUB-002, FR-IMP-001 |
| `src/Application/HubSpot/HubSpotMapper.php` | FR-HUB-002 |
| `src/Application/HubSpot/HubSpotRecordWriter.php` | FR-HUB-002, FR-GATE-001 |
| `src/Application/HubSpot/HubSpotRunState.php` | FR-HUB-002 |
| `src/Application/HubSpot/HubSpotRunner.php` | FR-HUB-002, FR-CONC-001 |
| `src/Application/HubSpot/NeedsPersonException.php` | FR-HUB-002, FR-GATE-001 |
| `src/Application/HubSpot/Port/HubSpotReaderInterface.php` | FR-HUB-002 |
| `src/Application/HubSpot/Port/HubSpotStoreInterface.php` | FR-HUB-001, FR-HUB-002 |
| `src/Application/Port/ExternalRefHooksInterface.php` | FR-HUB-002, FR-GDPR-002, FR-MERGE-001 |
| `src/Domain/Enum/HubSpotObject.php` | FR-HUB-002 |
| `src/Domain/Model/HubSpot/ExternalRef.php` | FR-HUB-002 |
| `src/Domain/Port/CrmActorEmailLookupInterface.php` | FR-HUB-002, FR-PORT-001 |
| `src/Domain/Read/HubSpot/HubSpotAssociation.php` | FR-HUB-002 |
| `src/Domain/Read/HubSpot/HubSpotIdPage.php` | FR-HUB-002 |
| `src/Domain/Read/HubSpot/HubSpotList.php` | FR-HUB-002 |
| `src/Domain/Read/HubSpot/HubSpotOwner.php` | FR-HUB-002 |
| `src/Domain/Read/HubSpot/HubSpotOwnerPage.php` | FR-HUB-002 |
| `src/Domain/Read/HubSpot/HubSpotPage.php` | FR-HUB-002 |
| `src/Domain/Read/HubSpot/HubSpotProperty.php` | FR-HUB-002 |
| `src/Domain/Read/HubSpot/HubSpotPropertyView.php` | FR-HUB-002 |
| `src/Domain/Read/HubSpot/HubSpotRecord.php` | FR-HUB-002 |
| `src/Domain/Read/HubSpot/HubSpotSchemaView.php` | FR-HUB-002 |
| `src/Domain/Service/HubSpotImportServiceInterface.php` | FR-HUB-002 |
| `src/Domain/Value/HubSpotCursor.php` | FR-HUB-002, FR-SEC-006 |
| `src/Domain/Value/HubSpotImportScope.php` | FR-HUB-002 |
| `src/Domain/Value/HubSpotKey.php` | FR-HUB-002, FR-SEC-006 |
| `src/Domain/Value/HubSpotMapping.php` | FR-HUB-002 |
| `src/Domain/Value/HubSpotNumericIdTrait.php` | FR-HUB-002, FR-SEC-006 |
| `src/Domain/Value/HubSpotObjectId.php` | FR-HUB-002, FR-SEC-006 |
| `src/Infrastructure/Doctrine/DoctrineHubSpotStore.php` | FR-HUB-001, FR-HUB-002, FR-CONC-001 |
| `src/Infrastructure/HubSpot/HttpHubSpotReader.php` | FR-HUB-002 |
| `src/Infrastructure/HubSpot/HubSpotHttpClientFactory.php` | FR-HUB-002 |
| `src/Infrastructure/HubSpot/HubSpotRequestOptionsInterface.php` | FR-HUB-002 |
| `src/Infrastructure/Symfony/Controller/HubSpotImportController.php` | FR-HUB-001, FR-HUB-002, FR-UI-002 |
| `src/Infrastructure/Symfony/Form/HubSpotMappingType.php` | FR-HUB-002, FR-UI-002 |
| `src/Infrastructure/Symfony/Form/HubSpotStartType.php` | FR-HUB-002, FR-UI-002 |
| `src/Resources/config/doctrine-hubspot/ExternalRef.orm.xml` | FR-HUB-002, FR-SEC-006 |
| `src/Resources/views/import/_hubspot_state.html.twig` | FR-HUB-002, FR-UI-002 |
| `src/Resources/views/import/hubspot/_body.html.twig` | FR-HUB-001, FR-HUB-002, FR-UI-002 |
| `src/Resources/views/import/hubspot/_mapping_table.html.twig` (0.10.3) | FR-HUB-002, FR-UI-002 |
| `src/Resources/views/import/_primary.html.twig` (0.10.3) | FR-UI-002 |
| `src/Resources/views/import/hubspot/dialog.html.twig` | FR-HUB-001, FR-HUB-002, FR-UI-002 |
| `src/Resources/views/import/hubspot/page.html.twig` | FR-HUB-001, FR-HUB-002, FR-UI-002 |

### 0.7.0-0.10.x: background import runs (Messenger) (4)

| Source file | Requirement IDs |
| --- | --- |
| `src/Application/Port/ImportRunDispatcherInterface.php` | FR-MSG-001 |
| `src/Infrastructure/Symfony/Messenger/MessengerImportRunDispatcher.php` | FR-MSG-001 |
| `src/Infrastructure/Symfony/Messenger/RunCrmImport.php` | FR-MSG-001 |
| `src/Infrastructure/Symfony/Messenger/RunCrmImportHandler.php` | FR-MSG-001, FR-WORKER-001 |

### 0.7.0-0.10.x: host object picker (6)

| Source file | Requirement IDs |
| --- | --- |
| `src/Domain/Port/CrmHostObjectSearchInterface.php` | FR-LINK-001, FR-PORT-001 |
| `src/Domain/Port/CrmHostObjectTypeLabelInterface.php` | FR-LINK-001, FR-PORT-001 |
| `src/Domain/Service/HostObjectPickerInterface.php` | FR-LINK-001 |
| `src/Domain/Value/HostObjectMatch.php` | FR-LINK-001 |
| `src/Domain/Value/HostTypeLabel.php` | FR-LINK-001 |
| `src/Resources/views/record/_link_form.html.twig` | FR-LINK-001, FR-UI-001 |

### 0.7.0-0.10.x: UI shell, dialogs and shared constants (13)

| Source file | Requirement IDs |
| --- | --- |
| `src/Domain/Read/Pipeline/DealListResult.php` | FR-UI-003 |
| `src/Domain/TranslationDomain.php` | FR-I18N-001, FR-ARCH-001 |
| `src/Infrastructure/Symfony/Controller/ListFilters.php` | FR-UI-001, FR-UI-003 |
| `src/Infrastructure/Symfony/Controller/PipelineDialogs.php` | FR-UI-003 |
| `src/Infrastructure/Symfony/Controller/UiIcons.php` | FR-UI-001 |
| `src/Infrastructure/Symfony/Form/AdminFilterType.php` | FR-UI-001 |
| `src/Infrastructure/Symfony/Form/FormProfile.php` | FR-UI-001, FR-ARCH-001 |
| `src/Resources/views/_dialog_form.html.twig` | FR-UI-001 |
| `src/Resources/views/admin/_create.html.twig` | FR-UI-001 |
| `src/Resources/views/admin/_create_remote.html.twig` | FR-UI-001 |
| `src/Resources/views/partials/_empty.html.twig` | FR-UI-001 |
| `src/Resources/views/pipeline/_dialog.html.twig` | FR-UI-003 |
| `src/Resources/views/pipeline/form.html.twig` | FR-UI-003 |

**Total production sources**: 512 (every file under `src/`; the `0.7.0-0.10.x` sections were added by the 2026-11-24
refresh, 85 files; 0.10.2 added `ListFilters.php`, `DealListResult.php` and `partials/_empty.html.twig`; 0.10.3 added
`ImportDialogPageResolver.php`, `import/_primary.html.twig` and `import/hubspot/_mapping_table.html.twig`)