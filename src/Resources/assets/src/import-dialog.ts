/**
 * 0.4.0: the import modal of the CRM lists (UiKit PAGE_CONTRACT "Dialog sizes and anatomy"). The markup is the Twig template
 * `import/_dialog_shell.html.twig`: UiKit's confirm_dialog anatomy (header with the title and ×, a body that alone
 * scrolls, a footer with Cancel then the one primary; 0.4.1, UiKit 2.63: the steps in the header and a full-screen sheet
 * on phones). Any `[data-crm-import-open]` link opens it (the page action
 * "Import"; its href is the import page, the no-JS fallback). Each step (file → mapping → preview → result) is a
 * fragment the import routes answer to `X-Nowo-Crm-Dialog: import` (same access checks and CSRF tokens as the pages):
 * its `[data-part="body"]` goes to the dialog body and its `[data-part="footer"]` to the dialog footer. A form of the
 * step posts with fetch; 204 + `X-Nowo-Crm-Dialog-Location` loads the next step; errors come back in the fragment and
 * show in place. Signed out (401, a redirect, an answer without the fragment header) → a "session expired" notice with
 * a reload button, never the login page in the modal. Closing aborts the pending request and wipes the step; focus
 * stays in the modal (Tab cycles) and returns to the opener. No inline style, no HTML string built here (CSP).
 */

/** Request header of the modal's calls (`ImportDialog::REQUEST_HEADER`). */
export const REQUEST_HEADER = 'X-Nowo-Crm-Dialog';

/** Response header that marks a step fragment (`ImportDialog::FRAGMENT_HEADER`). */
export const FRAGMENT_HEADER = 'X-Nowo-Crm-Fragment';

/** Response header of a 204: the next step's address (`ImportDialog::LOCATION_HEADER`). */
export const LOCATION_HEADER = 'X-Nowo-Crm-Dialog-Location';

/** Elements that take focus with Tab inside the modal. */
const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), summary, [tabindex]:not([tabindex="-1"])';

/** Attributes holding an address: kept only for a relative path or this origin (http / https). */
const URL_ATTRIBUTES = ['href', 'src', 'srcset', 'action', 'formaction', 'xlink:href', 'poster', 'data'];

/** What the modal needs. */
export interface ImportDialogOptions {
  /** The `<dialog data-crm-import-dialog>` of the page. */
  dialog: HTMLDialogElement;
  /** Focused again when the modal closes. */
  returnTo: HTMLElement | null;
  /** `fetch` (tests). */
  fetcher?: typeof fetch;
  /** Reloads the page (tests); called by the reload buttons and when a finished import's modal closes. */
  reload?: () => void;
}

/** The open modal and its controls. */
export interface ImportDialog {
  dialog: HTMLDialogElement;
  /**
   * Loads a step.
   *
   * @param url Address of the step (same origin).
   * @returns Resolves once the step is shown.
   */
  load(url: string): Promise<void>;
  /**
   * Posts a form of the step.
   *
   * @param form The form.
   * @param submitter The button that submitted it (its name / value are sent).
   * @returns Resolves once the answer is shown.
   */
  submit(form: HTMLFormElement, submitter: HTMLElement | null): Promise<void>;
  /** Closes the modal (aborts the pending request, wipes the step). */
  close(): void;
}

/**
 * Whether an address stays on this site: a relative path, or an absolute http(s) URL of this origin.
 *
 * @param value The address.
 * @returns True when it may be used.
 */
export function sameOriginUrl(value: string): boolean {
  try {
    const url = new URL(value, window.location.href);
    return (url.protocol === 'http:' || url.protocol === 'https:') && url.origin === window.location.origin;
  } catch {
    return false;
  }
}

/** The parts of a step fragment. */
export interface StepParts {
  /** The step root (`[data-crm-import-step]`) or null when the fragment has none. */
  root: HTMLElement | null;
  /** Nodes of the dialog body. */
  body: Node[];
  /** Nodes of the dialog footer. */
  footer: Node[];
  /** Nodes of the header's steps (0.4.1, UiKit 2.63 `nowo-ui-dialog__steps`). */
  steps: Node[];
}

/**
 * Parses a step fragment into nodes of this document. The fragment is the bundle's escaped Twig output; it is still
 * cleaned here: no script, style, frame, object, embed, base, meta, link or template element, no `on*` attribute, and
 * no address that leaves the site (`href`, `action`, `formaction`… must be a relative path or this origin). Forms stay:
 * they are the steps.
 *
 * @param html The fragment.
 * @returns Its parts.
 */
