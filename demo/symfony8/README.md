# CRM Kit Bundle demo (Symfony 8.1)

FrankenPHP worker mode (kernel kept alive, not reset), MySQL 8.4, UiKit + FormKit, SearchKit. Start it with
`make up` and open http://localhost:8262.

Sign in as `ana` (view), `mia` (edit) or `admin` (manage); the password is `demo` for all three. The CRM lives
under `/crm` (companies, contacts, import, tags, custom fields, settings). The demo's own "projects" module
(`src/Crm/DemoProjects.php`) shows the two host extension points of the 360° page: a host object type the CRM links
to by id (`P-1`, `P-2`, `P-3`) and a panel listing the projects linked to a company. `src/Crm/DemoActors.php` is the
actor directory (record owners). The entrypoint creates the tables with `doctrine:schema:update --em=nowo_crm_kit`.

`make test-e2e` runs the Playwright suite against the running demo; `make demo-screenshots` refreshes the README
screenshots.
