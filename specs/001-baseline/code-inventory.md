# Code inventory - 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/crm-kit-bundle`  
**Last audited**: 2026-11-18 (phase A, 0.1.0 not tagged)

Scope: every file under `src/`. Audit command: `find src -type f | wc -l` → **217** (181 PHP files, 16 Doctrine XML
mappings, 13 Twig views, 7 translation files).

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

### Application: persistence port and shared units (6)

| Source file | Requirement IDs |
| --- | --- |
| `src/Application/Port/CrmStoreInterface.php` | FR-ARCH-001 |
| `src/Application/Shared/DuplicateRowException.php` | FR-CONC-001 |
| `src/Application/Shared/Effects.php` | FR-EVT-001, FR-WORKER-001 |
| `src/Application/Shared/StaleRowException.php` | FR-CONC-001 |
| `src/Application/Shared/TransactionRunnerInterface.php` | FR-CONC-001 |
| `src/Application/Shared/WriteConflictException.php` | FR-CONC-001 |

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

### Infrastructure: port defaults (7)

| Source file | Requirement IDs |
| --- | --- |
| `src/Infrastructure/Symfony/Port/ConfiguredFeatureGate.php` | FR-PORT-001 |
| `src/Infrastructure/Symfony/Port/ConfiguredPlanLimits.php` | FR-PORT-001 |
| `src/Infrastructure/Symfony/Port/PsrLogAuditSink.php` | FR-PORT-001 |
| `src/Infrastructure/Symfony/Port/RoleMapAccessChecker.php` | FR-PORT-001 |
| `src/Infrastructure/Symfony/Port/SecurityActorDirectory.php` | FR-PORT-001 |
| `src/Infrastructure/Symfony/Port/SingleTenantResolver.php` | FR-PORT-001 |
| `src/Infrastructure/Symfony/Port/SymfonyUuidGenerator.php` | FR-PORT-001 |

### Infrastructure: HTTP listeners (4)

| Source file | Requirement IDs |
| --- | --- |
| `src/Infrastructure/Symfony/Http/ConflictFallbackListener.php` | FR-CONC-001 |
| `src/Infrastructure/Symfony/Http/CrmConflictFormatter.php` | FR-CONC-001 |
| `src/Infrastructure/Symfony/Http/DomainExceptionListener.php` | FR-GATE-001, FR-CONC-001 |
| `src/Infrastructure/Symfony/Http/EntityManagerHygieneListener.php` | FR-WORKER-001 |
| `src/Infrastructure/Symfony/Http/ImportDialog.php` (0.4.0) | FR-UI-002 |

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

### Bridge: SearchKit (1)

| Source file | Requirement IDs |
| --- | --- |
| `src/Bridge/SearchKit/CrmSearchProvider.php` | FR-SEARCH-001 |

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

### Resources: Twig views (13)

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
| `src/Resources/assets/tests/import-dialog.test.ts` | FR-UI-002 |
| `src/Resources/public/js/nowo-crm-import.js` (Vite build of the two sources above) | FR-UI-002 |

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

**Total production sources**: 217
