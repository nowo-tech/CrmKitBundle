/**
 * IIFE build entry (`nowo-crm-import.js`): exposes `window.NowoCrmImport`, logs the "script loaded" line once per page
 * (REQ-ASSETS-005) and starts the import modal on the page.
 */
import { start } from './import-dialog';
import { announceScript } from './script-banner';

/** The build time label, injected by Vite `define` from the committed `build-info.json`. */
declare const __CRM_KIT_BUILD_TIME__: string;

export * from './import-dialog';

announceScript('crm-kit/import', typeof __CRM_KIT_BUILD_TIME__ !== 'undefined' ? __CRM_KIT_BUILD_TIME__ : undefined);

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => start(), { once: true });
} else {
  start();
}
