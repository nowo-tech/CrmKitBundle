import { test, expect, type Locator, type Page } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { expectNoHorizontalScroll, login } from './helpers';

/**
 * 0.10.3 — the HubSpot steps of the import modal against the demo's fake HubSpot account (App\Crm\DemoHubSpot,
 * credential "demo-hubspot-key"), at desktop and phone sizes, and a step URL opened directly
 * (`import.dialog.direct_get: redirect`, UiKit remote_dialog.direct_get): it lands on the companies list with the
 * modal open on that step and a clean address. IMPORT_SHOTS_DIR (optional) saves screenshots.
 */
test.describe.configure({ mode: 'serial', timeout: 120_000 });

const shotsDir = process.env.IMPORT_SHOTS_DIR;

function dialog(page: Page): Locator {
  return page.getByTestId('crm-import-dialog');
}

async function settled(page: Page): Promise<void> {
  // No hover state and the dialog's entrance transition finished (axe reads colours mid-fade otherwise). Audit ZBJ-I1:
  // wait two frames (a transition starts on the frame after the dialog opens), then until no finite animation of the
  // page (the dialog, its ::backdrop) is still running; endless ones (a spinner) never settle and are left out.
  await page.mouse.move(1, 1);
  await page.evaluate(() => new Promise<void>((done) => requestAnimationFrame(() => requestAnimationFrame(() => done()))));
  await expect
    .poll(() => page.evaluate(() => document.getAnimations().filter((animation) => animation.playState === 'running' && animation.effect?.getTiming().iterations !== Infinity).length), { timeout: 5_000 })
    .toBe(0);
}

async function shot(page: Page, name: string): Promise<void> {
  if (!shotsDir) {
    return;
  }
  mkdirSync(shotsDir, { recursive: true });
  await page.locator('.sf-toolbar').evaluateAll((elements) => elements.forEach((element) => element.remove()));
  await settled(page);
  await page.screenshot({ path: resolve(shotsDir, `${name}.png`) });
}

/** The modal fits the viewport with its header and footer in view; nothing scrolls sideways. */
async function fits(page: Page): Promise<void> {
  await settled(page);
  await expect(dialog(page).locator('.nowo-ui-dialog__header')).toBeInViewport();
  await expect(dialog(page).locator('.nowo-ui-dialog__footer')).toBeInViewport();
  await expect(dialog(page).locator('.nowo-ui-dialog__body .nowo-ui-btn--primary')).toHaveCount(0);
  await expectNoHorizontalScroll(page);
}

for (const size of [
  { name: 'desktop', viewport: { width: 1280, height: 800 } },
  { name: 'phone', viewport: { width: 390, height: 844 } },
]) {
  test.describe(`HubSpot import modal (${size.name}, 0.10.3)`, () => {
    test.use({ viewport: size.viewport });

    test('source, connect, start, mapping and result run inside the modal', async ({ page }) => {
      await login(page, 'admin');
      await page.goto('/crm/companies');
      await page.getByTestId('crm-import-open').first().click();
      await expect(dialog(page)).toBeVisible();
      await expect(dialog(page).getByTestId('crm-import-file')).toBeVisible();
      await shot(page, `import-csv-file-${size.name}`);
      await dialog(page).getByTestId('crm-import-source-hubspot').locator('a').click();
      await expect(dialog(page).getByTestId('crm-hubspot-source')).toBeVisible();

      // Not connected yet (first run): connect with the demo's fake credential.
      if (await dialog(page).locator('a[data-testid="crm-hubspot-connect"]').isVisible()) {
        await fits(page);
        await shot(page, `import-hubspot-source-${size.name}`);
        await dialog(page).locator('a[data-testid="crm-hubspot-connect"]').click();
        await expect(dialog(page).locator('div[data-testid="crm-hubspot-connect"]')).toBeVisible();
        await fits(page);
        await shot(page, `import-hubspot-connect-${size.name}`);
        await dialog(page).locator('input[name$="[token]"]').fill('demo-hubspot-key');
        await dialog(page).getByTestId('crm-import-dialog-submit').click();
      }
      await expect(dialog(page).getByTestId('crm-hubspot-connection')).toBeVisible();
      await fits(page);
      await shot(page, `import-hubspot-card-${size.name}`);

      await dialog(page).locator('a[data-testid="crm-hubspot-start"]').click();
      await expect(dialog(page).locator('div[data-testid="crm-hubspot-start"]')).toBeVisible();
      await fits(page);
      await shot(page, `import-hubspot-start-${size.name}`);
      await dialog(page).getByTestId('crm-import-dialog-submit').click();

      // A second run in the same demo database is refused in place while the first import still runs (409).
      const mapping = dialog(page).getByTestId('crm-hubspot-mapping');
      await expect(mapping.or(dialog(page).getByTestId('crm-import-result')).or(dialog(page).getByTestId('crm-hubspot-alert'))).toBeVisible();
      if (await mapping.isVisible()) {
        await expect(mapping.locator('table')).toHaveCount(2);
        await fits(page);
        await shot(page, `import-hubspot-mapping-${size.name}`);
        await dialog(page).getByTestId('crm-import-dialog-submit').click();
      } else {
        test.skip(true, 'the demo database already runs a HubSpot import');
      }

      await expect(dialog(page).getByTestId('crm-import-result')).toBeVisible();
      await fits(page);
      await shot(page, `import-hubspot-result-${size.name}`);
    });

    test('a step URL opened directly lands on its list with the modal open on it', async ({ page }) => {
      await login(page, 'admin');
      // Address bar: no referrer, so fetch metadata says "none" and the server answers the redirect.
      await page.goto('/crm/import/hubspot/connect');
      await expect(page).toHaveURL(/\/crm\/companies$/);
      await expect(dialog(page)).toBeVisible();
      await expect(dialog(page).locator('div[data-testid="crm-hubspot-connect"]')).toBeVisible();
      await fits(page);
      await shot(page, `import-hubspot-direct-get-${size.name}`);
      await page.keyboard.press('Escape');
      await expect(dialog(page)).toBeHidden();

      // The CSV file step of the contacts list.
      await page.goto('/crm/import?subject=contact');
      await expect(page).toHaveURL(/\/crm\/contacts$/);
      await expect(dialog(page).getByTestId('crm-import-file')).toBeVisible();
      await expect(dialog(page).locator('select[name="crm_import[subject]"]')).toHaveValue('contact');
    });
  });
}
