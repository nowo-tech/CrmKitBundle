/**
 * The house "script loaded" console line (REQ-ASSETS-005), as UiKit's and IconSelector's `logger.ts`.
 *
 * The CRM's script logs exactly one line when it loads, so a developer can tell which build of the script a page runs
 * (stale `assets:install`, a CDN or the browser cache):
 *
 *     📦 [crm-kit/import] script loaded, build: 2026-11-24 10:05 UTC
 *
 * Nothing is printed with `<html data-nowo-console="quiet">` or `globalThis.NOWO_CONSOLE = 'quiet'`. The line holds
 * the script name and the build time only: no user data, URL or configuration value.
 */

/** True when the page asked for a silent console (`<html data-nowo-console="quiet">` or `globalThis.NOWO_CONSOLE = 'quiet'`). */
export function isQuiet(): boolean {
  try {
    if ((globalThis as { NOWO_CONSOLE?: unknown }).NOWO_CONSOLE === 'quiet') return true;
    return typeof document !== 'undefined' && document.documentElement.dataset.nowoConsole === 'quiet';
  } catch {
    return false;
  }
}

/** Options of {@link createBundleLogger}. */
export type BundleLoggerOptions = {
  /** If set, scriptLoaded() includes it (the build time label, e.g. "2026-11-24 10:05 UTC"). */
  buildTime?: string;
};

/** The logger of one script: the CRM prints nothing else to the console. */
export type BundleLogger = {
  /** Call once at startup. Logs "script loaded" and the build time, unless the quiet switch is set. */
  scriptLoaded: () => void;
};

/**
 * Creates the logger of a script.
 *
 * @param name - Kebab-case name for the prefix, `<bundle>` or `<bundle>/<script>` (e.g. `crm-kit/import`).
 * @param options - The build time shown by scriptLoaded().
 */
export function createBundleLogger(name: string, options: BundleLoggerOptions = {}): BundleLogger {
  const prefix = `[${name}]`;
  const { buildTime } = options;

  return {
    scriptLoaded(): void {
      if (isQuiet()) return;
      if (buildTime !== undefined && buildTime !== '') {
        console.log(`%c📦 ${prefix} script loaded, build: %c${buildTime}`, 'color:#0ea5e9;font-weight:bold', 'color:#059669');
      } else {
        console.log(`%c📦 ${prefix} script loaded`, 'color:#0ea5e9;font-weight:bold');
      }
    },
  };
}
