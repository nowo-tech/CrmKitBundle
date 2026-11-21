import AxeBuilder from '@axe-core/playwright';
import { test, expect, type Locator, type Page } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { expectNoHorizontalScroll, login } from './helpers';

/**
 * 0.4.0 — the import modal of the lists, at desktop and phone sizes: the page action "Import" opens UiKit's
 * confirm_dialog anatomy; the steps (file → mapping → preview → result) run inside it; the footer (Cancel, then one
 * primary) stays in view; axe finds nothing in the open modal; keyboard: Enter opens it, Tab stays inside, Escape
 * closes it and gives the focus back to the opener; an expired session shows the reload notice inside the modal; the
 * import page still works without JavaScript. IMPORT_SHOTS_DIR (optional) saves screenshots.
 */
test.describe.configure({ timeout: 120_000 });

const shotsDir = process.env.IMPORT_SHOTS_DIR;

function csv(prefix: string): Buffer {
  const stamp = Date.now();
  return Buffer.from(`first_name,last_name,email\n${prefix}Grace,Hopper,grace.${stamp}@demo.test\n${prefix}Alan,Turing,alan.${stamp}@demo.test\n`);
}

function dialog(page: Page): Locator {
  return page.getByTestId('crm-import-dialog');
}

async function settled(page: Page): Promise<void> {
  // No hover state and the dialog's entrance transition finished (axe reads colours mid-fade otherwise).
  await page.mouse.move(1, 1);
  await dialog(page).evaluate((element) => Promise.all(element.getAnimations({ subtree: true }).map((animation) => animation.finished)));
}

async function axe(page: Page): Promise<void> {
  await settled(page);
  // UiKit 2.63 defect, reported upstream: the upcoming step labels of the header steps use the muted colour on the
  // tinted header (4.39:1, under 4.5:1). On phones those labels are hidden (compact steps), so the phone checks see
  // every node; on desktop only those labels are left out until UiKit fixes the colour.
  const results = await new AxeBuilder({ page })
    .include('[data-testid="crm-import-dialog"]')
    .exclude('.nowo-ui-dialog__steps .nowo-ui-steps__item--upcoming .nowo-ui-steps__label')
    .analyze();
  expect(results.violations.map((v) => `${v.id}: ${v.nodes.map((n) => `${n.target.join(' ')} (${n.any.map((check) => check.message).join('; ')})`).join(', ')}`)).toEqual([]);
}

async function shot(page: Page, name: string): Promise<void> {
  if (!shotsDir) {
    return;
  }
  mkdirSync(shotsDir, { recursive: true });
  await settled(page);
  await page.screenshot({ path: resolve(shotsDir, `${name}.png`) });
}

/** The modal is inside the viewport, its header and footer in view, and nothing scrolls sideways. */
async function fits(page: Page): Promise<void> {
  await settled(page);
  const viewport = page.viewportSize();
  const box = await dialog(page).boundingBox();
  expect(box).not.toBeNull();
  if (box === null || viewport === null) {
    return;
  }
  expect(box.x).toBeGreaterThanOrEqual(0);
  expect(box.x + box.width).toBeLessThanOrEqual(viewport.width + 0.5);
  expect(box.y).toBeGreaterThanOrEqual(0);
  expect(box.y + box.height).toBeLessThanOrEqual(viewport.height + 0.5);
  await expect(dialog(page).locator('.nowo-ui-dialog__header')).toBeInViewport();
  await expect(dialog(page).locator('.nowo-ui-dialog__footer')).toBeInViewport();
  await expectNoHorizontalScroll(page);
  if (viewport.width <= 640) {
    // 0.4.1 (UiKit 2.63 phone sheet): the modal fills the screen.
    expect(Math.round(box.x)).toBe(0);
    expect(Math.round(box.y)).toBe(0);
    expect(Math.round(box.width)).toBe(viewport.width);
    expect(Math.round(box.height)).toBe(viewport.height);
  }
  // The steps are in the header (UiKit 2.63 header_steps), not in the body.
  await expect(dialog(page).locator('.nowo-ui-dialog__header .nowo-ui-dialog__steps [aria-current="step"]')).toHaveCount(1);
  await expect(dialog(page).locator('.nowo-ui-dialog__body .nowo-ui-steps')).toHaveCount(0);
}

