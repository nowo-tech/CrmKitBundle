# Browser assets

TypeScript source of the CRM import modal (0.4.0). The built IIFE lives in `src/Resources/public/js/nowo-crm-import.js`
and is published by `assets:install` under the `nowo_crm_kit` asset package; the lists load it themselves
(`asset('js/nowo-crm-import.js', 'nowo_crm_kit')`), so a host only runs `assets:install`.

This is **not** a standalone npm package. From the repository root (or `make assets` / `make test-ts` in Docker):

```bash
pnpm install
pnpm test
pnpm build
```
