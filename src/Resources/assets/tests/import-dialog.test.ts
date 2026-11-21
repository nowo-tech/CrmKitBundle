import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
  FRAGMENT_HEADER,
  LOCATION_HEADER,
  REQUEST_HEADER,
  notice,
  openImportDialog,
  parseStep,
  sameOriginUrl,
  start,
} from '../src/import-dialog';

/** The shell the list renders (import/_dialog_shell.html.twig, UiKit confirm_dialog anatomy). */
function shell(): string {
  return `
    <a href="/crm/import?subject=contact" data-crm-import-open data-testid="opener">Import</a>
    <div class="nowo-ui-menu" data-nowo-ui-page-actions><button type="button" data-testid="menu-toggle">More</button><a href="/crm/import" data-crm-import-open data-testid="hidden-opener" hidden>Import</a></div>
    <dialog class="nowo-ui-confirm-dialog nowo-ui-confirm-dialog--anatomy" data-crm-import-dialog="/crm/import"
      data-loading="Loading" data-failed="Failed" data-expired="Expired" data-reload="Reload" data-denied="Denied"
      data-conflict-retry="Retry" data-conflict-reload="Reload first" data-notice-class="nowo-ui-flash nowo-ui-flash--error" data-reload-class="nowo-ui-btn">
      <header class="nowo-ui-dialog__header"><h2 class="nowo-ui-dialog__title" id="t">Import</h2><button type="button" class="nowo-ui-dialog__close" data-confirm-dialog-close>×</button><div class="nowo-ui-dialog__steps"><ol data-testid="steps-initial"></ol></div></header>
      <div class="nowo-ui-dialog__body"><p data-part="status">Loading</p></div>
      <footer class="nowo-ui-dialog__footer"><button type="button" data-crm-import-close>Cancel</button></footer>
    </dialog>`;
}

/** A step fragment (import/dialog.html.twig). */
function step(name: string, extra = '', finished = false): string {
  return `<div data-crm-import-step="${name}"${finished ? ' data-crm-import-finished' : ''}>
    <div data-part="steps"><ol data-testid="steps-${name}"></ol></div>
    <div data-part="body">${extra}<form id="f" action="/crm/import" method="post"><input name="crm_import[subject]" value="contact"><input type="hidden" name="crm_import[_token]" value="tok"></form></div>
    <div data-part="footer"><button type="button" data-crm-import-close>Cancel</button><button type="submit" form="f" name="do" value="${name}" data-testid="submit">Next</button></div>
  </div>`;
}

/** A Response with headers. */
function answer(body: string | null, status: number, headers: Record<string, string> = {}, redirected = false): Response {
  const response = new Response(body, { status, headers });
  if (redirected) {
    Object.defineProperty(response, 'redirected', { value: true });
  }
  return response;
}

const fragment = (html: string, status = 200): Response => answer(html, status, { [FRAGMENT_HEADER]: 'import-step' });

function dialogElement(): HTMLDialogElement {
  const dialog = document.querySelector<HTMLDialogElement>('dialog');
  if (dialog === null) {
    throw new Error('no dialog');
  }
  return dialog;
}

const flush = async (): Promise<void> => {
  for (let i = 0; i < 10; i++) {
    await Promise.resolve();
  }
};

beforeEach(() => {
  document.body.innerHTML = shell();
  // jsdom: no layout and no modal dialog; a box for visible elements, a minimal showModal / close.
  vi.spyOn(Element.prototype, 'getClientRects').mockImplementation(function (this: Element) {
    return (this as HTMLElement).hidden ? ([] as unknown as DOMRectList) : ([{}] as unknown as DOMRectList);
  });
  HTMLDialogElement.prototype.showModal = function (this: HTMLDialogElement) {
    this.setAttribute('open', '');
  };
  HTMLDialogElement.prototype.close = function (this: HTMLDialogElement) {
    if (this.hasAttribute('open')) {
      this.removeAttribute('open');
      this.dispatchEvent(new Event('close'));
    }
  };
});

