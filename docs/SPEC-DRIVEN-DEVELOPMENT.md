# Spec-driven development

This document is the single entry point for **what CrmKitBundle guarantees**, **how it is proven** and **which
`REQ-*` anchors** the Makefiles and demos carry. There is no separate executable spec language: **PHPUnit** and
**PHPStan** are the normative proof alongside the prose.

## Table of contents

- [Layers kept in sync](#layers-kept-in-sync)
- [User stories](#user-stories)
- [Bundle functional scope](#bundle-functional-scope)
- [Validating the functional spec](#validating-the-functional-spec)
- [Requirement identifiers](#requirement-identifiers)
- [Contributor workflow](#contributor-workflow)
- [Relationship to Engram](#relationship-to-engram)
- [GitHub Spec Kit](#github-spec-kit)
- [See also](#see-also)

## Layers kept in sync

| Layer | Role | Where |
| --- | --- | --- |
| Design contract | Contract v0.1 for symfony-beacon spec 245 (owner decision D-20261118-05; every Q-O and Q-B as recommended until answered), adjusted to the phase A code: ports, persistence, security model, configuration, concurrency, worker design, tests, phases | [CONTRACT.md](CONTRACT.md), [ROADMAP.md](ROADMAP.md) |
| GitHub Spec Kit baseline | Feature spec (user scenarios, `FR-*`, `SC-*`, entities, non-goals) and the 100 % code inventory of `src/` | [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md), [`specs/001-baseline/code-inventory.md`](../specs/001-baseline/code-inventory.md), manual in [SPEC-KIT.md](SPEC-KIT.md) |
| Product behaviour | What integrating applications may rely on | [USAGE.md](USAGE.md), [CONFIGURATION.md](CONFIGURATION.md), [INSTALLATION.md](INSTALLATION.md), [SECURITY.md](SECURITY.md) |
| Traceability anchors | Stable `REQ-*` ids in the Makefiles, CI and demo files, so PRs and issues can cite tooling behaviour | [Requirement identifiers](#requirement-identifiers) |
| Change management | Consumer-facing deltas | [CHANGELOG.md](CHANGELOG.md), [UPGRADING.md](UPGRADING.md), [RELEASE.md](RELEASE.md) |

Every phase updates the baseline spec, the code inventory and the integrator docs in the same pull request.

## User stories

| ID | Story | Scope | Integrator docs |
| --- | --- | --- | --- |
| US-01 | **As an** integrator, **I want** to install the package with a strict configuration and its own entity manager **so that** it never flushes my unit of work. | Config tree, Flex recipe, `doctrine.entity_manager` | [INSTALLATION.md](INSTALLATION.md), [CONFIGURATION.md](CONFIGURATION.md) |
| US-02 | **As an** integrator, **I want** to plug my tenants, people, permissions, plan and audit into the CRM through ports **so that** I never override a bundle service. | Ports with deny-by-default defaults | [USAGE.md](USAGE.md#ports-to-implement) |
| US-03 | **As a** CRM user, **I want** to keep companies and contacts with owners, tags, custom fields, notes and a timeline **so that** the team shares one picture of each customer. | Records, affiliations, tags, fields, notes, timeline | [USAGE.md](USAGE.md) |
| US-04 | **As a** CRM user, **I want** a 360° page that shows my application's projects, tickets and hours for a record **so that** I do not switch modules. | Host links, resolvers, panels | [USAGE.md](USAGE.md#panel-providers) |
| US-05 | **As a** CRM user, **I want** to import CSV / vCard files and merge duplicates **so that** existing address books move in without double entries. | Import, duplicate hints, merge | [USAGE.md](USAGE.md#imports) |
| US-06 | **As a** data controller, **I want** consent per purpose, a contact export, an erasure and a retention purge **so that** I meet GDPR requests. | Consent, export, erasure, purge | [SECURITY.md](SECURITY.md#gdpr) |
| US-07 | **As an** operator, **I want** concurrent writes and worker mode to be safe **so that** no data is lost, doubled or leaked between tenants. | Write units, 409 codes, worker hygiene | [CONCURRENCY.md](CONCURRENCY.md), [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md) |

**Goal:** a reusable, configurable CRM for Symfony applications, built phase by phase (A companies and contacts, B
pipeline, C quotes, D invoices, E campaigns, F customer portal, G reports), tenant-aware and deny by default, on a
private entity manager with a UiKit + FormKit UI and bridges to the Nowo bundles. The full design is
[CONTRACT.md](CONTRACT.md).

**Phase A scope (0.1.0):** companies, contacts, affiliations, owners, tags, custom fields, notes, timeline, host links
and panels, CSV / vCard import and export, duplicate hints and merging, GDPR, trash, plan-limit counters, SearchKit
providers, the UI. Baseline: [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md).

**Explicit non-goals (all phases):** telephony (CTI), social media inboxes, AI scoring, multi-step marketing journeys,
an accounting ledger, payment card processing, native apps. Phase A ships no public JSON API and no attachments. Not
legal advice.

## Validating the functional spec

```bash
make test-coverage-100   # PHPUnit (Unit, Integration, Database on SQLite) + 100 % statements / elements of src/
make test-with-db        # Database suite on MySQL 8.4 (REQ-TEST-005); CI adds MariaDB 11.4 and PostgreSQL 16
make phpstan igor        # PHPStan level max + FrankenPHP worker rulesets, Igor worker-state audit
make qa                  # cs-check + test
make release-check       # the whole pre-release chain, demo included
```

Coverage floor: **100 %** of `src/` (statements and elements; CI gate). Any behaviour change ships with tests under
`tests/` (unit for value objects and services, functional for both host kinds, worker loop for state).

## Requirement identifiers

| `REQ-*` | Where | Meaning |
| --- | --- | --- |
| REQ-MAKE-001 / 002 | `Makefile` (`help`, `ensure-up`, `release-check`) | Standard targets, sequential release chain |
| REQ-MAKE-003 | `demo/symfony8/Makefile` | Demo targets incl. `test-coverage`, `verify` |
| REQ-MAKE-007 | `Makefile` `down-dev` | Stop without removing volumes |
| REQ-MAKE-008 | `Makefile`, `demo/Makefile`, `demo/symfony8/Makefile` (soft includes) | `update-deps`, `update-deps-all` |
| REQ-MAKE-004 / REQ-I18N-002 | `Makefile` `validate-translations` | YAML syntax + key parity of the 7 locales |
| REQ-TEST-003 / 006 | `Makefile` `test-coverage-100`, `coverage-check` | 100 % gate |
| REQ-TEST-005 | `Makefile` `test-with-db`, `test-coverage-with-db`; `docker-compose.yml` services `database`, `mariadb`, `postgres` (profile `db`) | Database suite on MySQL, MariaDB and PostgreSQL |
| REQ-TEST-011 | `Makefile` / `demo/Makefile` `demo-smoke`, `.github/workflows/demo-smoke.yml` | Demo boots, HTTP 200 |
| REQ-CS-001 | `Makefile` `validate-phpdoc` | PHPDoc on every class and public method |
| REQ-CS-008 | `Makefile` `igor` | Igor worker-state audit |
| REQ-TWIG-004 / 005 | `Makefile` `check-twig-extra`, `check-no-raw-html-form` | Twig extras, no raw form tags |
| REQ-REL-003 | `Makefile` `check-open-prs` | No open PRs left at release |
| REQ-GIT-001 | `Makefile` `setup-hooks`, CI job "Git history" | No agent co-author trailers |
| REQ-DEMO-001 / 002 / 010 / 012 | `demo/symfony*/composer.json`, `config/bundles.php`, `docker/frankenphp/Caddyfile*`, `docker/entrypoint.sh` | Dev stack, FrankenPHP + Mercure + hot reload, `FRANKENPHP_MODE`, Symfony 7.4 / 8.1 |
| REQ-DEMO-014 / 015 | `demo/symfony*/templates/base.html.twig`, `config/packages/nowo_ui_kit.yaml`, `nowo_form_kit.yaml` | UiKit admin layout + default theme (no CDN), FormKit `demo` profile |

When scripted behaviour changes, update the matching anchor comment and this table in the same pull request.

## Contributor workflow

1. **Clarify** the behaviour against [CONTRACT.md](CONTRACT.md) and the baseline spec (add or adjust `US-*`, `FR-*`,
   `SC-*` first).
2. **Implement with tests**: a failing test first where practical; keep the 100 % gate, PHPStan, Igor and the worker
   test green.
3. **Anchor scripts**: tooling changes keep or add their `REQ-*` comment.
4. **Ship integrator docs**: USAGE / CONFIGURATION / SECURITY / CHANGELOG / UPGRADING whenever behaviour or
   configuration changes; refresh `specs/001-baseline/code-inventory.md` when `src/` files change.

## Relationship to Engram

[ENGRAM.md](ENGRAM.md) covers the organisation-wide plane (Engram MCP memory, `.cursor/mcp.json`, checklist hygiene).
This document covers the product plane (behaviour and traceability). Neither replaces the other; Engram points here
for local `REQ-*` traceability.

## GitHub Spec Kit

The repository is initialised for GitHub Spec Kit (`.specify/`, Cursor Agent skills in `.cursor/skills/speckit-*`).
The full manual (install, initialise, navigate `.specify/` vs `specs/`, skills) is [SPEC-KIT.md](SPEC-KIT.md). The
baseline lives in [`specs/001-baseline/`](../specs/001-baseline/): `spec.md` (scenarios, requirements, success
criteria) and `code-inventory.md` (every file of `src/` mapped to `FR-*`). Keep the inventory aligned with `src/` in
every pull request that adds, moves or removes a source file.

## See also

- [GitHub Spec Kit](SPEC-KIT.md)
- [Usage](USAGE.md) · [Configuration](CONFIGURATION.md) · [Installation](INSTALLATION.md)
- [Contributing](CONTRIBUTING.md) · [Release](RELEASE.md) · [Roadmap](ROADMAP.md)
- [Demo with FrankenPHP](DEMO-FRANKENPHP.md) · [FrankenPHP worker audit](FRANKENPHP-WORKER-AUDIT.md)
