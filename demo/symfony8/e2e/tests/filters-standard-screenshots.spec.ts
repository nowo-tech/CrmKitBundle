import { test, expect, type Page } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { login } from './helpers';

/**
 * 0.10.2 filter standard screenshots (Beacon request 2026-11-24): every list (Companies, Contacts, Deals, Tags, Custom
 * fields, Funnels) at desktop (1280 px) and phone (390 px) width, the phone filter sheet open, "More filters" open
 * with a folded filter set, and a filter that matches nothing.
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
  // Empty states first (a fresh demo): one sentence and the create action(s) in the empty state.
  await page.setViewportSize({ width: 1280, height: 800 });
  for (const [type, path] of [['deals', '/crm/deals'], ['tags', '/crm/admin/tags'], ['fields', '/crm/admin/fields'], ['funnels', '/crm/admin/funnels']] as const) {
    await page.goto(path);
    if (await page.locator('[data-nowo-ui-region="empty"]').isVisible()) {
      await shot(page, `${type}-empty-desktop`);
    }
  }
  // Then rows: a deal funnel, two deals, two tags and a custom field (no-JS form pages, stable in a screenshot run).
  await page.goto('/crm/admin/funnels');
  if ((await page.locator('[data-testid="crm-funnels"] tbody tr').count()) === 0) {
    await page.goto('/crm/admin/funnels/new');
    await page.locator('input[name="crm_funnel[name]"]').fill('Sales');
    await page.locator('form[name="crm_funnel"] button[type="submit"]').first().click();
    await expect(page).toHaveURL(/\/crm\/admin\/funnels\/[0-9a-f-]{36}$/);
    await page.goto('/crm/companies?q=Northwind');
    const company = (await page.locator('[data-testid="crm-list-company"] tbody tr a[href*="/crm/companies/"]').first().getAttribute('href'))?.split('/').pop() ?? '';
    for (const title of ['Fleet renewal', 'Warehouse robots']) {
      await page.goto(`/crm/deals/new?company=${company}`);
      await page.locator('input[name="crm_deal[title]"]').fill(title);
      const stage = await page.locator('select[name="crm_deal[stage]"] option:not([value=""])').first().getAttribute('value');
      await page.locator('select[name="crm_deal[stage]"]').selectOption(stage ?? '');
      await page.getByTestId('crm-deal-form').locator('button[type="submit"]').click();
      await expect(page).toHaveURL(/\/crm\/deals\/[0-9a-f-]{36}$/);
    }
  }
  await page.goto('/crm/admin/tags');
  if ((await page.locator('[data-testid="crm-tags"] tbody tr').count()) === 0) {
    for (const tag of ['Key account', 'Prospect']) {
      await page.goto('/crm/admin/tags?new=1');
      await page.locator('form[name="crm_tag"] input[name="crm_tag[name]"]').first().fill(tag);
      await page.locator('form[name="crm_tag"] button[type="submit"]').first().click();
      await expect(page.getByTestId('crm-tags')).toContainText(tag);
    }
  }

  // 0.10.2 (Beacon scope note): every list of the kit — Companies, Contacts, Deals, Tags, Custom fields, Funnels.
  const lists = [
    ['companies', '/crm/companies'],
    ['contacts', '/crm/contacts'],
    ['deals', '/crm/deals'],
    ['tags', '/crm/admin/tags'],
    ['fields', '/crm/admin/fields'],
    ['funnels', '/crm/admin/funnels'],
  ] as const;
  for (const [type, path] of lists) {
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
