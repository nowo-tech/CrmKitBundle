/**
 * The "script loaded" console line (REQ-ASSETS-005): one line per script name and page, the build id, both quiet
 * switches.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createBundleLogger, isQuiet } from '../src/logger';
import { announceScript } from '../src/script-banner';

describe('announceScript', () => {
  let log: ReturnType<typeof vi.spyOn>;

  beforeEach(() => {
    delete window.__nowoScriptBanners;
    delete document.documentElement.dataset.nowoConsole;
    delete (globalThis as { NOWO_CONSOLE?: unknown }).NOWO_CONSOLE;
    log = vi.spyOn(console, 'log').mockImplementation(() => undefined);
  });

  afterEach(() => {
    vi.restoreAllMocks();
    delete window.__nowoScriptBanners;
    delete document.documentElement.dataset.nowoConsole;
    delete (globalThis as { NOWO_CONSOLE?: unknown }).NOWO_CONSOLE;
  });

  it('logs the script name and the build id', () => {
    announceScript('crm-kit/import', '2026-11-24 10:05 UTC');

    expect(log).toHaveBeenCalledTimes(1);
    expect(String(log.mock.calls[0]?.[0])).toContain('📦 [crm-kit/import] script loaded, build: ');
    expect(String(log.mock.calls[0]?.[2])).toBe('color:#059669');
    expect(log.mock.calls[0]?.join(' ')).toContain('2026-11-24 10:05 UTC');
  });

  it('logs without a build id when none is injected or it is empty', () => {
    announceScript('crm-kit/import');
    createBundleLogger('crm-kit/other', { buildTime: '' }).scriptLoaded();

    expect(String(log.mock.calls[0]?.[0])).toContain('[crm-kit/import] script loaded');
    expect(String(log.mock.calls[0]?.[0])).not.toContain('build: ');
    expect(String(log.mock.calls[1]?.[0])).not.toContain('build: ');
  });

  it('logs a script loaded twice only once, and each script name on its own', () => {
    announceScript('crm-kit/import');
    announceScript('crm-kit/import');
    announceScript('crm-kit/other');

    expect(log).toHaveBeenCalledTimes(2);
    expect(String(log.mock.calls[1]?.[0])).toContain('[crm-kit/other] script loaded');
  });

  it('never throws when page HTML clobbers the registry global, and still announces once', () => {
    document.body.innerHTML =
      '<img id="__nowoScriptBanners" name="crm-kit/clobbered"><img id="__nowoScriptBanners" name="crm-kit/clobbered">';
    Object.defineProperty(window, '__nowoScriptBanners', {
      get: () => document.getElementsByName('crm-kit/clobbered'),
      set: () => {
        throw new TypeError('read-only');
      },
      configurable: true,
    });
    try {
      expect(() => announceScript('crm-kit/clobbered')).not.toThrow();
      expect(() => announceScript('crm-kit/clobbered')).not.toThrow();
      expect(log).toHaveBeenCalledTimes(1);
    } finally {
      delete (window as { __nowoScriptBanners?: unknown }).__nowoScriptBanners;
      document.body.innerHTML = '';
    }
  });

  it('never throws when reading the global throws (a cross-origin frame), or a write is ignored', () => {
    Object.defineProperty(window, '__nowoScriptBanners', {
      get: () => {
        throw new DOMException('Blocked a frame', 'SecurityError');
      },
      configurable: true,
    });
    try {
      expect(() => announceScript('crm-kit/cross-origin')).not.toThrow();
      expect(() => announceScript('crm-kit/cross-origin')).not.toThrow();
    } finally {
      delete (window as { __nowoScriptBanners?: unknown }).__nowoScriptBanners;
    }
    // A setter that silently ignores the write: the global stays a non-object, the module set decides.
    Object.defineProperty(window, '__nowoScriptBanners', {
      get: () => 'clobbered',
      set: () => undefined,
      configurable: true,
    });
    try {
      announceScript('crm-kit/ignored');
      announceScript('crm-kit/ignored');
    } finally {
      delete (window as { __nowoScriptBanners?: unknown }).__nowoScriptBanners;
    }
    expect(log).toHaveBeenCalledTimes(2);
  });

  it('prints nothing with <html data-nowo-console="quiet">', () => {
    document.documentElement.dataset.nowoConsole = 'quiet';
    announceScript('crm-kit/import', 'x');

    expect(isQuiet()).toBe(true);
    expect(log).not.toHaveBeenCalled();
  });

  it('prints nothing with globalThis.NOWO_CONSOLE = "quiet"', () => {
    (globalThis as { NOWO_CONSOLE?: unknown }).NOWO_CONSOLE = 'quiet';
    announceScript('crm-kit/import', 'x');

    expect(log).not.toHaveBeenCalled();
  });

  it('is not quiet without a document, or when the page cannot be read', () => {
    vi.stubGlobal('document', undefined);
    try {
      expect(isQuiet()).toBe(false);
    } finally {
      vi.unstubAllGlobals();
    }
    vi.spyOn(document, 'documentElement', 'get').mockImplementation(() => {
      throw new Error('blocked');
    });
    expect(isQuiet()).toBe(false);
  });

  it('does nothing without a window', () => {
    vi.stubGlobal('window', undefined);
    try {
      announceScript('crm-kit/import');
    } finally {
      vi.unstubAllGlobals();
    }

    expect(log).not.toHaveBeenCalled();
  });
});
