# CRM Kit API (0.3.0)

The bundle has one API with two front doors that make the same decisions:

- **`Api\Operation`**: PHP services an in-process caller uses (an MCP host's `crm_*` tools). It needs `api.enabled`.
- **The `/crm/v1` JSON API**: thin controllers over the same operations. It needs `api.enabled` and
  `api.http.enabled`, and both are off by default.

Both use the same idempotency keys, audit lines and error codes. The OpenAPI document is
[`docs/openapi/crm-v1.yaml`](openapi/crm-v1.yaml). The operation catalogue is shipped as
`tests/Contract/crm-api-v1.json`, and a host can mirror it in its own tests (see "Contract and conformance" below).

## Table of contents

- [Principal, grant and reach](#principal-grant-and-reach)
- [Order of checks](#order-of-checks)
- [Operations](#operations)
- [Writes: idempotency, versions, audit](#writes-idempotency-versions-audit)
- [Lists: keyset cursors](#lists-keyset-cursors)
- [Untrusted text](#untrusted-text)
- [Errors](#errors)
- [Never exposed](#never-exposed)
- [The JSON API](#the-json-api)
- [For an MCP host](#for-an-mcp-host)
- [Contract and conformance](#contract-and-conformance)

## Principal, grant and reach

Every call takes a `CrmApiPrincipal`. The host builds it on each request, and the bundle never keeps it. It holds:

- **`user`**: the host's security user. It is handed to the area checker and to the record events.
- **`actor`**: the CRM actor, used for owner ids, `created_by` and the audit.
- **`grant`**: a `CrmApiLevel` (`view`, `edit` or `manage`) and three ticks: `amounts`, `consents` and `destructive`.
- **`reach`**: `CrmApiReach::everything($except)` or `limitedTo($tenants)`. An empty or unreadable scope must be
  `limitedTo([])`.
- **`tokenId`, `tokenName`, `channel`** (`api`, `mcp`, …) and an opaque **`hostContext`**.

Every operation also takes the tenant explicitly. The bundle never looks a record's tenant up by its id.

What each capability asks of the grant and of the person:

| Capability | Grant | Person (`CrmPermission`) |
|---|---|---|
| `read` | view or more | `view` |
| `read_amounts` | view or more, and `amounts` | `amounts_view` |
| `read_consents` | view or more, and `consents` | `view` on the contact |
| `create` | edit or more | `create` |
| `edit` | edit or more | `edit` on the record |
| `write_amounts` | edit or more, and `amounts` | `edit` and `amounts_view` |
| `trash` | edit or more, and `destructive` | `delete` on the record |
| `manage_reach` | manage | `manage` |

**`manage` (QA-1)** has one effect in 0.3.0. With `security.resource_scoping: true`, a person who may manage the
settings sees every record only through a manage-level token. Below that level they see what a non-manager sees.

## Order of checks

The first check that fails answers:

1. **Out of reach: 404 `ACCESS_001`.** Each of these gets the same answer:
   - the operation is unavailable on this install (for example, deals while `pipeline.enabled` is false);
   - the reach does not include the tenant;
   - the tenant is unknown, or the CRM is off for it;
   - the record is missing, belongs to another tenant, or is trashed or erased;
   - the principal may not view the record (resource scoping, or a `nowo_crm_kit.record_access_check` deny of
     `view`).
2. **Grant: 403 `TOKEN_001`.** The level or a tick is missing.
3. **Person: 403 `ACCESS_002`.** The area checker or the record decision refuses the action.

A merged record answers 409 `CRM_002`. It carries `merged_into_id` only when the principal may view the survivor.
Reads answer a tombstone instead. The services check again inside the write unit, under the record's lock.

`nowo_crm_kit.record_access_check` and `nowo_crm_kit.record_visibility` listeners see `getChannel()` (`web`, `api`,
`mcp`) and `getTokenId()`. A host can therefore deny an agent something it allows on the pages.

## Operations

| Operation | MCP tool | Capability | Kind | Base version |
|---|---|---|---|---|
| `search` | `crm_search` | read | read | — |
| `company.get` / `contact.get` | `crm_company_get` / `crm_contact_get` | read (+ amounts) | read | — |
| `timeline` | `crm_timeline` | read | read | — |
| `notes.list` | `crm_notes_list` | read | read | — |
| `consents.get` | `crm_consents_get` | read_consents | read | — |
| `deal.search` / `deal.get` | `crm_deal_search` / `crm_deal_get` | read (+ amounts) | read | — |
| `funnel.stats` | `crm_funnel_stats` | read (+ amounts for values) | read | — |
| `tags.list` / `funnels.list` | `crm_tag_list` / `crm_funnel_list` (QA-5) | read | read | — |
| `company.create` / `contact.create` | `crm_company_create` / `crm_contact_create` | create | additive | — |
| `company.update` / `contact.update` | `crm_company_update` / `crm_contact_update` | edit | additive | required |
| `affiliation.set` / `affiliation.remove` | `crm_affiliation_set` / `crm_affiliation_remove` | edit | additive | — |
| `note.add` | `crm_note_add` | edit | additive | — |
| `tag.add` / `tag.remove` | `crm_tag_add` / `crm_tag_remove` | edit | additive | — |
| `next_action.set` | `crm_next_action_set` | edit | additive | — |
| `deal.create` / `deal.update` | `crm_deal_create` / `crm_deal_update` | create / edit (+ write_amounts) | additive | — / required |
| `deal.move_stage` | `crm_deal_move_stage` | edit | additive | optional |
| `record.trash` | `crm_record_trash` | trash | destructive | optional |
| `deal.trash` | `crm_deal_trash` (QA-4) | trash | destructive | optional |
| `links.list`, `affiliations.list`, `identity` | — (JSON API only) | read | read | — |

`CrmApiOperations::available($operation)` tells whether an operation can work on this install. The deal operations
need `pipeline.enabled`.

**Creates check for duplicates first (design §6.5).** The check runs inside the write unit, after the plan counter row
is locked, and its read is a locking one. The first rule that matches decides:

- **Contacts:** the same e-mail.
- **Companies:** the same tax id; otherwise the same website domain; otherwise the same name, but only when the draft
  has neither a tax id nor a website (QA-6).

What a match answers:

| The match is | Answer |
|---|---|
| visible | 200 with the existing record, `duplicate: true` and `duplicate_of` (`email`, `tax_id`, `domain` or `name`); nothing is created |
| trashed and visible | 409 `CRM_003` with `record_id` |
| not visible | 409 `CRM_006`, with no id |

A new record's answer lists up to 5 visible name matches as `possible_duplicates` (ids only).

**Deals** (QA-4):

- Trashing a deal can be undone from the pages, and the deal is purged after `trash_days`.
- Moving a deal to a lost stage needs a lost reason (422 `CRM_007`).
- A stage outside the deal's funnel is 409 `CRM_008`, and so is a create into a closed stage.

**Next actions** go through `CrmTaskPort` before the CRM's write unit:

- The port gets a dedupe key derived from the idempotency key, so a retry adopts the task it created.
- It also gets the principal's `hostContext`, so the host applies the same token.
- A transient port refusal is 503 `CRM_009`, and nothing is written.
- A refusal with a code shaped like `ABC_123` keeps that code; any other refusal is 502 `CRM_010`.

## Writes: idempotency, versions, audit

- **Every write needs an idempotency key** (`IdempotencyKey`: 1–255 bytes, `0x21`–`0x7E`).
  - Keys are scoped per principal (channel and token) and per tenant.
  - The same key with the same input replays (`replayed: true`, and the first answer's status, so 201 stays 201).
  - The same key with another input is 409 `IDEMP_002`.
- **How the claim works.** The claim is the first statement of the write unit, and the outcome is its last:
  - a refused or failed write keeps nothing;
  - a concurrent call with the same key waits for the first one, then replays it, or runs if the first one rolled
    back.
- **What the store keeps.** Only ids and the outcome, never a body. A replay reads the result again through the read
  path, so it answers 404 once the target is out of reach.
- **Expiry.** Keys expire after `api.idempotency_ttl_seconds` (24 h by default). `nowo:crm-kit:api:purge-idempotency`
  and the daily `nowo:crm-kit:purge` delete them. A host can call
  `CrmApiIdempotencyStoreInterface::forget($channel, $tokenId)` when it revokes a token.
- **Base versions.** `company.update`, `contact.update` and `deal.update` need a base version (`If-Match`; MCP:
  `base_version`). Without it the answer is 428 `VERSION_001`. A stale version is 409 `CRM_001` with
  `current_version`.
- **Audit.** Every write is audited with an `AuditEntry` whose `origin` holds the channel, the token id and the token
  name. The token name is cut to 120 characters and loses its control and format characters. Timeline entries
  written through the API carry `via` and `token`, and the 360° page shows "via agent ‹token›". A replay audits
  nothing.

## Lists: keyset cursors

These lists have no offset and no total: `search`, `deal.search`, `timeline`, `notes.list` and `affiliations.list`.

- **`next_cursor`** is a sealed cursor: a `sodium_crypto_secretbox` under a key derived from `kernel.secret`. It is
  null on the last page.
- **When a cursor is refused.** Reusing it with other filters or another operation answers 400 `INPUT_003` ("Start
  the list again"). So does a tampered cursor, or one sealed before the secret was rotated.
- **Access** is decided again on every page.
- **Page size:** `api.page_size` (25) by default, at most 100.

## Untrusted text

Every read model keeps **facts** (ids, enums, keys, numbers, instants, flags) apart from **text**, which is everything
a person, an import or an agent wrote. Text sits in `text`, and the OpenAPI marks it `x-untrusted: true`. Input
strings are validated by the same rules as the forms. They are never echoed in a message, a code, an audit line or a
log.

## Errors

The body is `{"error": "<static message>", "code": "<CODE>", ...extra}`. Every refusal also sends `X-Error-Code`.

- **Lock conflicts** (REQ-RUNTIME-003 rule 6):
  - `CONFLICT_001`, when nothing committed, sends `X-Conflict-Code: CONFLICT_001` and `Retry-After`.
  - `CONFLICT_002`, when part of the request committed (a next action whose task was already created), sends no
    `Retry-After`.
- **`X-Conflict-Code` carries only these two codes.** Domain conflicts, unique violations and lost connections are not
  lock conflicts.

| HTTP | Code | When | Extra |
|---|---|---|---|
| 400 | `INPUT_001` | a required field is missing | `field` |
| 400 | `INPUT_002` | a malformed or unknown field, a bad `If-Match`, bad JSON | `field` |
| 400 | `INPUT_003` | the cursor is expired, tampered with or for another query | — |
| 422 | `INPUT_004` | the CRM's validation refused the input | `fields` |
| 400 | `IDEMP_003` | invalid `Idempotency-Key` | — |
| 428 | `IDEMP_005` | a write without `Idempotency-Key` | — |
| 409 | `IDEMP_002` | the key was reused with another input | — |
| 503 | `IDEMP_004` | the idempotency store failed | — |
| 428 | `VERSION_001` | an update without `If-Match` / `base_version` | — |
| 404 | `ACCESS_001` | out of reach | — |
| 403 | `TOKEN_001` | the grant does not cover it | — |
| 403 | `ACCESS_002` | the person may not | — |
| 409 | `CRM_001` | stale version | `current_version` |
| 409 | `CRM_002` | the record was merged | `merged_into_id` when visible |
| 409 | `CRM_003` | a matching record is in the trash | `record_id` when visible |
| 409 | `CRM_004` | plan limit | — |
| 409 | `CRM_005` | a unique key refused the write twice (a retry is safe) | — |
| 409 | `CRM_006` | a matching record exists and is not visible | — |
| 422 | `CRM_007` | a lost stage without a lost reason | — |
| 409 | `CRM_008` | the stage cannot be used here | — |
| 503 | `CRM_009` | the task port is unavailable; nothing written | — |
| 502 | `CRM_010` | the task port refused for an unclassified reason | — |
| 409 | `CONFLICT_001` | lock conflict, nothing committed | — |
| 409 | `CONFLICT_002` | lock conflict after a part committed | — |
| 500 | `SERVER_001` | anything else (logged by class name only) | — |

## Never exposed

There is no operation, route or tool for any of these:

- consent changes;
- merge, GDPR erasure and export, purge, and restore from the trash;
- imports and exports;
- settings: tags, custom fields, catalogues, funnels and stages, lost reasons;
- campaigns, quotes and invoices;
- adding or removing host links;
- editing, deleting or pinning a note (QA-8);
- `CrmBatchLookupInterface` and `CrmActorPersonalDataInterface`, which are system APIs without permission checks.

An architecture test fails if `Api\*` imports any of the services behind them.

## The JSON API

```yaml
nowo_crm_kit:
    api:
        enabled: true
        http:
            enabled: true
            prefix: /api                 # routes under /api/crm/v1
            authenticator: App\Crm\TokenAuthenticator   # a CrmApiAuthenticatorInterface; null = the firewall user + default_grant
            tenant_resolver: null        # a CrmApiTenantResolverInterface; null = the configured tenant resolver
```

```yaml
# config/routes/nowo_crm_kit_api.yaml
nowo_crm_kit_api:
    resource: .
    type: nowo_crm_kit_api
```

- **Firewall.** Protect `^%nowo_crm_kit.api_path%/` with a firewall of your own.
- **Default authenticator.** `SecurityUserCrmApiAuthenticator` turns the firewall's user into a principal:
  - the reach is the whole reach;
  - the grant is `api.http.default_grant`;
  - the token id is `session:` followed by the SHA-256 of the user identifier;
  - the token name is `API`.
- **Headers you send.** Writes need an `Idempotency-Key`. Updates need `If-Match`.
- **Headers you get back.** Single-record answers carry an `ETag`. Replays carry `Idempotency-Replayed: true`.
- **Unknown body fields** answer `INPUT_002`.
- **CORS.** The bundle does not handle it. Your CORS layer must expose `X-Error-Code`, `X-Conflict-Code`, `ETag` and
  `Idempotency-Replayed`.

```bash
curl -s -u ana:secret https://crm.example.test/api/crm/v1/me
curl -s -u ana:secret -H 'Idempotency-Key: 6f1c-01' -H 'Content-Type: application/json' \
     -d '{"name":"Acme Tools","website":"https://acme.test"}' https://crm.example.test/api/crm/v1/companies
curl -s -u ana:secret -X PATCH -H 'Idempotency-Key: 6f1c-02' -H 'If-Match: "1"' -H 'Content-Type: application/json' \
     -d '{"city":"Madrid"}' https://crm.example.test/api/crm/v1/companies/<id>
```

## For an MCP host

1. **Build the principal.** Build a `CrmApiPrincipal` per call:
   - channel `mcp`;
   - the token's level and ticks;
   - the token's tenants as the reach;
   - the MCP context as `hostContext`.
2. **Call the operations.** Use the `Api\Operation\CrmApi*Operations` services (public in the container). The refusal
   codes already have the `ABC_123` shape.
3. **Arguments.** `idempotency_key` is required on every write tool, and `base_version` on the update tools.
4. **Untrusted text.** Put each read model's `text` under your untrusted envelope.
5. **Hide tools** that `CrmApiOperations::available()` says cannot work on this install.
6. **Next actions on your task system.** Implement `CrmTaskPort` (or the TaskBoard bridge's user resolver). The task
   must be created with the same token.

## Contract and conformance

- **`tests/Contract/crm-api-v1.json`** lists, for every operation: the tool name, capability, kind, idempotency, base
  version, the grant ticks it uses, whether it is paged, and the refusal codes it can answer. `ApiContractTest` fails
  when the catalogue and the file differ, so a change is always visible in the diff.
- **`tests/Support/CrmApiConformance`** runs one operation through the grant profiles (`view`, `edit+amounts`,
  `manage+all`, …) and compares each decision with the expected one. This lets a host run the bundle's decision matrix
  with its own area checker and token principal.

Both files ship with the package; they are not archive-excluded. They use the namespace
`Nowo\CrmKitBundle\Tests\Support`, which the package's `autoload-dev` does not export. Map that namespace in your own
`autoload-dev`, or require the file.
