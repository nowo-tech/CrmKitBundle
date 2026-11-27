# Implementation plan: HubSpot activities import (0.12.0)

**Spec**: [spec.md](spec.md)

## Architecture

- **Domain**: enum `HubSpotActivity` (`notes`, `calls`, `meetings`, `tasks`: reference type, target type, scope,
  fixed properties); `HubSpotImportScope` gains `$activities` (`activity:<kind>` columns; activities without objects
  allowed); read models `HubSpotEngagement`, `HubSpotEngagementPage`; `NextAction::revise()`.
- **Application** (`Application\HubSpot`): `HubSpotText` (pure text and time rules); `HubSpotEngagementWriter`
  (settles a page inside the runner's unit: references, existing items, association targets through
  `HubSpotRecordWriter::targets()`, record locks contacts then companies in id order, create / update per kind);
  `HubSpotRunState` stages after the objects; `HubSpotRunner::activities()` (skips a kind switched off with one row);
  `HubSpotImportService::start()` refuses kinds the configuration does not import and counts them.
- **Infrastructure**: `HttpHubSpotReader::engagements()` (`GET /crm/v3/objects/{kind}` with associations; ids
  validated, deduplicated, capped); `count()` takes a kind; `HubSpotStartType` / `HubSpotImportController` offer the
  kinds; `ServiceDefinitions::hubspotActivities()` decides them from `activities.enabled` and `pipeline.enabled`.
- **Concurrency**: no new write path outside the runner's unit (cursor compare-and-set under the import row's lock);
  unique keys (`crm_external_ref`, next action dedupe) make a racing insert replay the page once, where it then updates.
- **Worker mode**: every new service is `readonly` and stateless.
