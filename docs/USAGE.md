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
  - [Audit sink](#audit-sink)
  - [Panel providers](#panel-providers)
  - [Host object resolvers](#host-object-resolvers)
- [Services a host calls](#services-a-host-calls)
- [Events](#events)
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

```php
use Nowo\CrmKitBundle\Domain\Enum\CrmPermission;
use Nowo\CrmKitBundle\Domain\Port\CrmAccessCheckerInterface;
use Nowo\CrmKitBundle\Domain\Value\{ActorRef, SubjectRef, TenantRef};

final readonly class OrganizationCrmAccess implements CrmAccessCheckerInterface
{
    public function __construct(private Security $security, private Memberships $members) {}

    public function isGranted(CrmPermission $permission, TenantRef $tenant, ?SubjectRef $subject = null): bool
    {
        $actor = $this->currentActor();
        if ($actor === null) {
            return false;
        }
        // $subject (type, id, owner) is set for a check on one record: e.g. "own companies only".
        return $this->members->has($tenant->value, $actor->value, 'crm.' . $permission->value);
    }

    public function currentActor(): ?ActorRef
    {
        $id = $this->security->getUser()?->getUserIdentifier();

        return $id === null ? null : new ActorRef($id);
    }
}
```

Deny by default: return `false` for anything you do not grant. `edit` and `manage` do not imply `view`; the bundle asks
for exactly the permission of the action ([SECURITY.md](SECURITY.md#permission-matrix)). With `$subject === null` the
bundle asks for the tenant-wide grant (lists, imports, settings, administration).

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
| `CrmReaderInterface` | `record(SubjectType, id): RecordView`, `list(ListQuery): ListResult`, `timeline(SubjectType, id, int $limit = 50, ?string $before)`, `duplicates`, `notes`, `links`, `panels`, `consents(contactId)`, `tags()`, `fields(SubjectType, bool $withArchived)`, `owners()` |
| `TagServiceInterface` | `create(name, colour): string`, `change(id, name, colour, int $v)`, `delete(id)` (`manage`); `assign(SubjectType, id, list<tagId>)` (`edit`, set semantics) |
| `FieldServiceInterface` | `define(SubjectType, key, label, FieldType, choices, required, position): string`, `change(id, label, choices, required, position, int $v)`, `archive(id, bool $archived, int $v)` (`manage`) |
| `NoteServiceInterface` | `add(SubjectType, subjectId, body, pinned): string`, `edit(id, body, pinned, int $v)`, `delete(id)` |
| `HostLinkServiceInterface` | `link(SubjectType, subjectId, HostObjectRef, ?label): string` (idempotent), `unlink(linkId)`, `subjectsLinkedTo(HostObjectRef): list<SubjectRef>` ("companies of project 42") |
| `TimelineInterface` | `record(SubjectType, subjectId, labelKey, params, ?url)`: a host entry (`labelKey` of the host's `messages` domain, `^[a-z0-9_.-]{1,120}$`; ≤ 20 scalar params with keys `^[a-z][a-z0-9_]{0,39}$`, strings ≤ 200; `url` a local path) |
| `ConsentServiceInterface` | `record(contactId, purpose, bool $granted, ConsentSource, ?evidence): string`, `current(contactId): array<purpose, bool>` |
| `ImportServiceInterface` | `upload(SubjectType, ImportFormat, fileName, bytes): string`, `preview(id, rows = 20): ImportView`, `confirm(id, mapping, DuplicatePolicy, int $v)`, `run(id, ?int $maxChunks): bool`, `cancel(id)` |
| `MergeServiceInterface` | `preview(SubjectType, survivorId, otherId): MergePreview`, `merge(SubjectType, survivorId, otherId, list<field> $takeFromOther, int $survivorVersion, int $otherVersion)` |
| `CrmExporterInterface` | `csv(ListQuery): iterable<string>` (`manage`), `vcard(contactId): string` (`view`) |
| `CrmPersonalDataInterface` | `export(contactId): array`, `erase(contactId)` (`manage`, idempotent) |
| `CrmSettingsInterface` | `get(): array{trash_days, timeline_years, import_keep_days, merge_keep_days, version}`, `update(int ×4, int $expectedVersion)` (`manage`) |

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
- **The company's primary contact**: at most one per company, set from the company page ("Make primary contact"), in
  the affiliation form of the contact, through `setPrimaryContact()` / `affiliate(…, primaryContact: true)` or by an
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
`primary_contact=1`); unknown keys in the URL are ignored.

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
| `primary_contact` (0.2.0) | `/companies/{id}/primary-contact/{contact}` (action token) | POST | edit on the company |
| `consent` | `/contacts/{id}/consent` | POST | edit |
| `vcard` | `/contacts/{id}/vcard` | GET | view |
| `gdpr_export`, `erase` | `/contacts/{id}/gdpr-export` (JSON download), `/contacts/{id}/erase` | POST (both with the action token `crm_action[_token]`) | manage |
| `import`, `import_show` | `/import`, `/import/{id}` (preview, confirm; `do=run` continue, `do=cancel`) | GET, POST | edit |
| `tags`, `tag_edit` | `/admin/tags`, `/admin/tags/{id}` (rename, recolour, delete) | GET, POST | manage |
| `fields`, `field_edit` | `/admin/fields`, `/admin/fields/{id}` (change, archive) | GET, POST | manage |
| `settings` | `/admin/settings` | GET, POST | manage |

Templates live under `@NowoCrmKit/` and extend UiKit's page templates; a host overrides one through Symfony's bundle
template path (`templates/bundles/NowoCrmKitBundle/…`) if it must, but the brand comes from UiKit tokens.

## Imports

1. **Upload** (`edit`): a CSV (UTF-8, BOM stripped, `,` or `;`, header row, unique non-empty column names) or a vCard
   3.0 / 4.0 file (contacts only; `N`, `FN`, `EMAIL`, `TEL` (`CELL` ⇒ mobile), `TITLE`, `ORG`, `ADR`, first value of
   each). At most `import.max_rows` rows and `import.max_bytes` bytes. Cells are text; nothing is evaluated.
2. **Preview**: the first 20 rows checked against the detected mapping (field names and common aliases such as
   `e-mail`, `surname`, `zip`, `vat`), errors per row and, for contacts, the existing contact with the same e-mail.
   The mapping is editable: a core field, `custom.<key>`, `tags` (comma-separated names of **existing** tags) or, for
   contacts, `company` (affiliates to an existing active company with exactly that name, case-insensitive), or skip.
   0.2.0: `roles` (role keys separated by `,` or `;`, both types; added to the stored ones), and for contacts
   `purposes` (purpose keys of the affiliation to the named company, added to the stored ones) and `primary_contact`
   (`yes` / `no`, also `1`, `true`, `x` / empty, `0`, `false`: makes the contact the company's primary contact).
   Keys must equal configured keys exactly; an unknown key or an unreadable yes / no makes the row `invalid`.
3. **Confirm** with the duplicate policy: `skip` (a contact whose e-mail exists becomes `duplicate`) or `update` (the
   existing active contact is updated; a trashed one stays `duplicate`). Companies have no duplicate key: every valid
   row creates a company.
4. **Run**: the confirm request runs `import.sync_rows` rows in chunks of `import.chunk_rows`; a larger import shows
   "Continue" (`do=run`, the same amount again). A worker that stopped leaves the import `running`;
   `nowo:crm-kit:import:resume` takes over claims older than `import.stale_claim_seconds`. Rows end as `created`,
   `updated`, `duplicate`, `invalid` (with the first error key) or `limit` (plan limit reached). Each chunk first
   locks the existing contacts and the named companies of all its rows (contacts, then companies, each in id order),
   then settles the rows ([CONCURRENCY.md](CONCURRENCY.md#import)).

No Messenger handler ships in 0.1.0: imports always run in requests and in the resume command.

## SearchKit

With `nowo-tech/search-kit-bundle` installed (it needs PHP >= 8.3), `ui.enabled` and `search.enabled` (default), the
bundle registers two providers, `nowo_crm_kit.search.company` (group `crm_companies`, priority 60) and
`nowo_crm_kit.search.contact` (group `crm_contacts`, priority 59). Per call each resolves the tenant, the feature gate
and `view`, searches active records of that tenant only (companies: name, legal name, website domain, e-mail, tax id;
contacts: name, e-mail, phone, mobile) with escaped `LIKE` wildcards, returns at most SearchKit's limit with the
visible total and a "see all" link to the filtered list, and answers `null` for any refusal (no 403, no hint). A scope
that is the id of an active company of the tenant narrows contacts to that company's; any other scope answers `null`.
The bundle's tests exercise the bridge with SearchKit stubs (`tests/Stubs/SearchKit`).

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