export function parseStep(html: string): StepParts {
  const parsed = new DOMParser().parseFromString(`<body>${html}</body>`, 'text/html');
  parsed.querySelectorAll('script, style, iframe, frame, object, embed, base, meta, link, template').forEach((node) => node.remove());
  parsed.body.querySelectorAll('*').forEach((element) => {
    [...element.attributes].forEach((attribute) => {
      const name = attribute.name.toLowerCase();
      if (name.startsWith('on') || (URL_ATTRIBUTES.includes(name) && !sameOriginUrl(attribute.value))) {
        element.removeAttribute(attribute.name);
      }
    });
  });
  const root = parsed.body.querySelector<HTMLElement>('[data-crm-import-step]');
  const nodes = (selector: string): Node[] => {
    const part = parsed.body.querySelector(selector);
    return part === null ? [] : [...part.childNodes].map((node) => document.importNode(node, true));
  };
  return {
    root: root === null ? null : (document.importNode(root, false) as HTMLElement),
    body: nodes('[data-part="body"]'),
    footer: nodes('[data-part="footer"]'),
    steps: nodes('[data-part="steps"]'),
  };
}

/**
 * A notice of the modal (UiKit notice class and a text from the dialog's data attributes), with a reload button when
 * asked.
 *
 * @param dialog The dialog (holds the texts and classes).
 * @param text The text.
 * @param kind Its `data-crm-import-notice` value (tests, e2e).
 * @param reload Called by the reload button; null for no button.
 * @param page The import page (same origin) to link to, so an unfinished import stays reachable; null for no link.
 * @returns The notice.
 */
export function notice(dialog: HTMLElement, text: string, kind: string, reload: (() => void) | null, page: string | null = null): HTMLElement {
  const box = document.createElement('div');
  box.className = dialog.dataset.noticeClass ?? '';
  box.setAttribute('role', 'alert');
  box.dataset.crmImportNotice = kind;
  box.dataset.testid = `crm-import-${kind}`;
  const paragraph = document.createElement('p');
  paragraph.textContent = text;
  box.append(paragraph);
  if (reload !== null) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = dialog.dataset.reloadClass ?? '';
    button.dataset.testid = 'crm-import-reload';
    button.textContent = dialog.dataset.reload ?? '';
    button.addEventListener('click', () => reload());
    box.append(button);
  }
  if (page !== null && sameOriginUrl(page)) {
    const link = document.createElement('a');
    link.href = page;
    link.dataset.testid = 'crm-import-open-page';
    link.textContent = dialog.dataset.openPage ?? '';
    const line = document.createElement('p');
    line.append(link);
    box.append(line);
  }
  return box;
}

/**
 * Whether an element is shown (focus can go there).
 *
 * @param element The element.
 * @returns True when it has a layout box.
 */
function shown(element: HTMLElement): boolean {
  return element.getClientRects().length > 0;
}

/**
 * Builds the modal on a dialog and opens it.
 *
 * @param options What the modal needs.
 * @returns The open modal.
 */