for (const size of [
  { name: 'desktop', viewport: { width: 1280, height: 800 } },
  { name: 'phone', viewport: { width: 390, height: 844 } },
]) {
  test.describe(`import modal (${size.name}, 0.4.0)`, () => {
    test.use({ viewport: size.viewport });

    test('the steps run inside the modal and the list shows the new contacts', async ({ page }) => {
      await login(page, 'mia');
      await page.goto('/crm/contacts');
      const opener = page.getByTestId('crm-import-open');
      await expect(opener).toHaveAttribute('href', '/crm/import?subject=contact');
      await opener.click();
      await expect(dialog(page)).toBeVisible();
      await expect(page).toHaveURL(/\/crm\/contacts$/);
      await expect(dialog(page).locator('.nowo-ui-dialog__body input[type="file"]')).toBeVisible();
      await expect(dialog(page).locator('.nowo-ui-dialog__body .nowo-ui-card')).toHaveCount(0);
      await expect(dialog(page).locator('.nowo-ui-dialog__footer .nowo-ui-btn--primary')).toHaveCount(1);
      await expect(dialog(page).locator('.nowo-ui-dialog__footer button').first()).toHaveAttribute('data-testid', 'crm-import-dialog-cancel');
      await fits(page);
      await axe(page);
      await shot(page, `import-modal-file-${size.name}`);

      const prefix = size.name === 'phone' ? 'P' : 'D';
      await dialog(page).locator('input[type="file"]').setInputFiles({ name: 'people.csv', mimeType: 'text/csv', buffer: csv(prefix) });
      await dialog(page).getByTestId('crm-import-dialog-submit').click();
      await expect(dialog(page).getByTestId('crm-import-mapping')).toBeVisible();
      await fits(page);
      await axe(page);
      await shot(page, `import-modal-mapping-${size.name}`);

      await dialog(page).getByTestId('crm-import-dialog-submit').click();
      await expect(dialog(page).getByTestId('crm-import-preview')).toContainText(`${prefix}Grace`);
      await expect(dialog(page).getByTestId('crm-import-dialog-submit')).toHaveText(/Import/);
      await fits(page);
      await axe(page);
      await shot(page, `import-modal-preview-${size.name}`);

      await dialog(page).getByTestId('crm-import-dialog-submit').click();
      await expect(dialog(page).getByTestId('crm-import-result')).toHaveAttribute('data-status', 'done');
      await expect(dialog(page).locator('.nowo-ui-dialog__footer .nowo-ui-btn--primary')).toHaveCount(0);
      await fits(page);
      await axe(page);
      await shot(page, `import-modal-result-${size.name}`);

      // Closing a finished import reloads the list, which now shows the new contacts.
      await Promise.all([page.waitForEvent('load'), dialog(page).getByTestId('crm-import-dialog-close').click()]);
      await expect(dialog(page)).toBeHidden();
      await expect(page.getByTestId('crm-list-contact')).toContainText(`${prefix}Grace`);
    });

    test('keyboard: Enter opens, Tab stays inside, Escape closes and the focus returns', async ({ page }) => {
      await login(page, 'mia');
      await page.goto('/crm/contacts');
      const opener = page.getByTestId('crm-import-open');
      await opener.focus();
      await page.keyboard.press('Enter');
      await expect(dialog(page)).toBeVisible();
      await expect(dialog(page).locator('select[name="crm_import[subject]"]')).toBeFocused();
      for (let i = 0; i < 12; i++) {
        await page.keyboard.press('Tab');
        expect(await dialog(page).evaluate((element) => element.contains(document.activeElement))).toBe(true);
      }
      for (let i = 0; i < 12; i++) {
        await page.keyboard.press('Shift+Tab');
        expect(await dialog(page).evaluate((element) => element.contains(document.activeElement))).toBe(true);
      }
      await page.keyboard.press('Escape');
      await expect(dialog(page)).toBeHidden();
      await expect(opener).toBeFocused();

      // Cancel closes too, and the modal opens again on its first step.
      await opener.click();
      await expect(dialog(page).locator('input[type="file"]')).toBeVisible();
      await dialog(page).getByTestId('crm-import-dialog-cancel').last().click();
      await expect(dialog(page)).toBeHidden();
      await expect(opener).toBeFocused();
    });

    test('an expired session shows the reload notice inside the modal', async ({ page, context }) => {
      await login(page, 'mia');
      await page.goto('/crm/contacts');
      await page.getByTestId('crm-import-open').click();
      await expect(dialog(page).locator('input[type="file"]')).toBeVisible();
      await context.clearCookies();
      await dialog(page).locator('input[type="file"]').setInputFiles({ name: 'people.csv', mimeType: 'text/csv', buffer: csv('X') });
      await dialog(page).getByTestId('crm-import-dialog-submit').click();
      const expired = dialog(page).getByTestId('crm-import-expired');
      await expect(expired).toBeVisible();
      await expect(expired.getByTestId('crm-import-reload')).toBeFocused();
      await expect(dialog(page).locator('input[name="_username"]')).toHaveCount(0);
      await expect(page).toHaveURL(/\/crm\/contacts$/);
      await axe(page);
      await shot(page, `import-modal-expired-${size.name}`);
    });
  });
}

