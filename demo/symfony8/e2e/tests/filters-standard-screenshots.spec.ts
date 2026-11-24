import { test, expect, type Page } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { login } from './helpers';

/**
 * 0.10.2 filter standard screenshots (Beacon request 2026-11-24): the Companies, Contacts and Deals lists at desktop
 * (1280 px) and phone (390 px) width, the phone filter sheet open, "More filters" open with a folded filter set, and
 * a filter that matches nothing. On a fresh demo Contacts and Deals show their empty state (create + Import).
 * SHOT_PREFIX names the set (`before` on 0.10.1, `after` on 0.10.2); PNG, converted to WebP by scripts/to-webp.mjs
 * (REQ-DEMO-013).
 */
const outDir = process.env.SCREENSHOT_DIR ? resolve(process.env.SCREENSHOT_DIR) : resolve(__dirname, '../../../../docs/images/demo');
const prefix = process.env.SHOT_PREFIX ?? 'after';

async function shot(page: Page, name: string): Promise<void> {
  await page.locator('.sf-toolbar').evaluateAll((els) => els.forEach((el) => el.remove()));
  await page.waitForTimeout(250);
  await page.screenshot({ path: resolve(outDir, `crm-filters-${prefix}-${name}.png`), fullPage: false });
}

test.beforeAll(() => {
  mkdirSync(outDir, { recursive: true });
});

test.use({ reducedMotion: 'reduce' });

test('filter bars, sheet, More filters and empty states (desktop and phone)', async ({ page }) => {
  await login(page, 'admin');
  for (const name of ['Northwind Traders', 'Bilbao Foods', 'Contoso Logistics']) {
    await page.goto('/crm/companies?q=' + encodeURIComponent(name));
    if ((await page.locator('[data-testid="crm-list-company"] tbody tr').count()) > 0) {
      continue;
    }
    await page.goto('/crm/companies/new');
    await page.locator('input[name="crm_record[name]"]').fill(name);
    await page.getByTestId('crm-record-form').locator('button[type="submit"]').first().click();
    await expect(page.getByTestId('crm-record')).toContainText(name);
  }
  for (const [type, path] of [['companies', '/crm/companies'], ['contacts', '/crm/contacts'], ['deals', '/crm/deals']] as const) {
    await page.setViewportSize({ width: 1280, height: 800 });
    await page.goto(path);
    await shot(page, `${type}-desktop`);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(path);
    await shot(page, `${type}-phone`);
    const open = page.locator('[data-nowo-ui-filter-sheet-open]').first();
    if (await open.isVisible()) {
      await open.click();
      await shot(page, `${type}-phone-sheet`);
      await page.keyboard.press('Escape');
    }
  }
  // "More filters" opens by itself while one of its filters is set, with the count in its summary.
  await page.setViewportSize({ width: 1280, height: 800 });
  await page.goto('/crm/companies?created_from=2000-01-01');
  await expect(page.locator('details.nowo-ui-filters-more')).toHaveAttribute('open', '');
  await shot(page, 'companies-more-open-desktop');
  // A filter that matches nothing: "Nothing matches these filters" and Clear filters.
  await page.goto('/crm/companies?q=zzzz-no-match');
  await shot(page, 'companies-no-match-desktop');
});
