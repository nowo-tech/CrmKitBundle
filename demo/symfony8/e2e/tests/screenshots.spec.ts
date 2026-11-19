import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { login, waitForWebProfiler } from './helpers';

/** README screenshots (PNG, converted to WebP by scripts/to-webp.mjs). Run after demo.spec.ts (it creates the data). */
const outDir = process.env.SCREENSHOT_DIR ? resolve(process.env.SCREENSHOT_DIR) : resolve(__dirname, '../../../../docs/images/demo');

test.beforeAll(() => {
  mkdirSync(outDir, { recursive: true });
});

test.describe('CRM Kit screenshots', () => {
  test.use({ viewport: { width: 1280, height: 1000 } });

  test('crm-companies and crm-company-360', async ({ page }) => {
    await login(page, 'mia');
    await page.goto('/crm/companies');
    await expect(page.getByTestId('crm-list-company')).toBeVisible();
    await waitForWebProfiler(page);
    await page.screenshot({ path: resolve(outDir, 'crm-companies.png'), fullPage: true });
    await page.locator('[data-testid="crm-list-company"] a[href*="/crm/companies/"]').first().click();
    await expect(page.getByTestId('crm-record')).toBeVisible();
    await waitForWebProfiler(page);
    await page.screenshot({ path: resolve(outDir, 'crm-company-360.png'), fullPage: true });
  });
});
