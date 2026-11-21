/** IIFE build entry (`nowo-crm-import.js`): exposes `window.NowoCrmImport` and starts the import modal on the page. */
import { start } from './import-dialog';

export * from './import-dialog';

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => start(), { once: true });
} else {
  start();
}
