# Architecture

## Table of contents

- [Layers](#layers)
- [Layer rules](#layer-rules)
- [Architecture checks](#architecture-checks)
- [Request flow](#request-flow)
- [Persistence](#persistence)
- [Host kinds](#host-kinds)

Design: [CONTRACT.md](CONTRACT.md) sections 3, 4 and 20.

## Layers

```
src/Domain/Model/          entities (plain PHP; XML mapping in Resources/config/doctrine), RecordFields
src/Domain/Enum/           CrmPermission, SubjectType, RecordStatus, FieldType, ActivityKind, Consent / Import enums
src/Domain/Value/          VOs: TenantRef, ActorRef, RecordId, SubjectRef, HostObjectRef, EmailAddress, TagName, RoleKey,
                           AffiliationPurpose, ...
src/Domain/Port/           host ports: tenant resolver, access checker, feature gate, plan limits, actor directory,
                           audit sink, panel providers, host object resolvers, id generator
src/Domain/Service/        public service interfaces + RecordInput, ListQuery
src/Domain/Read/           read models (RecordView, ListResult, NoteView, ActivityView, ImportView, MergePreview, ...)
src/Domain/Event/          after-commit events (ids and VOs only)
src/Domain/Exception/      NotAvailable (404), AccessDenied (403), Conflict (409 domain), InvalidInput (422 / 400)
src/Domain/Import/         CsvParser, VcardParser (pure functions on bytes)
src/Application/           services implementing the interfaces; CrmAccess (the gate), WriteSupport, PlanCounter,
                           RecordValidator, ImportMapping, RecordPurger, Taggings, Roles, PartyCatalogue, RecordViews
src/Application/Port/      CrmStoreInterface, CrmBatchLookupStoreInterface (persistence ports owned by the application)
src/Application/Shared/    Effects (per-call after-commit buffer), TransactionRunnerInterface, unit exceptions
src/Infrastructure/Doctrine/   DoctrineCrmStore, DoctrineCrmBatchLookupStore, DoctrineTransactionRunner, CrmMetadataListener, BinaryCollation,
                               UTC datetime type
src/Infrastructure/Symfony/    DI (config tree, service definitions, compiler passes), controllers, forms, commands,
                               HTTP listeners (exceptions, conflicts, EM hygiene), port defaults, route loader
src/Bridge/SearchKit/      CrmSearchProvider (the only code that imports SearchKit)
src/Resources/             doctrine mapping, Twig views (@NowoCrmKit), translations (7 locales)
```

## Layer rules

Enforced by `tests/Architecture/LayerDependencyTest`:

- `Domain` imports no Symfony, Doctrine or HTTP class and nothing from `Application`, `Infrastructure` or `Bridge`.
- `Application` imports no Symfony or Doctrine class and nothing from `Infrastructure` or `Bridge`; it uses PSR
  interfaces (clock, log, event dispatcher) and the transaction contracts.
- `Bridge\*` are the only importers of optional packages (SearchKit); the one exception is
  `Infrastructure\Symfony\DependencyInjection\Compiler\CrmPortsPass`, which checks that SearchKit's interface exists
  before it registers the providers.
- Public ports, events and value objects carry no Symfony, Doctrine or HTTP types (REQ-ARCH-001 rule 3: own VO per
  port).

## Architecture checks

Besides `LayerDependencyTest`, [Deptrac](https://github.com/deptrac/deptrac) checks the dependencies between the
layers on every CI run (REQ-ARCH-002; `deptrac.yaml`, `composer deptrac` / `make deptrac`, part of
`make release-check`). A violation, a dependency on an unlisted vendor namespace or a class under `src/` that belongs
to no layer fails the build.

| Deptrac layer | Namespaces | May depend on |
| --- | --- | --- |
| `Domain` | `Domain` | itself, `psr/clock`, `psr/log`, `psr/event-dispatcher`, `nowo-tech/transaction-contract` |
| `Application` | `Application`, `Api` (operation layer) | `Domain` and the same contract packages |
| `Infrastructure` | `Infrastructure` except the UI edge and DI below | `Domain`, `Application`, Symfony, Doctrine, Twig, transaction-doctrine, UiKit / FormKit |
| `UI` | `Infrastructure\Symfony\{Controller, Form, Routing, Command, Http, Security, Api, Event}` and `Symfony\Port\SecurityActorDirectory` (not `Http\EntityManagerHygieneListener`, a Doctrine adapter) | `Domain`, `Application`, Symfony, Twig, transaction-doctrine (write-unit attributes), UiKit / FormKit |
| `Bridge` | `Bridge` | `Domain`, `Application`, `Infrastructure`, Symfony, Doctrine and the bridged packages (SearchKit, TaskBoard, DoctrineEncrypt, OutboundUrlGuard) |
| `DependencyInjection` | `Infrastructure\Symfony\DependencyInjection`, the bundle class | every layer (composition root) |

The translation domain and the FormKit profile are read from `Domain\TranslationDomain::NAME` and
`Infrastructure\Symfony\Form\FormProfile::NAME` (the bundle constants keep the same values), so no layer depends on
the bundle class. `skip_violations` is empty since 0.10.1: `NextActionService::set()` names transaction-contract 1.1's
`ConcurrencyConflict` (transaction-doctrine 1.10 aliases its old name to it). The list may only shrink.

## Request flow

1. A controller (or a host service call) reaches an application service.
2. `CrmAccess` resolves, per call: the tenant (`CrmTenantResolverInterface`; none ⇒ 404), the feature gate (off ⇒
   404), the actor (none ⇒ 404), then the permission (`CrmAccessCheckerInterface::isGranted()`; refused ⇒ 403). A
   record of another tenant is 404. Nothing is cached.
3. Writes run in `WriteSupport::write()`: a transaction-doctrine write unit around one transaction on the bundle's
   entity manager, flushed before the commit. Timeline rows are written inside the unit; events and audit entries are
   collected in a per-call `Effects` buffer and released only after the commit (audit first, then events). A write
   inside a transaction that is already open is refused, so no outer rollback can undo what was released. Writes to
   a child row (note, consent, link, affiliation) lock the parent record first.
4. Reads run in `WriteSupport::read()`; the entity manager is cleared afterwards.
5. Domain exceptions become 404 / 403 / 409 / 422 on the bundle's routes (`DomainExceptionListener`); lock conflicts
   become 409 + `CONFLICT_001` / `CONFLICT_002` through transaction-doctrine's responder (its bundle is required), or
   the bundle's fallback listener when the host turns that responder off ([CONCURRENCY.md](CONCURRENCY.md)).

## Persistence

- Entities are bundle-owned plain classes (`Company` and `Contact` share the mapped superclass `CrmRecord`), mapped
  in XML, related by plain id columns (no ORM associations), so children move by primary key and the bundle never
  loads a graph.
- `CrmMetadataListener` prefixes table, index and unique-constraint names with `doctrine.table_prefix` and maps every
  `id` / `*_id` column and the key columns with a binary NO PAD collation on MySQL / MariaDB (REQ-SEC-006).
- `DoctrineCrmStore` implements `Application\Port\CrmStoreInterface`; every row it returns is re-checked in PHP with
  `===` against the tenant (and owner, when filtered).
- Atomic SQL is used where a race matters: plan counters, import claims and row settlement
  ([CONCURRENCY.md](CONCURRENCY.md)).

## Host kinds

The suite boots three hosts (REQ-ARCH-001): standard (private entity manager `nowo_crm_kit` on the default
connection), DDD (own connection, entity manager `ddd`, another table prefix) and single entity manager
(`doctrine.entity_manager: null`: the host's default manager, used in explicit transactions; the runner detaches only
the bundle's entities and refuses to write while the host has pending changes of its own).
