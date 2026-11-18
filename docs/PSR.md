# PSR compliance

Package: `nowo-tech/crm-kit-bundle` (`symfony-bundle`)

| PSR | Status | Notes |
| --- | --- | --- |
| PSR-1 / PSR-12 Coding style | **Adopted** | PHP-CS-Fixer (`@PSR12` + `@Symfony`), `make cs-check` in CI |
| PSR-4 Autoloading | **Adopted** | `Nowo\CrmKitBundle\` maps to `src/` |
| PSR-3 Logger | **Adopted** | The default audit sink and the panel reader take an optional `Psr\Log\LoggerInterface`; ids, counts and class names only, no personal data in the log context |
| PSR-6 Cache | **N/A** | Phase A has no cache |
| PSR-14 Event dispatcher | **Adopted** | Domain events (`Domain\Event\*`) are plain classes dispatched after the commit, ids and value objects only |
| PSR-20 Clock | **Adopted** | Every "now" reads an injected `Psr\Clock\ClockInterface` (`clock` port; FrameworkBundle's clock by default) |
| PSR-7 / PSR-15 / PSR-17 / PSR-18 | **N/A** | Symfony HttpFoundation and HttpClient are used directly |
