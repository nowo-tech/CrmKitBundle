import { test, expect, type Page } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { login } from './helpers';
import { frameShot } from './frame';

/**
 * README screenshots (PNG, converted to WebP by scripts/to-webp.mjs). Self-contained: run on a fresh demo stack
 * (`make demo-screenshots` right after `make -C demo up-symfony8`), it creates a few readable companies and one
 * contact, then captures the list and the 360° page of the first company.
 */
const outDir = process.env.SCREENSHOT_DIR ? resolve(process.env.SCREENSHOT_DIR) : resolve(__dirname, '../../../../docs/images/demo');

const companies: Array<{ name: string; city: string; roles: string[] }> = [
  { name: 'Northwind Traders', city: 'Madrid', roles: ['customer', 'supplier'] },
  { name: 'Bilbao Foods', city: 'Bilbao', roles: ['customer'] },
  { name: 'Contoso Logistics', city: 'Valencia', roles: ['partner'] },
  { name: 'Fabrikam Robotics', city: 'Barcelona', roles: ['supplier'] },
];

async function createCompany(page: Page, company: { name: string; city: string; roles: string[] }): Promise<void> {
  await page.goto('/crm/companies/new');
  await page.locator('input[name="crm_record[name]"]').fill(company.name);
  await page.locator('input[name="crm_record[city]"]').fill(company.city);
  for (const role of company.roles) {
    await page.locator(`input[name="crm_record[roles][]"][value="${role}"]`).check();
  }
  await page.getByTestId('crm-record-form').locator('button[type="submit"]').first().click();
  await expect(page.getByTestId('crm-record')).toContainText(company.name);
}

test.beforeAll(() => {
  mkdirSync(outDir, { recursive: true });
});

test.describe('CRM Kit screenshots', () => {
  test.use({ viewport: { width: 1280, height: 900 } });

  test('crm-companies and crm-company-360', async ({ page }) => {
    await login(page, 'mia');
    for (const company of [...companies].reverse()) {
      await createCompany(page, company);
    }
    // The 360° page of the last one created (Northwind): a host link, a note and an affiliated primary contact.
    const record = page.url();
    await page.getByTestId('crm-link-form').locator('input[name="crm_link[hostId]"]').fill('P-1');
    await page.getByTestId('crm-link-form').locator('button[type="submit"]').click();
    await expect(page.getByTestId('crm-panel')).toContainText('Website relaunch');
    await page.getByTestId('crm-note-form').locator('textarea').fill('First call: they want a quote for the spring catalogue.');
    await page.getByTestId('crm-note-form').locator('button[type="submit"]').click();
    await expect(page.getByTestId('crm-notes')).toContainText('First call');

    await page.goto('/crm/contacts/new');
    await page.locator('input[name="crm_record[first_name]"]').fill('Ada');
    await page.locator('input[name="crm_record[last_name]"]').fill('Lovelace');
    await page.locator('input[name="crm_record[email]"]').fill('ada@northwind.demo.test');
    await page.getByTestId('crm-record-form').locator('button[type="submit"]').first().click();
    await expect(page.getByTestId('crm-record')).toContainText('Lovelace');
    const select = page.getByTestId('crm-affiliation-form').locator('select');
    const value = await select.locator('option', { hasText: 'Northwind Traders' }).first().getAttribute('value');
    await select.selectOption(value ?? '');
    await page.getByTestId('crm-affiliation-form').locator('input[name="crm_affiliation[purposes][]"][value="billing"]').check();
    await page.getByTestId('crm-affiliation-form').locator('input[name="crm_affiliation[primary_contact]"]').check();
    await page.getByTestId('crm-affiliation-form').locator('button[type="submit"]').click();
    await expect(page.getByTestId('crm-affiliations')).toContainText('Northwind Traders');

    await page.goto('/crm/companies');
    await expect(page.getByTestId('crm-list-company')).toBeVisible();
    await frameShot(page, resolve(outDir, 'crm-companies.png'));

    await page.goto(record);
    await expect(page.getByTestId('crm-record')).toBeVisible();
    await frameShot(page, resolve(outDir, 'crm-company-360.png'));
  });
});
