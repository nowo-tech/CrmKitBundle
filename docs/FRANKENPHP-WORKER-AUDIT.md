# FrankenPHP worker audit

## Table of contents

- [Scope](#scope)
- [Result](#result)
- [State per component](#state-per-component)
- [Entity manager hygiene](#entity-manager-hygiene)
- [Per-call buffers and streams](#per-call-buffers-and-streams)
- [Worker test](#worker-test)
- [Static analysis](#static-analysis)

## Scope

REQ-RUNTIME-002; contract section 18. Audited revision: **0.10.1** (refreshed 2026-11-24 for everything shipped since
phase A: the pipeline, the JSON API, the HubSpot import with its HTTP client, secret boxes and throttles, and the
Messenger handler; set to the tag at release, [RELEASE.md](RELEASE.md)). The bundle must work in FrankenPHP worker mode with the kernel kept alive and **not reset**
between requests (`kernel.reset` false, no services resetter relied on): nothing a request learns may reach the next
one.

## Result

No request, tenant, actor, permission or record data is kept in service properties or statics. Every port is asked
per call, the private entity manager is empty after every unit and every request, and memory stays flat over a long
run with alternating tenants and actors.

## State per component

| Component | State kept between calls | Notes |
| --- | --- | --- |
| Application services (`RecordService`, `CrmReader`, `MergeService`, `ImportService`, `TagService`, `FieldService`, `NoteService`, `HostLinkService`, `ConsentService`, `CrmPersonalData`, `CrmExporter`, `SettingsService`, `PlanCounter`, `RecordPurger`, `WriteSupport`, `Taggings`, `RecordViews`) | none | `final readonly`; collaborators and configuration scalars only |
| `CrmAccess` | none | asks the tenant resolver, feature gate and access checker on every call; the resulting `CrmContext` (tenant, actor, now) lives only for the call |
| Port defaults (`SingleTenantResolver`, `SecurityCrmAccessChecker`, `ConfigurableCrmKitAccessChecker`, `CrmResourceAccessChecker`, `CrmKitGuard`, `SecurityActorDirectory`, `ConfiguredFeatureGate`, `ConfiguredPlanLimits`, `PsrLogAuditSink`, `SymfonyUuidGenerator`) | none | read the security token per call; configuration only |
| `DoctrineTransactionRunner`, `DoctrineCrmStore` | none | the entity manager is fetched from the registry per call (and reset when closed) |
| `CrmMetadataListener` | none | the table prefix only; collation chosen from the platform per class load |
| Controllers, forms, route loader, HTTP listeners | none | `final readonly`; forms are built per request |
| SearchKit bridge (`CrmSearchDefinition`, `CrmRecordSearchDefinition`, `CrmDealSearchDefinition`) | none | the reader per call |
| Pipeline (`FunnelService`, `DealService`, `PartyStageService`, `NextActionService`, `FunnelStatistics`, readers, `BuiltInCrmTaskPort`, TaskBoard bridge) | none | `final readonly`; funnels, stages and deals are read per call; the TaskBoard bridge asks TaskBoard's API operations per call |
| JSON API (`Api\Operation\*`, `ApiIdempotency`, `CrmApiCursorCodec`, HTTP edge controller, authenticator, tenant resolver) | none | operations are `final readonly`; the idempotency claim and replay live in the database; the cursor codec holds `kernel.secret` only; the authenticator reads the security token per request |
| HubSpot client (`HubSpotHttpClientFactory`, `HttpHubSpotReader`, `GuardedHubSpotRequestOptions`) | the `HttpClientInterface` instance | stateless per request: the bearer header is built per call from a token that lives for that call; no cookie jar, no cached response; the OutboundUrlGuard pin is resolved per call |
| HubSpot services (`HubSpotConnectionService`, `HubSpotImportService`, `HubSpotRunner`, `HubSpotRecordWriter`, `HubSpotEngagementWriter` (0.12.0), `HubSpotTokenOpener`, `DoctrineHubSpotStore`) and the pure `HubSpotText` (0.12.0, static, no state) | none | `final readonly`; the connect and browse throttles are counter rows in the database, never memory; the run cursor lives in `crm_import.run_cursor` |
| `HubSpotToken` | its own plaintext, for the object's life | the plaintext sits in a suspended generator's frame owned by the object, created per call and collected with it; never a service property |
| Secret boxes (`SodiumCrmSecretBox`, `DoctrineEncryptCrmSecretBox`) | the configured keys / the encryptor registry | configuration only; no plaintext and no per-tenant state between calls; `secrets:rotate` works one connection per unit |
| `RunCrmImportHandler`, `MessengerImportRunDispatcher` | none | ids only in the messages; the handler claims the import like the resume command and dispatches the next message |
| Caches | none | the kit keeps no cache |

Host ports (tenant resolver, access checker, directory, panels, resolvers) are host code: they must be stateless too
([USAGE.md](USAGE.md#ports-to-implement)).

## Entity manager hygiene

- The runner clears the private entity manager (`nowo_crm_kit`) when every unit ends (`write()` and `read()`), on
  success and on failure. On a shared manager (`entity_manager: null`) it detaches only the bundle's entities.
- `EntityManagerHygieneListener` clears it again at `kernel.finish_request`, `kernel.terminate` and
  `console.terminate` (priority −2048), so nothing survives a request or a command even when a unit was skipped.
  With `import.messenger.enabled` (0.10.1) it also listens to Messenger's `WorkerMessageHandledEvent` and
  `WorkerMessageFailedEvent`, so a long-running worker starts every message with an empty manager
  (`HubSpotEdgeTest::testTheBackgroundRunThroughMessenger`).
- A manager closed by a failed flush is reset through `ManagerRegistry::resetManager()` before the next unit.
- Transactions open and close inside the call; no lock survives it.

## Per-call buffers and streams

- `Effects` (events and audit entries) is created per write call, cleared at the start of every attempt and released
  after the commit; it is never a service.
- Timeline actor names and tag maps are local arrays of the call.
- CSV exports stream from a keyset reader in batches of 500 rows, so memory stays flat whatever the list size; the
  entity manager is cleared after each batch.

## Worker test

`tests/Integration/Database/HostKindsAndWorkerTest.php::testOneKernelServesTwoTenantsWithoutCrossedData`: one
kept-alive kernel (no reboot between requests) serves **120 requests** alternating **two tenants and two actors**
(the companies list on every request, a contact created through the form every fifth), and asserts:

- every page shows only its tenant's companies (no crossed data);
- no managed entity is left in the bundle's unit of work after each request;
- memory grows by **less than 8 MiB** after a warm-up of 20 requests;
- each tenant ends with exactly its own contacts.

The same class runs the DDD host and the single-entity-manager host (`testTheDddHost`,
`testTheSingleEntityManagerHostKeepsItsOwnWork`).

## Static analysis

- **Igor** (`composer igor`, `make igor`, CI): the worker-state audit of `src/` passes; the few `@igor-ignore`
  comments mark per-call buffers (`Effects`) and closures the runner calls at once.
- **PHPStan** level max with `nowo-tech/phpstan-frankenphp` (`ruleset-classic`, `ruleset-worker-strict`,
  `ruleset-hardening`) in CI.
- Demos run FrankenPHP in worker mode (`FRANKENPHP_MODE=worker`, [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md)).