test.describe('phone sheet (0.4.1, UiKit 2.63)', () => {
  test.use({ viewport: { width: 390, height: 844 } });

  test('on a 390px phone the modal fills the screen and only the body scrolls', async ({ page }) => {
    await login(page, 'mia');
    await page.goto('/crm/contacts');
    await page.getByTestId('crm-import-open').click();
    // Many columns: the mapping step is taller than the screen.
    const columns = ['first_name', 'last_name', 'email', ...Array.from({ length: 14 }, (_, i) => `extra_${i}`)];
    const csvText = `${columns.join(',')}\n${columns.map((c) => `v_${c}`).join(',')}\n`;
    await dialog(page).locator('input[type="file"]').setInputFiles({ name: 'wide.csv', mimeType: 'text/csv', buffer: Buffer.from(csvText) });
    await dialog(page).getByTestId('crm-import-dialog-submit').click();
    await expect(dialog(page).getByTestId('crm-import-mapping')).toBeVisible();
    await expect(dialog(page)).toHaveClass(/nowo-ui-confirm-dialog--sheet/);
    await fits(page);
    const body = dialog(page).locator('.nowo-ui-dialog__body');
    expect(await body.evaluate((element) => element.scrollHeight > element.clientHeight)).toBe(true);
    await body.evaluate((element) => element.scrollTo(0, element.scrollHeight));
    await expect(dialog(page).locator('.nowo-ui-dialog__header')).toBeInViewport({ ratio: 1 });
    await expect(dialog(page).locator('.nowo-ui-dialog__footer')).toBeInViewport({ ratio: 1 });
    await expect(dialog(page).locator('select').last()).toBeInViewport();
    expect(await page.evaluate(() => window.scrollY)).toBe(0);
    await axe(page);
    await shot(page, 'import-modal-sheet-scrolled-phone');
    await page.keyboard.press('Escape');
    await expect(dialog(page)).toBeHidden();
  });
});

test.describe('an unfinished import stays reachable (audit XU-M1)', () => {
  test('a paused import is linked from the result step and from the list once the modal is closed', async ({ page }) => {
    await login(page, 'mia');
    await page.goto('/crm/companies');
    // More rows than import.sync_rows (500): the confirm runs 500, the rest waits for "Continue".
    const stamp = Date.now();
    let rows = 'name\n';
    for (let i = 0; i < 520; i++) {
      rows += `Paused ${stamp} ${i}\n`;
    }
    await page.getByTestId('crm-import-open').click();
    await dialog(page).locator('input[type="file"]').setInputFiles({ name: `paused-${stamp}.csv`, mimeType: 'text/csv', buffer: Buffer.from(rows) });
    await dialog(page).getByTestId('crm-import-dialog-submit').click();
    await expect(dialog(page).getByTestId('crm-import-mapping')).toBeVisible();
    await dialog(page).getByTestId('crm-import-dialog-submit').click();
    await expect(dialog(page).getByTestId('crm-import-preview')).toBeVisible();
    await dialog(page).getByTestId('crm-import-dialog-submit').click();
    const result = dialog(page).getByTestId('crm-import-result');
    await expect(result).toHaveAttribute('data-status', 'running', { timeout: 60_000 });
    const link = dialog(page).getByTestId('crm-import-open-page');
    await expect(link).toBeVisible();
    const href = await link.getAttribute('href');
    expect(href).toMatch(/^\/crm\/import\/[0-9a-f-]{36}$/);
    await shot(page, 'import-modal-paused');

    // Closed: the list still leads to it.
    await page.keyboard.press('Escape');
    await expect(dialog(page)).toBeHidden();
    await page.reload();
    const notice = page.getByTestId('crm-import-unfinished');
    await expect(notice).toBeVisible();
    await expect(notice.locator(`a[href="${href}"]`)).toHaveText(`paused-${stamp}.csv`);
    await shot(page, 'import-unfinished-notice');
    await notice.locator(`a[href="${href}"]`).click();
    await expect(page.getByTestId('crm-import-summary')).toHaveAttribute('data-status', 'running');
    await page.getByTestId('crm-import-continue').click();
    await expect(page.getByTestId('crm-import-summary')).toHaveAttribute('data-status', 'done', { timeout: 60_000 });
    await page.goto('/crm/companies');
    await expect(page.locator(`[data-testid="crm-import-unfinished"] a[href="${href}"]`)).toHaveCount(0);
  });
});

test.describe('import page without JavaScript (no-JS fallback)', () => {
  test.use({ javaScriptEnabled: false });

  test('the page action is a link to the import page, which imports', async ({ page }) => {
    await login(page, 'mia');
    await page.goto('/crm/companies');
    await page.getByTestId('crm-import-open').click();
    await expect(page).toHaveURL(/\/crm\/import\?subject=company$/);
    await page.locator('input[type="file"]').setInputFiles({ name: 'firms.csv', mimeType: 'text/csv', buffer: Buffer.from(`name,city\nNoJs ${Date.now()},Bilbao\n`) });
    await page.getByTestId('crm-import-upload').locator('button[type="submit"]').first().click();
    await expect(page.getByTestId('crm-import-rows')).toContainText('NoJs');
    await page.locator('form[name="crm_import_confirm"] button[type="submit"]').first().click();
    await expect(page.getByTestId('crm-import-summary')).toHaveAttribute('data-status', 'done');
  });
});