afterEach(() => {
  document.body.innerHTML = '';
});

describe('sameOriginUrl', () => {
  it('keeps relative paths and this origin, drops other origins and schemes', () => {
    expect(sameOriginUrl('/crm/import')).toBe(true);
    expect(sameOriginUrl(`${window.location.origin}/crm/import/1`)).toBe(true);
    expect(sameOriginUrl('https://evil.test/x')).toBe(false);
    expect(sameOriginUrl('javascript:alert(1)')).toBe(false);
    expect(sameOriginUrl('http://[::1')).toBe(false);
  });
});

describe('parseStep', () => {
  it('cleans the fragment and keeps the forms', () => {
    const parts = parseStep(`<div data-crm-import-step="file" data-crm-import-finished><div data-part="body">
      <script>alert(1)</script><style>p{}</style><p onclick="x()">Hi</p><a href="https://evil.test">x</a>
      <form action="/crm/import"><button formaction="https://evil.test">go</button></form></div>
      <div data-part="footer"><button>Next</button></div></div>`);
    expect(parts.root?.dataset.crmImportStep).toBe('file');
    expect(parts.root?.hasAttribute('data-crm-import-finished')).toBe(true);
    const holder = document.createElement('div');
    holder.append(...parts.body);
    expect(holder.querySelector('script, style')).toBeNull();
    expect(holder.querySelector('p')?.hasAttribute('onclick')).toBe(false);
    expect(holder.querySelector('a')?.hasAttribute('href')).toBe(false);
    expect(holder.querySelector('form')?.getAttribute('action')).toBe('/crm/import');
    expect(holder.querySelector('button')?.hasAttribute('formaction')).toBe(false);
    expect(parts.footer).toHaveLength(1);
  });

  it('gives empty parts for a fragment without them', () => {
    const parts = parseStep('<p>nothing</p>');
    expect(parts.root).toBeNull();
    expect(parts.body).toEqual([]);
    expect(parts.footer).toEqual([]);
  });
});

describe('notice', () => {
  it('builds the notice with or without a reload button', () => {
    const reload = vi.fn();
    const box = notice(dialogElement(), 'Expired', 'expired', reload);
    expect(box.getAttribute('role')).toBe('alert');
    expect(box.className).toBe('nowo-ui-flash nowo-ui-flash--error');
    box.querySelector('button')?.click();
    expect(reload).toHaveBeenCalledOnce();
    expect(notice(document.createElement('div'), 'x', 'failed', null).querySelector('button')).toBeNull();
  });
});