export function openImportDialog(options: ImportDialogOptions): ImportDialog {
  const { dialog } = options;
  const body = dialog.querySelector<HTMLElement>('.nowo-ui-dialog__body');
  const footer = dialog.querySelector<HTMLElement>('.nowo-ui-dialog__footer');
  const title = dialog.querySelector<HTMLElement>('.nowo-ui-dialog__title');
  // 0.4.1: the steps live in the header (UiKit 2.63); each step replaces them.
  const headerSteps = dialog.querySelector<HTMLElement>('.nowo-ui-dialog__steps');
  if (body === null || footer === null || title === null) {
    throw new Error('NowoCrmImport: the import dialog needs the UiKit anatomy (header title, body, footer).');
  }
  const fetcher = options.fetcher ?? window.fetch.bind(window);
  const reload = options.reload ?? ((): void => window.location.reload());
  const initialBody = [...body.childNodes].map((node) => node.cloneNode(true));
  const initialFooter = [...footer.childNodes].map((node) => node.cloneNode(true));
  const initialSteps = [...(headerSteps?.childNodes ?? [])].map((node) => node.cloneNode(true));
  let controller: AbortController | null = null;
  let finished = false;
  /** The page of the import the modal works on, once a step named it (audit XU-M1). */
  let importPage: string | null = null;

  const setBusy = (busy: boolean): void => {
    body.setAttribute('aria-busy', busy ? 'true' : 'false');
    footer.querySelectorAll<HTMLButtonElement>('button[type="submit"]').forEach((button) => {
      button.disabled = busy;
    });
  };

  /** Puts a notice at the top of the body (the step stays, so the person can try again). */
  const showNotice = (text: string, kind: string, withReload: boolean, withPage = false): void => {
    body.querySelectorAll('[data-crm-import-notice]').forEach((node) => node.remove());
    body.prepend(notice(dialog, text, kind, withReload ? reload : null, withPage ? importPage : null));
  };

  const focusStep = (): void => {
    const target = body.querySelector<HTMLElement>('[aria-invalid="true"]')
      ?? body.querySelector<HTMLElement>('[data-crm-import-notice] button')
      ?? body.querySelector<HTMLElement>('[role="alert"]')
      ?? [...body.querySelectorAll<HTMLElement>('input:not([type="hidden"]), select, textarea')].find(shown)
      ?? null;
    if (target !== null) {
      if (target.getAttribute('role') === 'alert' && !target.hasAttribute('tabindex')) {
        target.setAttribute('tabindex', '-1');
      }
      target.focus();
      return;
    }
    title.focus();
  };

  const render = (html: string): void => {
    const step = parseStep(html);
    body.replaceChildren(...step.body);
    footer.replaceChildren(...step.footer);
    if (headerSteps !== null && step.steps.length > 0) {
      headerSteps.replaceChildren(...step.steps);
    }
    finished = finished || (step.root?.hasAttribute('data-crm-import-finished') ?? false);
    importPage = step.root?.dataset.crmImportPage ?? importPage;
    dialog.dataset.crmImportStep = step.root?.dataset.crmImportStep ?? '';
    focusStep();
  };

  /** Shows the answer of a step request; a 204 loads the next step. */
  const handle = async (response: Response, signal: AbortSignal): Promise<void> => {
    const isStep = response.headers.get(FRAGMENT_HEADER) === 'import-step';
    // Signed out (401, a redirect to the login page, or a page without the fragment header): never show that page in
    // the modal; say the session expired and offer a reload.
    if (response.status === 401 || response.redirected || (response.ok && response.status !== 204 && !isStep)) {
      showNotice(dialog.dataset.expired ?? '', 'expired', true, true);
      focusStep();
      return;
    }
    if (response.status === 204) {
      const next = response.headers.get(LOCATION_HEADER);
      if (next === null || !sameOriginUrl(next)) {
        throw new Error('NowoCrmImport: a 204 without a same-origin next step.');
      }
      await request(next, { method: 'GET' }, signal);
      return;
    }
    if (isStep) {
      render(await response.text());
      return;
    }
    if (response.status === 409) {
      // REQ-RUNTIME-003 rule 6: CONFLICT_001 = nothing was saved, try again; CONFLICT_002 = part was saved, reload.
      const code = response.headers.get('X-Conflict-Code');
      if (code === 'CONFLICT_002') {
        showNotice(dialog.dataset.conflictReload ?? '', 'conflict', true, true);
      } else {
        showNotice(dialog.dataset.conflictRetry ?? '', 'conflict', false);
      }
      focusStep();
      return;
    }
    if (response.status === 403) {
      showNotice(dialog.dataset.denied ?? '', 'denied', false);
      focusStep();
      return;
    }
    throw new Error(`HTTP ${response.status}`);
  };

  const request = async (url: string, init: RequestInit, signal: AbortSignal): Promise<void> => {
    const response = await fetcher(url, {
      ...init,
      headers: { [REQUEST_HEADER]: 'import', 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' },
      credentials: 'same-origin',
      signal,
    });
    if (signal.aborted) {
      return;
    }
    await handle(response, signal);
  };

  const run = async (url: string, init: RequestInit): Promise<void> => {
    if (!sameOriginUrl(url)) {
      // The import routes are on this site: anything else is never fetched.
      showNotice(dialog.dataset.failed ?? '', 'failed', false);
      return;
    }
    controller?.abort();
    const current = new AbortController();
    controller = current;
    setBusy(true);
    try {
      await request(url, init, current.signal);
    } catch (error) {
      if (!current.signal.aborted) {
        showNotice(dialog.dataset.failed ?? String(error), 'failed', false);
        focusStep();
      }
    } finally {
      if (controller === current) {
        controller = null;
        setBusy(false);
      }
    }
  };

  const load = (url: string): Promise<void> => run(url, { method: 'GET' });

  const submit = (form: HTMLFormElement, submitter: HTMLElement | null): Promise<void> => {
    if (controller !== null) {
      return Promise.resolve();
    }
    const data = new FormData(form);
    if (submitter instanceof HTMLButtonElement && submitter.name !== '') {
      data.append(submitter.name, submitter.value);
    }
    return run(form.action, { method: 'POST', body: data });
  };

  const wipe = (): void => {
    body.replaceChildren(...initialBody.map((node) => node.cloneNode(true)));
    footer.replaceChildren(...initialFooter.map((node) => node.cloneNode(true)));
    headerSteps?.replaceChildren(...initialSteps.map((node) => node.cloneNode(true)));
    delete dialog.dataset.crmImportStep;
    importPage = null;
  };

  const onClick = (event: Event): void => {
    const target = event.target instanceof Element ? event.target : null;
    if (target?.closest('[data-crm-import-close], [data-confirm-dialog-close]')) {
      event.preventDefault();
      close();
    }
    // 0.10.0: a link of the flow (the HubSpot source and its steps) loads in the modal; its href is the page.
    const link = target?.closest<HTMLAnchorElement>('a[data-crm-import-link]');
    if (link && dialog.contains(link)) {
      event.preventDefault();
      if (controller === null) {
        void load(link.href);
      }
    }
  };
  const onSubmit = (event: SubmitEvent): void => {
    const form = event.target instanceof HTMLFormElement ? event.target : null;
    if (form === null || !dialog.contains(form)) {
      return;
    }
    event.preventDefault();
    void submit(form, event.submitter instanceof HTMLElement ? event.submitter : null);
  };
  // Focus stays inside: Tab from the last control goes to the first, Shift+Tab from the first to the last.
  const onKeydown = (event: KeyboardEvent): void => {
    if (event.key !== 'Tab') {
      return;
    }
    const focusable = [...dialog.querySelectorAll<HTMLElement>(FOCUSABLE)].filter(shown);
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (first === undefined || last === undefined) {
      return;
    }
    if (event.shiftKey && (document.activeElement === first || !dialog.contains(document.activeElement))) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && (document.activeElement === last || !dialog.contains(document.activeElement))) {
      event.preventDefault();
      first.focus();
    }
  };
  // Escape closes the native dialog itself; `close` cleans up for every way out.
  const onClose = (): void => {
    controller?.abort();
    controller = null;
    dialog.removeEventListener('click', onClick);
    dialog.removeEventListener('submit', onSubmit);
    dialog.removeEventListener('keydown', onKeydown);
    dialog.removeEventListener('close', onClose);
    wipe();
    setBusy(false);
    const back = options.returnTo;
    if (back !== null && back.isConnected) {
      (shown(back) ? back : (back.closest('[data-nowo-ui-page-actions], .nowo-ui-menu')?.querySelector<HTMLElement>('button') ?? back)).focus();
    }
    if (finished) {
      // New records: show them in the list.
      reload();
    }
  };

  function close(): void {
    if (dialog.open) {
      dialog.close();
    } else {
      onClose();
    }
  }

  dialog.addEventListener('click', onClick);
  dialog.addEventListener('submit', onSubmit);
  dialog.addEventListener('keydown', onKeydown);
  dialog.addEventListener('close', onClose);
  if (!title.hasAttribute('tabindex')) {
    title.setAttribute('tabindex', '-1');
  }
  if (!dialog.open) {
    if (typeof dialog.showModal === 'function') {
      dialog.showModal();
    } else {
      dialog.setAttribute('open', '');
    }
  }
  title.focus();
  return { dialog, load, submit, close };
}

/**
 * Starts the modal on a page: a click on a `[data-crm-import-open]` link opens the page's import dialog and loads the
 * link's address (the import page answers the first step to the modal). A click with a modifier key, or a page without
 * the dialog, follows the link (the import page).
 *
 * @param root The document.
 * @param options Test seams (`fetch`, reload).
 * @returns A function that removes the listener.
 */
export function start(root: Document = document, options: Pick<ImportDialogOptions, 'fetcher' | 'reload'> = {}): () => void {
  const onClick = (event: MouseEvent): void => {
    const target = event.target instanceof Element ? event.target : null;
    const opener = target?.closest<HTMLElement>('[data-crm-import-open]');
    const dialog = root.querySelector<HTMLDialogElement>('dialog[data-crm-import-dialog]');
    if (!opener || dialog === null || dialog.open || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) {
      return;
    }
    const url = opener.getAttribute('href') ?? dialog.dataset.crmImportDialog ?? '';
    if (url === '' || !sameOriginUrl(url)) {
      return;
    }
    event.preventDefault();
    const modal = openImportDialog({ dialog, returnTo: opener, ...options });
    void modal.load(url);
  };
  root.addEventListener('click', onClick);
  return () => root.removeEventListener('click', onClick);
}
