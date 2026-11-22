# Usage

## Table of contents

- [What phase A does](#what-phase-a-does)
- [Set it up](#set-it-up)
- [Ports to implement](#ports-to-implement)
  - [Tenant resolver](#tenant-resolver)
  - [Access checker](#access-checker)
  - [Feature gate](#feature-gate)
  - [Plan limits](#plan-limits)
  - [Actor directory](#actor-directory)
  - [Actor resolver (0.2.1)](#actor-resolver-021)
  - [Audit sink](#audit-sink)
  - [Panel providers](#panel-providers)
  - [Host object resolvers](#host-object-resolvers)
- [Services a host calls](#services-a-host-calls)
  - [Searching notes (0.5.0)](#searching-notes-050)
- [Events](#events)
- [A person's references (export and anonymisation)](#a-persons-references-export-and-anonymisation)
- [Batch lookups for host jobs](#batch-lookups-for-host-jobs)
- [Errors](#errors)
- [Roles and affiliation purposes](#roles-and-affiliation-purposes)
- [Pages and routes](#pages-and-routes)
- [Imports](#imports)
- [SearchKit](#searchkit)
- [UiKit context menus and focus mode](#uikit-context-menus-and-focus-mode)

## What phase A does

Phase A (0.1.0) gives a tenant **companies** and **contacts** with owners, tags, custom fields, notes, an activity
timeline, affiliations (contact ↔ company with a role, one primary company), host links and host panels on a 360°
page, CSV / vCard import, duplicate hints and merging, CSV / vCard export, GDPR consent / export / erasure /
retention, trash with restore, plan-limit counters and a SearchKit provider. 0.2.0 adds **party roles** (customer,
supplier, partner, … several per record, also on a contact without a company), the company's **primary contact** and
**affiliation purposes** (billing, orders, technical, general). The host owns tenants, people, who may do
what, the plan, the brand and its own modules; the bundle asks the host through ports on every call.

## Set it up

1. Install the bundle and its entity manager ([INSTALLATION.md](INSTALLATION.md)), register
   `NowoTransactionDoctrineBundle`, generate the migration with `doctrine:migrations:diff --em=nowo_crm_kit`.
2. Implement the ports your host needs (below) and point the configuration at their service ids
   ([CONFIGURATION.md](CONFIGURATION.md#ports)). The defaults are a single tenant `default`, a role map that denies
   everything until roles are mapped, the CRM on for every tenant, no plan limit, a directory that only knows the
   signed-in user and a PSR log audit line.
3. Opt in to the pages (`ui.enabled: true`) and import the routes:

   ```yaml
   # config/routes/nowo_crm_kit.yaml
   nowo_crm_kit:
       resource: .
       type: nowo_crm_kit_ui
       prefix: /admin
   ```

4. Schedule `nowo:crm-kit:purge` (daily) and `nowo:crm-kit:import:resume` (every few minutes).

## Ports to implement

All ports live in `Nowo\CrmKitBundle\Domain\Port` and take and return the bundle's value objects
(`Domain\Value\*`). They are asked **per call** and must not cache tenant, actor or permission data in service
properties (FrankenPHP worker mode). Host references (`TenantRef`, `ActorRef`, host ids) are opaque strings of 1–128
bytes, compared byte for byte: never trimmed or case folded.

### Tenant resolver

```php
use Nowo\CrmKitBundle\Domain\Port\CrmTenantResolverInterface;
use Nowo\CrmKitBundle\Domain\Value\TenantRef;

final readonly class OrganizationTenantResolver implements CrmTenantResolverInterface
{
    public function __construct(private CurrentOrganization $organizations) {}

    public function current(): ?TenantRef
    {
        $id = $this->organizations->currentId();   // null outside an organisation

        return $id === null ? null : new TenantRef($id);
    }
}
```

`null` means no tenant: every page and service call answers 404. Configure: `nowo_crm_kit.tenant_resolver:
App\Crm\OrganizationTenantResolver`.

### Access checker

0.2.0 follows the house shape of the Nowo admin UIs (REQ-UI-002; [CONFIGURATION.md](CONFIGURATION.md#security-req-ui-002)).

**Areas.** The roles of `security.*_roles` decide by default. For other rules, implement
`Nowo\CrmKitBundle\Infrastructure\Symfony\Security\CrmKitAccessCheckerInterface` (or its tenant-aware variant, which
receives the tenant of the call) and set `security.access_checker` to your service:

```php
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\TenantAwareCrmKitAccessCheckerInterface;

final readonly class OrganizationCrmAreas implements TenantAwareCrmKitAccessCheckerInterface
{
    public function __construct(private Memberships $members) {}

    public function canAccess(?object $user, ?string $tenantKey = null): bool { return $this->has($user, $tenantKey, 'crm.view'); }
    public function canCreate(?object $user, ?string $tenantKey = null): bool { return $this->has($user, $tenantKey, 'crm.edit'); }
    public function canEdit(?object $user, ?string $tenantKey = null): bool { return $this->has($user, $tenantKey, 'crm.edit'); }
    public function canDelete(?object $user, ?string $tenantKey = null): bool { return $this->has($user, $tenantKey, 'crm.edit'); }
    public function canImport(?object $user, ?string $tenantKey = null): bool { return $this->has($user, $tenantKey, 'crm.edit'); }
    public function canExport(?object $user, ?string $tenantKey = null): bool { return $this->has($user, $tenantKey, 'crm.manage'); }
    public function canManageSettings(?object $user, ?string $tenantKey = null): bool { return $this->has($user, $tenantKey, 'crm.manage'); }
    public function canErase(?object $user, ?string $tenantKey = null): bool { return $this->has($user, $tenantKey, 'crm.manage'); }
    public function canViewAmounts(?object $user, ?string $tenantKey = null): bool { return $this->has($user, $tenantKey, 'crm.amounts.view'); }
    public function canSendCampaigns(?object $user, ?string $tenantKey = null): bool { return $this->has($user, $tenantKey, 'crm.campaigns.send'); }

    private function has(?object $user, ?string $tenant, string $permission): bool
    {
        return $user !== null && $tenant !== null && $this->members->has($tenant, $user->getUserIdentifier(), $permission);
    }
}
```

**The actor** is the security user's identifier (1–128 bytes, compared exactly); the actor directory port gives the
names. Deny by default: return `false` for anything you do not grant.

**The deprecated 0.1.0 port.** A `Domain\Port\CrmAccessCheckerInterface` service set in the root key `access_checker`
still replaces all of this in 0.2.0; it is asked `create`, `delete` and `import` as `edit`, `export` and `erase` as
`manage`. Move to the area checker and a record listener; the key is removed in 0.3.0.

<a id="record-access-listeners"></a>
**Records.** After the area check, a decision on one company or contact dispatches
`CrmKitEvents::RECORD_ACCESS_CHECK` (`nowo_crm_kit.record_access_check`). The default is "open" (a shared CRM) or,
with `security.resource_scoping: true`, "owner, ownerless record or `settings_roles` only". A listener grants or
denies; a deny always wins:

```php
use Nowo\CrmKitBundle\Infrastructure\Symfony\Event\{CrmKitEvents, RecordAccessCheckEvent};
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: CrmKitEvents::RECORD_ACCESS_CHECK)]
final readonly class TeamRecordAccess
{
    public function __construct(private Teams $teams) {}

    public function __invoke(RecordAccessCheckEvent $event): void
    {
        // getAction(): view, edit, create, delete, import, export, manage, erase, ...
        $owner = $event->getOwnerId();
        if ($owner !== null && $this->teams->sameTeam($event->getTenantKey(), $event->getActorId(), $owner)) {
            $event->grant();
        }
        if ($event->getAction() === 'erase' && $this->teams->isLegalHold($event->getTenantKey(), $event->getSubjectId())) {
            $event->deny();
        }
    }
}
```

**Lists under resource scoping.** With `security.resource_scoping: true`, every read that returns several records
(lists and totals, search, duplicates, CSV exports, the related records of a page, `subjectsLinkedTo()`) shows only
ownerless records, the person's own and the owners a `CrmKitEvents::RECORD_VISIBILITY` listener adds, in SQL; the
same set is the default of the record decision above, so a team rule belongs here:

```php
use Nowo\CrmKitBundle\Infrastructure\Symfony\Event\{CrmKitEvents, RecordVisibilityEvent};

#[AsEventListener(event: CrmKitEvents::RECORD_VISIBILITY)]
final readonly class TeamVisibility
{
    public function __construct(private Teams $teams) {}

    public function __invoke(RecordVisibilityEvent $event): void
    {
        foreach ($this->teams->teammates($event->getTenantKey(), $event->getActorId()) as $id) {
            $event->addOwner($id);
        }
        // $event->allowAll() opens every record of the tenant (e.g. a supervisor).
    }
}
```

Lists follow only `RECORD_VISIBILITY` (owner-based); `RECORD_ACCESS_CHECK` decides single-record pages and writes. To
hide a record from lists, give it an owner the person does not see. A visibility above 1 000 owners is refused: use
`allowAll()` and record checks instead. The GDPR export of a contact lists every company it is affiliated to (also
companies the person exporting may not see), since it is the person's own data.

**In your own controllers**, `Nowo\CrmKitBundle\Infrastructure\Symfony\Security\CrmKitGuard` answers the same
questions: `requireGranted(CrmPermission, ?SubjectRef)` (404 when the CRM is not available, 403 when refused),
`isGranted()` and `grants()` (every permission's decision, for menus).

### Feature gate

```php
final readonly class PlanFeatureGate implements CrmFeatureGateInterface
{
    public function __construct(private Plans $plans) {}

    public function isEnabled(TenantRef $tenant): bool
    {
        return $this->plans->includes($tenant->value, 'crm');
    }
}
```

Off: every route and service answers 404 and nothing is rendered.

### Plan limits

```php
final readonly class PlanCrmLimits implements CrmPlanLimitsInterface
{
    public function __construct(private Plans $plans) {}

    public function limit(TenantRef $tenant, CrmResource $resource): ?int
    {
        return match ($resource) {
            CrmResource::Companies => $this->plans->limit($tenant->value, 'crm_companies'),
            CrmResource::Contacts  => $this->plans->limit($tenant->value, 'crm_contacts'),
        };   // null = unlimited
    }
}
```

The limit counts **active** records. The bundle enforces it with an atomic counter per tenant and resource, so two
creates at the limit can never both pass ([CONCURRENCY.md](CONCURRENCY.md#plan-limit-counters)).

### Actor directory

```php
final readonly class MemberDirectory implements CrmActorDirectoryInterface
{
    public function __construct(private Memberships $members) {}

    public function actor(TenantRef $tenant, ActorRef $actor): ?ActorProfile
    {
        $member = $this->members->find($tenant->value, $actor->value);

        // Must return exactly the requested ref: the bundle checks it with ===.
        return $member === null ? null : new ActorProfile($actor, $member->displayName(), $member->email());
    }

    public function owners(TenantRef $tenant): array
    {
        return array_map(
            static fn (Member $m): ActorProfile => new ActorProfile(new ActorRef($m->id()), $m->displayName()),
            $this->members->withPermission($tenant->value, 'crm.edit'),
        );
    }
}
```

`owners()` fills the owner pickers, and every owner a write stores is checked against it (an unknown owner is the form
error `error.owner_unknown`). `actor()` names note authors, owners and timeline actors.

### Actor resolver (0.2.1)

The actor id the CRM stores (owner, note author, created_by / updated_by, timeline, consent, import, merge and audit
actor) comes from `CrmActorResolverInterface::idOf()`. The default keeps `getUserIdentifier()`. When that identifier
can change (an e-mail), register a resolver that returns a stable id, and name people for the pages in one call:

```php
use Nowo\CrmKitBundle\Domain\Value\{ActorProfile, ActorRef};
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\CrmActorResolverInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final readonly class UuidActorResolver implements CrmActorResolverInterface
{
    public function __construct(private Users $users) {}

    public function idOf(UserInterface $user): string
    {
        return $user instanceof AppUser ? $user->uuid() : $user->getUserIdentifier();
    }

    public function resolve(array $ids): array   // one query per page
    {
        $profiles = [];
        foreach ($this->users->byUuids($ids) as $u) {
            $profiles[$u->uuid()] = new ActorProfile(new ActorRef($u->uuid()), $u->displayName(), $u->email());
        }

        return $profiles;
    }
}
```

```yaml
nowo_crm_kit:
    security:
        actor_resolver: App\Crm\UuidActorResolver
```

Each profile's ref must be exactly its key; ids the resolver leaves out are named by the actor directory. Switching an
existing installation to new ids needs a data migration: see [UPGRADING.md](UPGRADING.md#to-021).

- The resolver feeds the default access checker (`SecurityCrmAccessChecker`) and the default directory. A host
  `access_checker` of its own (or a 0.1.0 checker under `LegacyCrmAccessChecker`) must return `idOf($user)` from
  `currentActor()` itself.
- `nowo_crm_kit.record_visibility` and `record_access_check` listeners compare owners with these ids: add the new ids.
- `anonymised` (`ActorRef::ANONYMISED`, what anonymisation writes) is reserved: never return it from a checker,
  resolver, directory or listener. The bundle refuses it anyway (no actor, no owner, no visible owner), and a
  signed-in person is never the demo's shared actor `anonymous`.

### Audit sink

```php
final readonly class CrmAudit implements CrmAuditSinkInterface
{
    public function __construct(private UserActionRecorder $recorder) {}

    public function record(AuditEntry $entry): void
    {
        $this->recorder->record($entry->tenant->value, $entry->actor?->value, $entry->action, [
            'subject_type' => $entry->subjectType,
            'subject_id'   => $entry->subjectId,
        ] + $entry->details);
    }
}
```

Called after the commit. `action` is a `crm.*` key; `details` carry ids, counts and codes only, never names, e-mails
or note text. Keys written in 0.1.0: `crm.company.{created,updated,trashed,restored,purged}`, the same for
`crm.contact.*`, `crm.contact.exported`, `crm.contact.erased`, `crm.records.merged`, `crm.consent.recorded`,
`crm.note.deleted`, `crm.tag.{created,changed,deleted}`, `crm.field.{defined,changed,archived,restored}`,
`crm.import.{uploaded,completed,cancelled}`, `crm.settings.changed`, `crm.retention.purged` (no actor).

### Panel providers

A panel shows your own read model on a company or contact page. Implement the interface; autoconfiguration adds the
tag `nowo_crm_kit.panel`.

```php
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Port\CrmPanelProviderInterface;
use Nowo\CrmKitBundle\Domain\Value\{Panel, PanelContext, PanelItem};

final readonly class ProjectsPanel implements CrmPanelProviderInterface
{
    public function __construct(private ProjectReader $projects) {}

    public function key(): string
    {
        return 'projects';   // ^[a-z0-9_-]{1,40}$, otherwise the panel is dropped
    }

    public function supports(SubjectType $type): bool
    {
        return $type === SubjectType::Company;
    }

    public function panel(PanelContext $context): ?Panel
    {
        $ids = [];
        foreach ($context->links as $link) {           // the host objects linked to the record
            if ($link->type === 'project') {
                $ids[] = $link->id;
            }
        }
        // Check your own permissions for $context->actor in $context->tenant.
        $rows = $this->projects->visible($context->tenant->value, $context->actor?->value, $ids);

        return new Panel(
            'crm.panel.projects',                       // translation key ...
            array_map(static fn ($p): PanelItem => new PanelItem($p->name, '/projects/' . $p->id, $p->client, $p->status, 'info'), $rows),
            domain: 'messages',                         // ... of this domain (null: the title is plain text)
            total: count($rows),
            moreUrl: '/projects?company=' . $context->subject->id,
            position: Panel::SIDE,                      // or Panel::MAIN
        );
    }
}
```

Return `null` to show nothing. Item URLs are rendered only when they are local paths or http(s) URLs; `moreUrl` only
when it is a local path. A provider that throws is logged (provider class and exception class only) and skipped; a
transient storage failure is rethrown so the write unit can replay. Every text is rendered escaped.

### Host object resolvers

Host links store `(record, host type, host id, label)`. A resolver per host type turns ids into labels and URLs on
every view and decides what the actor may see. Autoconfiguration adds the tag `nowo_crm_kit.host_object`.

```php
use Nowo\CrmKitBundle\Domain\Port\CrmHostObjectResolverInterface;
use Nowo\CrmKitBundle\Domain\Value\{HostObjectView, TenantRef};

final readonly class ProjectResolver implements CrmHostObjectResolverInterface
{
    public function __construct(private ProjectReader $projects) {}

    public function type(): string
    {
        return 'project';    // ^[a-z][a-z0-9_.-]{0,63}$
    }

    public function resolve(TenantRef $tenant, array $ids): array
    {
        $views = [];
        foreach ($this->projects->visibleByIds($tenant->value, $ids) as $p) {
            $views[$p->id] = new HostObjectView($p->id, $p->name, '/projects/' . $p->id);   // key === requested id
        }

        return $views;
    }

    public function search(TenantRef $tenant, string $text, int $limit): array
    {
        return [];   // reserved for a link picker; the 0.1.0 UI does not call it
    }
}
```

Rules on the 360° page:

- a type **with** a resolver: a link the resolver returns (same id, byte for byte) shows its label and URL; a link it
  does not return (deleted, other tenant, no permission) is **hidden**;
- a type **without** a resolver: the link shows its stored label (or "type id") **without** a URL;
- the UI's "add link" form offers only the types that have a resolver (the host id is typed); the service
  `HostLinkServiceInterface::link()` accepts any valid type.

Return only `http(s)` URLs or local paths from `resolve()`: the template renders the URL as given.

## Services a host calls

Interfaces in `Nowo\CrmKitBundle\Domain\Service` (public container aliases). Ids are lowercase UUID strings. Every call
resolves the tenant and the actor and checks the permission itself (404 / 403); writes are write units.

| Service | Methods |
| --- | --- |
| `RecordServiceInterface` | `create(RecordInput): string`, `update(string $id, RecordInput, int $expectedVersion): int`, `trash(SubjectType, string $id, int $expectedVersion)`, `restore(SubjectType, string $id)`, `purge(SubjectType, string $id)` (`manage`), `affiliate(string $contactId, string $companyId, ?string $role, bool $primary, ?bool $primaryContact = null, ?list $purposes = null): string`, `unaffiliate(string $affiliationId)`, `setPrimaryContact(string $companyId, ?string $contactId)`, `setRoles(SubjectType, string $id, list<string> $roles)` |
| `CrmCatalogueInterface` | `roles(): list<string>`, `purposes(): list<string>` (the configured keys) |
| `CrmReaderInterface` | `record(SubjectType, id): RecordView`, `list(ListQuery): ListResult`, `timeline(SubjectType, id, int $limit = 50, ?string $before)`, `duplicates`, `notes`, `searchNotes(string $query, int $limit = 20): list<NoteHit>` (0.5.0), `notesOf(list<SubjectRef>, int $perSubject = 5): array<key, list<NoteView>>` (0.5.0), `links`, `panels`, `consents(contactId)`, `tags()`, `fields(SubjectType, bool $withArchived)`, `owners()`; see [Searching notes](#searching-notes-050) |
| `TagServiceInterface` | `create(name, colour): string`, `change(id, name, colour, int $v)`, `delete(id)` (`manage`); `assign(SubjectType, id, list<tagId>)` (`edit`, set semantics) |
| `FieldServiceInterface` | `define(SubjectType, key, label, FieldType, choices, required, position): string`, `change(id, label, choices, required, position, int $v)`, `archive(id, bool $archived, int $v)` (`manage`) |
| `NoteServiceInterface` | `add(SubjectType, subjectId, body, pinned): string`, `edit(id, body, pinned, int $v)`, `delete(id)` |
| `HostLinkServiceInterface` | `link(SubjectType, subjectId, HostObjectRef, ?label): string` (idempotent), `unlink(linkId)`, `subjectsLinkedTo(HostObjectRef): list<SubjectRef>` ("companies of project 42") |
| `TimelineInterface` | `record(SubjectType, subjectId, labelKey, params, ?url)`: a host entry (`labelKey` of the host's `messages` domain, `^[a-z0-9_.-]{1,120}$`; ≤ 20 scalar params with keys `^[a-z][a-z0-9_]{0,39}$`, strings ≤ 200; `url` a local path) |
| `ConsentServiceInterface` | `record(contactId, purpose, bool $granted, ConsentSource, ?evidence): string`, `current(contactId): array<purpose, bool>` |
| `ImportServiceInterface` | `upload(SubjectType, ImportFormat, fileName, bytes): string`, `preview(id, rows = 20): ImportView`, `previewMapping(id, mapping, rows = 20): ImportView` (0.4.0, stored nowhere), `unfinished(limit = 5): array{total, imports}` (0.4.0), `confirm(id, mapping, DuplicatePolicy, int $v)`, `run(id, ?int $maxChunks): bool`, `cancel(id)` |
| `MergeServiceInterface` | `preview(SubjectType, survivorId, otherId): MergePreview`, `merge(SubjectType, survivorId, otherId, list<field> $takeFromOther, int $survivorVersion, int $otherVersion)` |
| `CrmExporterInterface` | `csv(ListQuery): iterable<string>` (`manage`), `vcard(contactId): string` (`view`) |
| `CrmPersonalDataInterface` | `export(contactId): array`, `erase(contactId)` (`manage`, idempotent) |
| `CrmSettingsInterface` | `get(): array{trash_days, timeline_years, import_keep_days, merge_keep_days, version}`, `update(int ×4, int $expectedVersion)` (`manage`) |
| `CrmBatchLookupInterface` (0.2.1) | a **system API without access checks** for the host's backend jobs: see [Batch lookups for host jobs](#batch-lookups-for-host-jobs) |
| `CrmActorPersonalDataInterface` (0.2.1) | a **system API without tenant scope or access checks**: `exportActor()`, `anonymiseActor()`; see [A person's references](#a-persons-references-export-and-anonymisation) |

### Searching notes (0.5.0)

For a host feature that reads across records (Beacon's *Ask AI* over the CRM, a global search), `CrmReaderInterface`
has two reads of notes beside `notes(SubjectType, id)`:

```php
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Value\SubjectRef;

// Notes of the tenant's active companies and contacts that contain the text, newest first.
foreach ($reader->searchNotes('renewal budget', 20) as $hit) {
    $hit->subjectType;   // SubjectType::Company or SubjectType::Contact
    $hit->subjectId;     // the record's id (ids, never entities)
    $hit->excerpt;       // up to 200 characters around the first match, '…' on each cut side
    $hit->note;          // NoteView: id, body, pinned, author id and name, version, createdAt, updatedAt
}

// The latest notes of several records at once (pinned first, then newest), keyed by SubjectRef::key().
$notes = $reader->notesOf([
    SubjectRef::of(SubjectType::Company, $companyId),
    SubjectRef::of(SubjectType::Contact, $contactId),
], perSubject: 5);
$notes['company:' . $companyId]; // list<NoteView>, or no key at all
```

- **Access.** Both need `view` in the tenant (`AccessDeniedException` / `NotAvailableException` otherwise, as `list()`).
  Every record a note is on goes through the same per-record check as `notes()` (the access checker, so every
  `nowo_crm_kit.record_access_check` listener) and through the owner scoping of the lists
  (`nowo_crm_kit.record_visibility`), decided for each batch of rows **before** a row counts towards the limit: a
  hidden record shows nowhere, not in the hits, their number or an excerpt.
- **Which records.** `searchNotes()` reads active records only (not trashed, merged or erased ones, as the lists).
  `notesOf()` answers active and trashed records (as their page does); an unknown, erased, merged or hidden record, a
  deal reference or another tenant's id gets no key, with no hint which. An erased contact has no notes left (erasure
  deletes them); a note whose author was anonymised shows the placeholder author id and no name, as on the record's
  page.
- **Search.** A case-insensitive portable `LIKE` on the note text: `%`, `_`, `\` and the escape character are literal.
  The text is trimmed and cut to 200 characters; under 2 characters nothing is searched (`[]`). `$limit` is clamped to
  1–50. Matching notes are read in rounds of 50, at most 10 rounds (500 matches looked at): a search whose first 500
  matches are all on hidden records returns fewer hits than the limit.
- **Cost.** `searchNotes()`: per round one note query and one record query per type. `notesOf()`: per 100 records one
  record query per type, one query for the note ids (`ROW_NUMBER()`, MySQL 8 / MariaDB 10.2 / PostgreSQL / SQLite
  3.25) and one for the notes, plus one author-name lookup; `$perSubject` is clamped to 1–50. Nothing is kept between
  calls (FrankenPHP worker mode).

### Creating and editing a record

```php
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Service\{RecordInput, RecordServiceInterface};

$companyId = $records->create(new RecordInput(SubjectType::Company, [
    'name'    => 'Acme Ltd',
    'website' => 'https://www.acme.example',
    'tax_id'  => 'B-12.345.678',
], owner: $userId));

$contactId = $records->create(new RecordInput(SubjectType::Contact, [
    'first_name' => 'Ana',
    'last_name'  => 'Ruiz',
    'email'      => 'ana@acme.example',
], custom: ['vat_regime' => 'general'], tagIds: [$tagId]));

$records->affiliate($contactId, $companyId, 'Buyer', primary: true);

// Edit: only the keys given change; setOwner: false keeps the owner; custom/tagIds null keep them.
$view = $reader->record(SubjectType::Contact, $contactId);
$records->update($contactId, new RecordInput(SubjectType::Contact, ['job_title' => 'Head of purchasing'], setOwner: false), $view->version);
```

Core fields: companies `name` (required), `legal_name`, `tax_id`, `website` (http(s)), `email`, `phone`, `industry`,
`street`, `postal_code`, `city`, `region`, `country` (ISO 3166-1 alpha-2), `description`; contacts `first_name`,
`last_name` (one of the two required), `email`, `phone`, `mobile`, `job_title` and the address fields. Values are
trimmed, empty strings become null, and every error is collected into one `InvalidInputException` (field ⇒
translation key).

### Linking from a host page

```php
$links->link(SubjectType::Company, $companyId, new HostObjectRef('project', '42'), 'Website relaunch');

foreach ($links->subjectsLinkedTo(new HostObjectRef('project', '42')) as $subject) {
    $view = $reader->record($subject->type, $subject->id->value);   // active records of the current tenant only
}

$timeline->record(SubjectType::Company, $companyId, 'crm.timeline.invoice_sent', ['number' => 'F-2026-0042'], '/invoices/77');
```

## Events

Plain classes in `Nowo\CrmKitBundle\Domain\Event`, dispatched through the Symfony event dispatcher **after the
commit** (a rollback or a replay drops them). Each extends `CrmEvent` (`tenant`, nullable `actor`, `occurredAt`) and
carries ids and VOs only.

| Event | Extra data |
| --- | --- |
| `RecordCreated`, `RecordTrashed`, `RecordRestored`, `RecordPurged` | `type`, `id` |
| `RecordsPurged` (0.2.1) | `subjects` (`list<SubjectRef>`, type + id, no owner); `actor` is null |
| `RecordUpdated` | `type`, `id`, `changed` (field names) |
| `RecordsMerged` | `type`, `survivorId`, `mergedId` |
| `ContactErased` | `contactId` |
| `NoteAdded` | `type`, `subjectId`, `noteId` |
| `ConsentRecorded` | `contactId`, `purpose`, `granted` |
| `ImportCompleted` | `type`, `importId`, `counts` (per outcome) |
| `TagsChanged` | `type`, `id`, `added`, `removed` (tag ids) |
| `RolesChanged` (0.2.0) | `type`, `id`, `added`, `removed` (role keys) |
| `HostLinkAdded`, `HostLinkRemoved` | `type`, `id`, `hostType`, `hostId` |

```php
#[AsEventListener]
final readonly class ProjectClientSync
{
    public function __invoke(RecordsMerged $event): void
    {
        // Point your own references at $event->survivorId.
    }
}
```

The event names are the class names; `Infrastructure\Symfony\Event\CrmKitEvents` has a constant for each (0.2.1):
`RECORD_CREATED`, `RECORD_UPDATED`, `RECORD_TRASHED`, `RECORD_RESTORED`, `RECORD_PURGED`, `RECORDS_MERGED`,
`CONTACT_ERASED`, `NOTE_ADDED`, `CONSENT_RECORDED`, `IMPORT_COMPLETED`, `TAGS_CHANGED`, `ROLES_CHANGED`,
`HOST_LINK_ADDED`, `HOST_LINK_REMOVED` (e.g. `#[AsEventListener(event: CrmKitEvents::CONTACT_ERASED)]`).

What a host that keeps its own copy of links, e-mails or affiliations listens to:

| Change | Event | Notes |
| --- | --- | --- |
| A link removed by `unlink()` | `HostLinkRemoved` | record `type` + `id`, `hostType` + `hostId` |
| A record merged into another | `RecordsMerged` | `type`, `survivorId` (to), `mergedId` (from); the merged record's links, roles, tags and affiliations moved to the survivor (no `HostLinkRemoved` / `HostLinkAdded` for them) |
| A contact erased (GDPR) | `ContactErased` | `contactId`; its links, tags, roles and affiliations are deleted with it (no `HostLinkRemoved` for them) |
| A record trashed | `RecordTrashed` | it leaves every batch lookup at once (except `rolesAndTagsOf()`); `RecordRestored` brings it back |
| A trashed record purged with `purge()` | `RecordPurged` | one event per record; its links, tags, roles and affiliations are deleted with it |
| Records deleted by the scheduled retention purge (`nowo:crm-kit:purge`) | `RecordsPurged` (0.2.1) | one event per committed batch (at most 200 records of one type: trashed records past `trash_days`, merge tombstones past `merge_keep_days`), after that batch's commit; a batch that rolls back announces nothing. Delete the rows you derived from these ids (GDPR) |

There is no `CompanyErased`: companies hold no personal data and are not erased; a company leaves through the trash
(`RecordTrashed`, then `RecordPurged` or `RecordsPurged`). As a safety net for missed events, a nightly sweep can
pass the ids it holds to `CrmBatchLookupInterface::existing()` and drop those that are gone.

## A person's references (export and anonymisation)

`CrmActorPersonalDataInterface::exportActor($actorId)` (its own service, so the tenant-scoped
`CrmPersonalDataInterface` stays safe for GDPR pages) lists every CRM reference to a host person **in every tenant**
(tenant ⇒ kind such as `company.owner`, `note.author`, `activity.actor` ⇒ row keys); `anonymiseActor($actorId)`
replaces each with `anonymised` in batches of 200 rows, one transaction each, and returns how many it replaced (0
when run again). Records, notes and everyone else's data stay. After the commits it dispatches `ActorAnonymised`
(`CrmKitEvents::ACTOR_ANONYMISED`: tenant and count, never the id) and audits `crm.actor.anonymised` per tenant.

> **System API.** No tenant, no actor and no permission check: call it only from trusted server-side code (an
> account-deletion job), never from a page, an API endpoint or an agent tool (MCP) a person can reach. Audit entries
> your sink already stored are yours to anonymise.

## Batch lookups for host jobs

`CrmBatchLookupInterface` (0.2.1, public alias) answers a host's backend jobs ("which customers does this e-mail
thread, domain or issue concern?") with **one read query per call** (per chunk of `CrmBatchLookupInterface::CHUNK` =
500 inputs; a longer list runs one query per chunk, never one per input).

> **System API.** Every method takes the `TenantRef` explicitly and is scoped to it, but it does **not** resolve the
> tenant, the feature gate or the actor, and checks **no permission** and **no record visibility**
> (`security.resource_scoping`). It is meant for trusted server-side code (queue consumers, cron jobs). Never pass it a
> tenant or input taken from a request, and never show its results to a person, without your own checks
> ([SECURITY.md](SECURITY.md#host-extension-points)). For per-user reads use `CrmReaderInterface` /
> `HostLinkServiceInterface`.

| Method | Result |
| --- | --- |
| `contactsByEmails(TenantRef, list<string> $emails)` | `array<emailKey, list<contactId>>`. Keys are the inputs trimmed and lowercased (`EmailAddress::lookupKey()`, the stored contact e-mail key). The key is unique per tenant, so a list holds at most one id today; it stays a list. |
| `companiesByDomains(TenantRef, list<string> $domains)` | `array<domainKey, list<companyId>>` (ascending). Keys are `CompanyDomain::key()`: the lowercase host without `www.`, as stored from the website; a URL is accepted. Several companies may share a domain. No free-mail filter: that is the caller's concern. |
| `subjectsLinkedToMany(TenantRef, list<HostObjectRef>)` | `array<hostKey, list<SubjectRef>>`, the batch form of `subjectsLinkedTo()`. `hostKey` is `HostObjectRef::key()`: `<type>:<id>` (the type never holds `:`, so the first `:` splits it). Link creation order; each `SubjectRef` carries the owner. |
| `affiliationsOf(TenantRef, list<string> $contactIds)` | `array<contactId, list<companyId>>`: the primary affiliation first, then by affiliation date, then company id. |
| `existing(TenantRef, list<SubjectRef>)` | `list<SubjectRef>`: the given records whose row still exists in the tenant, **whatever its status** (active, trashed, merge tombstone, erased contact); purged or unknown ids are left out. Input order, first occurrence, the input objects as given. For reconciliation sweeps: what is missing is gone for good. |
| `rolesAndTagsOf(TenantRef, list<SubjectRef>)` | `array<subjectKey, RolesAndTags>`; `subjectKey` is `SubjectRef::key()` (`company:<id>` / `contact:<id>`); `RolesAndTags::$roles` holds the configured role keys in catalogue order, `$tags` the tag name keys (lowercase) in byte order. |

Shapes: every usable input is a key of the result, with an empty list (or empty `RolesAndTags`) when nothing matched;
duplicates collapse; unusable inputs (an empty or invalid address, a host-less domain, an id that is not a lowercase
UUID) are left out. Only **active** records are returned: trashed records, merge tombstones and erased contacts never
are (an affiliation counts when both the contact and the company are active). `rolesAndTagsOf()` and `existing()` read
the rows of the given records whatever their status. Read-only: no write unit, no event, no lock; stateless (worker mode).

```php
use Nowo\CrmKitBundle\Domain\Service\CrmBatchLookupInterface;
use Nowo\CrmKitBundle\Domain\Value\{HostObjectRef, TenantRef};

final readonly class AffectedCustomersJob
{
    public function __construct(private CrmBatchLookupInterface $crm)
    {
    }

    public function __invoke(string $organisationId, array $senderEmails, array $issueIds): void
    {
        $tenant   = new TenantRef($organisationId);                    // from the job's own trusted payload
        $contacts = $this->crm->contactsByEmails($tenant, $senderEmails);
        $linked   = $this->crm->subjectsLinkedToMany($tenant, array_map(
            static fn (string $id): HostObjectRef => new HostObjectRef('issue', $id),
            $issueIds,
        ));
        $companies = $this->crm->affiliationsOf($tenant, array_merge(...array_values($contacts)));
        // …
    }
}
```

## Errors

| Exception (`Domain\Exception`, or `Application\Shared`) | Meaning | HTTP on the bundle's routes |
| --- | --- | --- |
| `NotAvailableException` | no tenant, feature off, no actor, unknown id or another tenant's record | 404 |
| `AccessDeniedException` | permission refused inside an enabled tenant | 403 |
| `ConflictException` (`errorCode`) | domain conflict: `crm_record_stale`, `crm_record_merged` (`recordId` = survivor), `crm_record_trashed`, `crm_merge_stale`, `crm_import_running`, `crm_limit_reached`, `crm_settings_stale`, `crm_write_conflict` (a racing writer's unique row that one more attempt did not settle; nothing committed, a retry is safe) | 409, no `Retry-After`, no `X-Conflict-Code` |
| transaction-doctrine's `ConcurrencyConflict` | a lock conflict: another writer held the rows and the replays did not settle it | 409 `CONFLICT_001` + `Retry-After` + `X-Conflict-Code` (`CONFLICT_002` when the request already committed part of its work) |
| `LogicException` | a write called inside a transaction that is already open, or with pending host changes on a shared entity manager | 500 (a programming error: call the CRM outside your transaction) |
| `InvalidInputException` (`errors`: field ⇒ key) | input refused | form errors; 422 (JSON) / 400 |
| `ImportFileException` | file refused at upload (size, encoding, header, rows) | form error |

Forms of the UI handle the expected cases themselves: a stale record form is shown again with the current values,
a flash and status 409; POST actions (trash, restore, notes, tags, links, consent) flash the conflict and redirect.

## Roles and affiliation purposes

Since 0.2.0 ([CONFIGURATION.md](CONFIGURATION.md#roles-and-affiliation-purposes), contract section 6.8).

- **Roles** say what a party is to the tenant: a company or a contact holds any number of them (`customer`,
  `supplier`, `partner` built in; `distributor` and keys of your own by configuration). One entity serves customers
  and suppliers: a supplier that also buys is a company with both roles. A private customer is a contact with the
  `customer` role and no company. Prospect and former customer are lifecycle stages of the customer role (funnels,
  0.3.0); a competitor is a tag.
- **The company's primary contact**: at most one per company, set from the company page ("Make primary contact",
  "Remove as primary contact"), in the affiliation form of the contact (an unticked box keeps it), through `setPrimaryContact()` / `affiliate(…, primaryContact: true)` or by an
  import column; the previous one is cleared.
- **Purposes** say what a contact is the company's contact for (several per affiliation).

```php
use Nowo\CrmKitBundle\Domain\Service\ListQuery;

$supplierId = $records->create(new RecordInput(SubjectType::Company, ['name' => 'Parts Ltd'], roles: ['supplier']));
$records->setRoles(SubjectType::Company, $supplierId, ['supplier', 'customer']);   // set semantics

$records->affiliate($contactId, $supplierId, 'Accounts', primary: true, primaryContact: true, purposes: ['billing']);
$records->setPrimaryContact($supplierId, $otherContactId);                          // clears the previous one

$suppliers = $reader->list(new ListQuery(SubjectType::Company, role: 'supplier'));
$billing   = $reader->list(new ListQuery(SubjectType::Contact, companyId: $supplierId, purpose: 'billing'));
$primaries = $reader->list(new ListQuery(SubjectType::Contact, role: 'customer', primaryContactOnly: true));
```

`RecordView::$roles` holds the configured role keys of a record (catalogue order) and every `AffiliationView` its
`primaryContact` flag and `purposes`. A key removed from the configuration stays in the database but is hidden; the
GDPR export lists every stored role. The list pages filter by `role` (and, for contacts, `purpose` and
`primary_contact=1`); unknown keys in the URL are ignored, while `ListQuery` itself refuses a string that is not a
valid key (`InvalidInputException`). An affiliation edit replaces only the configured purposes; stored ones no longer
configured stay.

## Pages and routes

Under the import prefix and `ui.path` (default `/crm`); names `nowo_crm_kit_<name>`; `{type}` is `companies` or
`contacts`. Every POST carries a CSRF token.

| Name | Path | Methods | Permission |
| --- | --- | --- | --- |
| `home` | `/` | GET | redirect to companies |
| `list` | `/{type}` (`q`, `tag`, `role`, `owner`, `status=trashed`, `sort=name\|created\|updated`, `direction`, `page`, `per_page`; contacts also `purpose`, `primary_contact=1`) | GET | view |
| `new` | `/{type}/new` | GET, POST | edit |
| `show` | `/{type}/{id}` (a merged id redirects to the survivor) | GET | view |
| `edit` | `/{type}/{id}/edit` | GET, POST | edit |
| `trash`, `restore` | `/{type}/{id}/trash`, `/{type}/{id}/restore` | POST | edit |
| `purge` | `/{type}/{id}/purge` | POST | manage |
| `merge` | `/{type}/{id}/merge` (pick; `?other=<id>` preview and confirm) | GET, POST | edit on both |
| `export` | `/{type}/export.csv` (the list's filters) | GET | manage |
| `note_add` | `/{type}/{id}/notes` | POST | edit |
| `note_edit`, `note_delete` | `/notes/{id}/edit`, `/notes/{id}/delete` | POST | edit (own) / manage |
| `tags_assign` | `/{type}/{id}/tags` | POST | edit |
| `link_add`, `link_remove` | `/{type}/{id}/links`, `/links/{id}/remove` | POST | edit |
| `affiliate`, `unaffiliate` | `/contacts/{id}/affiliations`, `/affiliations/{id}/remove` | POST | edit |
| `primary_contact`, `primary_contact_clear` (0.2.0) | `/companies/{id}/primary-contact/{contact}`, `/companies/{id}/primary-contact/clear` (action token) | POST | edit on the company |
| `consent` | `/contacts/{id}/consent` | POST | edit |
| `vcard` | `/contacts/{id}/vcard` | GET | view |
| `gdpr_export`, `erase` | `/contacts/{id}/gdpr-export` (JSON download), `/contacts/{id}/erase` | POST (both with the action token `crm_action[_token]`) | manage |
| `import`, `import_show` | `/import` (`?subject=contact\|company`), `/import/{id}` (preview, confirm; `do=preview` re-checks the rows with the posted mapping, `do=run` continue, `do=cancel`); 0.4.0: also the import modal's steps | GET, POST | import |
| `tags`, `tag_edit` | `/admin/tags`, `/admin/tags/{id}` (rename, recolour, delete) | GET, POST | manage |
| `fields`, `field_edit` | `/admin/fields`, `/admin/fields/{id}` (change, archive) | GET, POST | manage |
| `settings` | `/admin/settings` | GET, POST | manage |

Templates live under `@NowoCrmKit/` and extend UiKit's page templates; a host overrides one through Symfony's bundle
template path (`templates/bundles/NowoCrmKitBundle/…`) if it must, but the brand comes from UiKit tokens.

## Imports

0.4.0: the lists open the import in a **modal** from their page action **Import** (shown only to someone who may
import; its href is the import page, which stays the no-JS fallback). The modal is UiKit's `confirm_dialog` with its
anatomy at the wide size (0.4.1: a full-screen sheet on phones, the steps in its header); the steps below run inside it (file → mapping → preview → result), errors show in place, and
an expired session shows a reload notice instead of the login page. An unfinished import (still running after
`import.sync_rows`, waiting for a person, or stopped by a conflict) stays reachable: the result step and the conflict /
expired notices link its page, and the lists show the person a notice linking their unfinished imports
(`ImportServiceInterface::unfinished()`, the acting person's running imports in the tenant). The lists load `js/nowo-crm-import.js` themselves
(`asset(…, 'nowo_crm_kit')`; run `assets:install`). Wire protocol, for a host that drives the modal itself:

- request header `X-Nowo-Crm-Dialog: import` on the import routes ⇒ the step fragment (`import/dialog.html.twig`,
  response header `X-Nowo-Crm-Fragment: import-step`, `[data-part="steps"]` (0.4.1, the header's steps),
  `[data-part="body"]` and `[data-part="footer"]`) instead of the
  page; 422 / 409 answer the fragment with the errors;
- a step that would redirect answers 204 + `X-Nowo-Crm-Dialog-Location: <next step>`;
- nobody signed in ⇒ 401 `{"error": "session_expired", "reload": true}`; lock conflicts ⇒ 409 JSON with
  `CONFLICT_001` / `CONFLICT_002` and `X-Conflict-Code` ([CONCURRENCY.md](CONCURRENCY.md)).

1. **Upload** (`import`): a CSV (UTF-8, BOM stripped, `,` or `;`, header row, unique non-empty column names) or a vCard
   3.0 / 4.0 file (contacts only; `N`, `FN`, `EMAIL`, `TEL` (`CELL` ⇒ mobile), `TITLE`, `ORG`, `ADR`, first value of
   each). At most `import.max_rows` rows and `import.max_bytes` bytes. Cells are text; nothing is evaluated.
2. **Preview**: the first 20 rows checked against the detected mapping (field names and common aliases such as
   `e-mail`, `surname`, `zip`, `vat`), errors per row and, for contacts, the existing contact with the same e-mail.
   The mapping is editable: a core field, `custom.<key>`, `tags` (comma-separated names of **existing** tags) or, for
   contacts, `company` (affiliates to an existing active company with exactly that name, case-insensitive), or skip.
   0.2.0: `roles` (role keys separated by `,` or `;`, both types; added to the stored ones), and for contacts
   `purposes` (purpose keys of the affiliation to the named company, added to the stored ones) and `primary_contact`
   (`yes` / `no`, also `1`, `true`, `x` / empty, `0`, `false`: makes the contact the company's primary contact; a row
   that changes it needs `edit` on that company, else it is `invalid` with `error.forbidden`).
   Keys must equal configured keys exactly; an unknown key or an unreadable yes / no makes the row `invalid`.
3. **Confirm** with the duplicate policy: `skip` (a contact whose e-mail exists becomes `duplicate`) or `update` (the
   existing active contact is updated; a trashed one stays `duplicate`). Companies have no duplicate key: every valid
   row creates a company.
4. **Run**: the confirm request runs `import.sync_rows` rows in chunks of `import.chunk_rows`; a larger import shows
   "Continue" (`do=run`, the same amount again). A worker that stopped leaves the import `running`;
   `nowo:crm-kit:import:resume` takes over claims older than `import.stale_claim_seconds`; with nobody signed in it
   stops before a row that updates an existing contact or names a company, which waits for a person's "Continue". Rows end as `created`,
   `updated`, `duplicate`, `invalid` (with the first error key) or `limit` (plan limit reached). Each chunk first
   locks the existing contacts and the named companies of all its rows (contacts, then companies, each in id order),
   then settles the rows ([CONCURRENCY.md](CONCURRENCY.md#import)).

No Messenger handler ships in 0.1.0: imports always run in requests and in the resume command.

## The API (0.3.0)

The `Api\Operation` services and the `/crm/v1` JSON API are off by default. [API.md](API.md) covers:

- the principal, the grant and the order of checks;
- every operation and the duplicate check of creates;
- idempotency keys, base versions and the audit with the token name;
- sealed keyset cursors, untrusted text and the error codes;
- what is never exposed;
- that API company creates behave like the pages (a company the principal may not view is never a duplicate), and
  that every list, search, label and timeline hides records a `nowo_crm_kit.record_access_check` listener denies;
- the setup for a plain Symfony host and for an MCP host.

## SearchKit

With `nowo-tech/search-kit-bundle` **^1.9** installed (it needs PHP >= 8.3), `ui.enabled` and `search.enabled`
(default), the bundle registers three groups on SearchKit's entity provider base (`EntitySearchDefinition`, tag
`nowo_search_kit.entity_definition`): `crm_companies` (priority 60), `crm_contacts` (59) and, with
`pipeline.enabled`, `crm_deals` (58). On every search each group:

- resolves the tenant and the feature gate of the CRM and checks `view` (any refusal: no group, no count, no hint);
- searches only rows of that tenant, in SQL: active companies (name, legal name, tax id key, website domain, e-mail),
  active contacts (sort and display name, e-mail key, phone and mobile as typed) or deals outside the trash (title,
  company name, stage name; never the amount), with escaped `LIKE` wildcards and the record visibility of
  `security.resource_scoping` (ownerless records and the visible owners);
- then asks `nowo_crm_kit.record_access_check` for every candidate (at most SearchKit's candidate window, 100), so a
  host deny hides the row and it is not counted; SearchKit's own `nowo_search_kit.item_visibility` event follows;
- shows a "see all" link to the filtered list (`/companies?q=`, `/contacts?q=`, `/deals?q=`) and the visible total.

A scope that is the id of an active company of the tenant narrows contacts to that company's; any other scope, and any
scope on companies or deals, finds nothing. A deal shows its stage, its company (only when the person may view the
company: a hidden company's name is neither shown nor used as a match) and, only with `amounts_view`, its amount.
Recents: with SearchKit's `nowo_search_kit.recent.safe` on (revalidated recents), a stored result is shown again only
while its record is still visible. `crm_contacts` is a sensitive group (contact names are personal data): SearchKit
runs it only while recents are revalidated or off, so contact names are never kept in the browser unchecked.
`crm_companies` and `crm_deals` are not sensitive. A host's `nowo_crm_kit.record_access_check` deny is decided after
the SQL filter, so a denied row can take a place in the candidate window: it is never shown or counted, but the total
is then marked inexact. Web-list totals are the SQL count minus the current page's denied rows: a denied row still takes its slot of the
page (the page is shorter), a page can be empty while the pager offers more, and the total can differ from page to
page. That reveals where denied rows sit in the order, never their content.

Switch one group off, or reorder the groups, with SearchKit's own `nowo_search_kit.groups.<key>` (`enabled`,
`priority`); `search.enabled: false` registers none.

## UiKit context menus and focus mode

Lists use UiKit's `ListPage` with row actions in a kebab and `responsive: cards` on phones; list and record pages put
UiKit's `focus_mode_toggle` partial in their toolbar. Both features are the host's choice in its UiKit configuration:

```yaml
# config/packages/nowo_ui_kit.yaml
nowo_ui_kit:
    theme:
        context_menus: true          # row actions also open on right-click, the ContextMenu key and long press
        focus_mode:
            enabled: true            # the toggle renders; false: it renders nothing
```