describe('start and the steps', () => {
  it('opens the modal from the page action and loads the first step with the modal headers', async () => {
    const fetcher = vi.fn<typeof fetch>().mockResolvedValue(fragment(step('file')));
    const stop = start(document, { fetcher });
    document.querySelector<HTMLElement>('[data-testid="opener"]')?.click();
    await flush();
    const dialog = dialogElement();
    expect(dialog.open).toBe(true);
    const [url, init] = fetcher.mock.calls[0] ?? [];
    expect(url).toBe('/crm/import?subject=contact');
    expect((init?.headers as Record<string, string>)[REQUEST_HEADER]).toBe('import');
    expect(init?.credentials).toBe('same-origin');
    expect(dialog.dataset.crmImportStep).toBe('file');
    expect(dialog.querySelector('.nowo-ui-dialog__footer [data-testid="submit"]')).not.toBeNull();
    expect(document.activeElement?.getAttribute('name')).toBe('crm_import[subject]');
    stop();
  });

  it('leaves modified clicks, foreign links and pages without the dialog to the browser', () => {
    const fetcher = vi.fn<typeof fetch>();
    const stop = start(document, { fetcher });
    const opener = document.querySelector<HTMLAnchorElement>('[data-testid="opener"]');
    const ctrl = new MouseEvent('click', { bubbles: true, cancelable: true, ctrlKey: true });
    opener?.dispatchEvent(ctrl);
    expect(ctrl.defaultPrevented).toBe(false);
    opener?.setAttribute('href', 'https://evil.test/import');
    const foreign = new MouseEvent('click', { bubbles: true, cancelable: true });
    opener?.dispatchEvent(foreign);
    expect(foreign.defaultPrevented).toBe(false);
    dialogElement().remove();
    opener?.setAttribute('href', '/crm/import');
    const plain = new MouseEvent('click', { bubbles: true, cancelable: true });
    opener?.dispatchEvent(plain);
    expect(plain.defaultPrevented).toBe(false);
    expect(fetcher).not.toHaveBeenCalled();
    stop();
  });

  it('posts a step with its submitter and follows the 204 to the next step', async () => {
    const fetcher = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(fragment(step('file')))
      .mockResolvedValueOnce(answer(null, 204, { [LOCATION_HEADER]: '/crm/import/abc' }))
      .mockResolvedValueOnce(fragment(step('mapping')));
    const modal = openImportDialog({ dialog: dialogElement(), returnTo: null, fetcher });
    await modal.load('/crm/import');
    const submitter = dialogElement().querySelector<HTMLButtonElement>('[data-testid="submit"]');
    const form = dialogElement().querySelector<HTMLFormElement>('form');
    form?.dispatchEvent(new SubmitEvent('submit', { bubbles: true, cancelable: true, submitter }));
    await flush();
    const [postUrl, post] = fetcher.mock.calls[1] ?? [];
    expect(postUrl).toBe(`${window.location.origin}/crm/import`);
    expect(post?.method).toBe('POST');
    const data = post?.body as FormData;
    expect(data.get('crm_import[_token]')).toBe('tok');
    expect(data.get('do')).toBe('file');
    expect(fetcher.mock.calls[2]?.[0]).toBe('/crm/import/abc');
    expect(dialogElement().dataset.crmImportStep).toBe('mapping');
  });

  it('shows errors in place and focuses the first invalid field', async () => {
    const fetcher = vi.fn<typeof fetch>().mockResolvedValue(fragment(step('file', '<input aria-invalid="true" name="bad">'), 422));
    const modal = openImportDialog({ dialog: dialogElement(), returnTo: null, fetcher });
    await modal.load('/crm/import');
    expect(document.activeElement?.getAttribute('name')).toBe('bad');
  });

  it.each([
    ['a 401', answer('{"error":"session_expired"}', 401)],
    ['a redirect to the login page', answer('<html>login</html>', 200, {}, true)],
    ['a page without the fragment header', answer('<html>login</html>', 200)],
  ])('shows the reload notice instead of %s', async (_label, response) => {
    const reload = vi.fn();
    const fetcher = vi.fn<typeof fetch>().mockResolvedValue(response);
    const modal = openImportDialog({ dialog: dialogElement(), returnTo: null, fetcher, reload });
    await modal.load('/crm/import');
    const expired = dialogElement().querySelector<HTMLElement>('[data-crm-import-notice="expired"]');
    expect(expired?.textContent).toContain('Expired');
    expect(dialogElement().textContent).not.toContain('login');
    expect(document.activeElement).toBe(expired?.querySelector('button'));
    expired?.querySelector('button')?.click();
    expect(reload).toHaveBeenCalledOnce();
  });

  it('answers the conflict codes of REQ-RUNTIME-003 rule 6, a refusal and failures in place', async () => {
    const fetcher = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(answer('{"code":"CONFLICT_001"}', 409, { 'X-Conflict-Code': 'CONFLICT_001' }))
      .mockResolvedValueOnce(answer('{"code":"CONFLICT_002"}', 409, { 'X-Conflict-Code': 'CONFLICT_002' }))
      .mockResolvedValueOnce(answer('Forbidden', 403))
      .mockResolvedValueOnce(answer('Boom', 500))
      .mockRejectedValueOnce(new TypeError('offline'))
      .mockResolvedValueOnce(answer(null, 204))
      .mockResolvedValueOnce(answer(null, 204, { [LOCATION_HEADER]: 'https://evil.test/next' }));
    const dialog = dialogElement();
    const modal = openImportDialog({ dialog, returnTo: null, fetcher, reload: vi.fn() });
    const text = (): string => dialog.querySelector('[data-crm-import-notice]')?.textContent ?? '';
    await modal.load('/crm/import/1');
    expect(text()).toBe('Retry');
    expect(dialog.querySelector('[data-crm-import-notice] button')).toBeNull();
    await modal.load('/crm/import/1');
    expect(text()).toContain('Reload first');
    expect(dialog.querySelectorAll('[data-crm-import-notice]')).toHaveLength(1);
    await modal.load('/crm/import/1');
    expect(text()).toBe('Denied');
    for (let i = 0; i < 4; i++) {
      await modal.load('/crm/import/1');
      expect(text()).toBe('Failed');
    }
  });

  it('refuses addresses of other sites', async () => {
    const fetcher = vi.fn<typeof fetch>();
    const modal = openImportDialog({ dialog: dialogElement(), returnTo: null, fetcher });
    await modal.load('https://evil.test/crm/import');
    expect(fetcher).not.toHaveBeenCalled();
    expect(dialogElement().querySelector('[data-crm-import-notice="failed"]')).not.toBeNull();
  });

  it('ignores a second submit while a request is pending', async () => {
    let resolve: (response: Response) => void = () => undefined;
    const fetcher = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(fragment(step('file')))
      .mockImplementationOnce(() => new Promise<Response>((done) => { resolve = done; }));
    const modal = openImportDialog({ dialog: dialogElement(), returnTo: null, fetcher });
    await modal.load('/crm/import');
    const form = dialogElement().querySelector<HTMLFormElement>('form');
    if (form === null) {
      throw new Error('no form');
    }
    void modal.submit(form, null);
    await modal.submit(form, null);
    expect(fetcher).toHaveBeenCalledTimes(2);
    expect(dialogElement().querySelector<HTMLButtonElement>('.nowo-ui-dialog__footer [type="submit"]')?.disabled).toBe(true);
    resolve(fragment(step('mapping')));
    await flush();
    expect(dialogElement().dataset.crmImportStep).toBe('mapping');
  });

  it('closes with Cancel: aborts, wipes the step and returns the focus', async () => {
    let signal: AbortSignal | undefined;
    const fetcher = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(fragment(step('file')))
      .mockImplementationOnce((_url, init) => {
        signal = init?.signal ?? undefined;
        return new Promise<Response>(() => undefined);
      });
    const opener = document.querySelector<HTMLElement>('[data-testid="opener"]');
    const reload = vi.fn();
    const modal = openImportDialog({ dialog: dialogElement(), returnTo: opener, fetcher, reload });
    await modal.load('/crm/import');
    void modal.load('/crm/import/1');
    dialogElement().querySelector<HTMLElement>('.nowo-ui-dialog__footer [data-crm-import-close]')?.click();
    expect(signal?.aborted).toBe(true);
    expect(dialogElement().open).toBe(false);
    expect(dialogElement().querySelector('[data-part="status"]')).not.toBeNull();
    expect(dialogElement().dataset.crmImportStep).toBeUndefined();
    expect(document.activeElement).toBe(opener);
    expect(reload).not.toHaveBeenCalled();
  });

  it('closes with Escape (native close) and reloads the list after a finished import', async () => {
    const fetcher = vi.fn<typeof fetch>().mockResolvedValue(fragment(step('result', '', true)));
    const reload = vi.fn();
    const hidden = document.querySelector<HTMLElement>('[data-testid="hidden-opener"]');
    const modal = openImportDialog({ dialog: dialogElement(), returnTo: hidden, fetcher, reload });
    await modal.load('/crm/import/1');
    dialogElement().close();
    expect(reload).toHaveBeenCalledOnce();
    expect(document.activeElement?.getAttribute('data-testid')).toBe('menu-toggle');
  });

  it('close() on a dialog that is no longer open still cleans up', async () => {
    const fetcher = vi.fn<typeof fetch>().mockResolvedValue(fragment(step('file')));
    const modal = openImportDialog({ dialog: dialogElement(), returnTo: null, fetcher });
    await modal.load('/crm/import');
    dialogElement().removeAttribute('open');
    modal.close();
    expect(dialogElement().querySelector('[data-part="status"]')).not.toBeNull();
  });

  it('keeps the focus inside with Tab and Shift+Tab', async () => {
    const fetcher = vi.fn<typeof fetch>().mockResolvedValue(fragment(step('file')));
    const modal = openImportDialog({ dialog: dialogElement(), returnTo: null, fetcher });
    await modal.load('/crm/import');
    const focusable = [...dialogElement().querySelectorAll<HTMLElement>('button:not([disabled]), input:not([type="hidden"])')];
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    last?.focus();
    const tab = new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true });
    dialogElement().dispatchEvent(tab);
    expect(tab.defaultPrevented).toBe(true);
    expect(document.activeElement).toBe(first);
    const back = new KeyboardEvent('keydown', { key: 'Tab', shiftKey: true, bubbles: true, cancelable: true });
    dialogElement().dispatchEvent(back);
    expect(document.activeElement).toBe(last);
    const other = new KeyboardEvent('keydown', { key: 'a', bubbles: true, cancelable: true });
    dialogElement().dispatchEvent(other);
    expect(other.defaultPrevented).toBe(false);
    focusable[1]?.focus();
    const middle = new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true });
    dialogElement().dispatchEvent(middle);
    expect(middle.defaultPrevented).toBe(false);
  });

  it('does nothing on Tab in a modal without focusable controls', () => {
    vi.spyOn(Element.prototype, 'getClientRects').mockImplementation(() => [] as unknown as DOMRectList);
    openImportDialog({ dialog: dialogElement(), returnTo: null, fetcher: vi.fn<typeof fetch>() });
    const tab = new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true });
    dialogElement().dispatchEvent(tab);
    expect(tab.defaultPrevented).toBe(false);
  });

  it('opens without showModal and focuses the title while a step has no field', async () => {
    const dialog = dialogElement();
    Object.defineProperty(dialog, 'showModal', { value: undefined });
    const fetcher = vi.fn<typeof fetch>().mockResolvedValue(fragment('<div data-crm-import-step="result"><div data-part="body"><p>Done</p></div><div data-part="footer"></div></div>'));
    const modal = openImportDialog({ dialog, returnTo: null, fetcher });
    expect(dialog.hasAttribute('open')).toBe(true);
    await modal.load('/crm/import/1');
    expect(document.activeElement?.classList.contains('nowo-ui-dialog__title')).toBe(true);
  });

  it('needs the UiKit anatomy', () => {
    dialogElement().querySelector('.nowo-ui-dialog__body')?.remove();
    expect(() => openImportDialog({ dialog: dialogElement(), returnTo: null })).toThrow(/anatomy/);
  });

  it('ignores submit events of forms outside the modal', async () => {
    const fetcher = vi.fn<typeof fetch>().mockResolvedValue(fragment(step('file')));
    const modal = openImportDialog({ dialog: dialogElement(), returnTo: null, fetcher });
    await modal.load('/crm/import');
    const event = new Event('submit', { bubbles: true, cancelable: true });
    dialogElement().dispatchEvent(event);
    expect(event.defaultPrevented).toBe(false);
    expect(fetcher).toHaveBeenCalledOnce();
  });

  it('falls back to empty texts on a bare dialog and keeps an alert focusable', async () => {
    const dialog = dialogElement();
    for (const name of ['loading', 'failed', 'expired', 'reload', 'denied', 'conflictRetry', 'conflictReload', 'noticeClass', 'reloadClass']) {
      delete dialog.dataset[name];
    }
    dialog.querySelector('.nowo-ui-dialog__title')?.setAttribute('tabindex', '0');
    const fetcher = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(answer('{}', 401))
      .mockResolvedValueOnce(answer('{}', 409))
      .mockResolvedValueOnce(answer('{}', 409, { 'X-Conflict-Code': 'CONFLICT_002' }))
      .mockResolvedValueOnce(answer('{}', 403))
      .mockRejectedValueOnce(new TypeError('offline'))
      .mockResolvedValueOnce(fragment('<div data-crm-import-step="result"><div data-part="body"><div role="alert" tabindex="0">Stop</div></div></div>'))
      .mockResolvedValueOnce(fragment('<div><div data-part="body"><p>x</p></div></div>'));
    const modal = openImportDialog({ dialog, returnTo: null, fetcher, reload: vi.fn() });
    expect(dialog.querySelector('.nowo-ui-dialog__title')?.getAttribute('tabindex')).toBe('0');
    await modal.load('/crm/import');
    expect(dialog.querySelector('[data-crm-import-notice="expired"] button')?.textContent).toBe('');
    await modal.load('/crm/import');
    expect(dialog.querySelector('[data-crm-import-notice="conflict"]')?.textContent).toBe('');
    await modal.load('/crm/import');
    await modal.load('/crm/import');
    expect(dialog.querySelector('[data-crm-import-notice="denied"]')).not.toBeNull();
    await modal.load('/crm/import');
    expect(dialog.querySelector('[data-crm-import-notice="failed"]')?.textContent).toContain('offline');
    await modal.load('/crm/import/1');
    expect(document.activeElement?.textContent).toBe('Stop');
    expect(document.activeElement?.getAttribute('tabindex')).toBe('0');
    await modal.load('/crm/import/1');
    expect(dialog.dataset.crmImportStep).toBe('');
    await modal.load('https://evil.test/');
    expect(dialog.querySelector('[data-crm-import-notice="failed"]')?.textContent).toBe('');
  });

  it('focuses a role=alert error of the step and sends no name of an unnamed submitter', async () => {
    const fetcher = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(fragment(step('file', '<div role="alert">CSRF</div>')))
      .mockResolvedValueOnce(fragment(step('file')));
    const opener = document.createElement('button');
    const modal = openImportDialog({ dialog: dialogElement(), returnTo: opener, fetcher });
    await modal.load('/crm/import');
    expect(document.activeElement?.textContent).toBe('CSRF');
    expect(document.activeElement?.getAttribute('tabindex')).toBe('-1');
    const form = dialogElement().querySelector<HTMLFormElement>('form');
    if (form === null) {
      throw new Error('no form');
    }
    const plain = document.createElement('button');
    await modal.submit(form, plain);
    expect([...(fetcher.mock.calls[1]?.[1]?.body as FormData).keys()]).not.toContain('');
    modal.close();
    expect(document.activeElement).not.toBe(opener);
  });

  it('drops an answer that arrives after the modal closed', async () => {
    let resolve: (response: Response) => void = () => undefined;
    const fetcher = vi.fn<typeof fetch>().mockImplementationOnce(() => new Promise<Response>((done) => { resolve = done; }));
    const modal = openImportDialog({ dialog: dialogElement(), returnTo: null, fetcher });
    const pending = modal.load('/crm/import');
    modal.close();
    resolve(fragment(step('mapping')));
    await pending;
    expect(dialogElement().dataset.crmImportStep).toBeUndefined();
  });

  it('opens with the dialog address when the opener has no href, and not twice', async () => {
    const fetcher = vi.fn<typeof fetch>().mockResolvedValue(fragment(step('file')));
    const stop = start(document, { fetcher });
    const button = document.createElement('button');
    button.setAttribute('data-crm-import-open', '');
    document.body.append(button);
    button.click();
    await flush();
    expect(fetcher.mock.calls[0]?.[0]).toBe('/crm/import');
    button.click();
    expect(fetcher).toHaveBeenCalledOnce();
    document.body.click();
    stop();
  });

  it('links the import page from the expired and CONFLICT_002 notices once a step named it (XU-M1)', async () => {
    const dialog = dialogElement();
    dialog.dataset.openPage = 'Open the import page';
    const named = '<div data-crm-import-step="preview" data-crm-import-page="/crm/import/abc"><div data-part="body"><p>x</p></div><div data-part="footer"></div></div>';
    const fetcher = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(answer('{}', 401))
      .mockResolvedValueOnce(fragment(named))
      .mockResolvedValueOnce(answer('{}', 409, { 'X-Conflict-Code': 'CONFLICT_002' }))
      .mockResolvedValueOnce(answer('{}', 401))
      .mockResolvedValueOnce(answer('{}', 409, { 'X-Conflict-Code': 'CONFLICT_001' }));
    const modal = openImportDialog({ dialog, returnTo: null, fetcher, reload: vi.fn() });
    await modal.load('/crm/import');
    expect(dialog.querySelector('[data-crm-import-notice] a')).toBeNull();
    await modal.load('/crm/import/abc');
    await modal.load('/crm/import/abc');
    const link = dialog.querySelector<HTMLAnchorElement>('[data-crm-import-notice="conflict"] a[data-testid="crm-import-open-page"]');
    expect(link?.getAttribute('href')).toBe('/crm/import/abc');
    expect(link?.textContent).toBe('Open the import page');
    await modal.load('/crm/import/abc');
    expect(dialog.querySelector('[data-crm-import-notice="expired"] a')?.getAttribute('href')).toBe('/crm/import/abc');
    await modal.load('/crm/import/abc');
    expect(dialog.querySelector('[data-crm-import-notice="conflict"] a')).toBeNull();
    modal.close();
    const again = openImportDialog({ dialog, returnTo: null, fetcher: vi.fn<typeof fetch>().mockResolvedValue(answer('{}', 401)), reload: vi.fn() });
    await again.load('/crm/import');
    expect(dialog.querySelector('[data-crm-import-notice] a')).toBeNull();
  });

  it('never links a page of another site, and falls back to an empty link text', () => {
    const box = notice(document.createElement('div'), 'x', 'expired', null, 'https://evil.test/crm/import/1');
    expect(box.querySelector('a')).toBeNull();
    expect(notice(document.createElement('div'), 'x', 'expired', null, '/crm/import/1').querySelector('a')?.textContent).toBe('');
  });

  it('puts the steps of each step in the header and restores them on close (0.4.1)', async () => {
    const fetcher = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(fragment(step('file')))
      .mockResolvedValueOnce(fragment(step('mapping')))
      .mockResolvedValueOnce(fragment('<div data-crm-import-step="result"><div data-part="body"><p>x</p></div></div>'));
    const modal = openImportDialog({ dialog: dialogElement(), returnTo: null, fetcher });
    const header = (): string | null | undefined => dialogElement().querySelector('.nowo-ui-dialog__steps ol')?.getAttribute('data-testid');
    await modal.load('/crm/import');
    expect(header()).toBe('steps-file');
    await modal.load('/crm/import/1');
    expect(header()).toBe('steps-mapping');
    await modal.load('/crm/import/1');
    expect(header()).toBe('steps-mapping');
    modal.close();
    expect(header()).toBe('steps-initial');
  });

  it('works with a header without steps (UiKit before 2.63)', async () => {
    dialogElement().querySelector('.nowo-ui-dialog__steps')?.remove();
    const fetcher = vi.fn<typeof fetch>().mockResolvedValue(fragment(step('file')));
    const modal = openImportDialog({ dialog: dialogElement(), returnTo: null, fetcher });
    await modal.load('/crm/import');
    expect(dialogElement().dataset.crmImportStep).toBe('file');
    modal.close();
    expect(dialogElement().querySelector('.nowo-ui-dialog__steps')).toBeNull();
  });
});
