import { test, expect } from '@playwright/test';
import { expectNoHorizontalScroll, login, logout } from './helpers';

/**
 * The CRM end to end against the running demo (one serial flow on a fresh stack): mia creates a company with two
 * party roles and a contact, links them (purpose, primary contact), adds a note and imports contacts from a CSV file;
 * ana only reads and filters by role; admin adds a tag and a custom field through their dialogs (0.6.1).
 */
test.describe.configure({ mode: 'serial' });

const company = `Acme ${Date.now()}`;

test.describe('CRM Kit demo', () => {
  test('the home page answers 200 for an anonymous visitor', async ({ page }) => {
    const response = await page.goto('/');
    expect(response?.status()).toBe(200);
    await expect(page.getByTestId('demo-people')).toContainText('Mia Sales');
  });

  test('anonymous visitors are sent to the login page', async ({ request }) => {
    const response = await request.get('/crm/companies', { maxRedirects: 0 });
    expect(response.status()).toBe(302);
    expect(response.headers()['location']).toContain('/login');
  });

  test('mia creates a company, a contact, a link and a note', async ({ page }) => {
    await login(page, 'mia');
    await page.goto('/crm/companies/new');
    await page.locator('input[name="crm_record[name]"]').fill(company);
    await page.locator('input[name="crm_record[city]"]').fill('Madrid');
    await page.locator('input[name="crm_record[roles][]"][value="customer"]').check();
    await page.locator('input[name="crm_record[roles][]"][value="supplier"]').check();
    await page.getByTestId('crm-record-form').locator('button[type="submit"]').first().click();
    await expect(page.getByTestId('crm-record')).toContainText(company);
    await expect(page.getByTestId('crm-roles')).toContainText('Customer');
    await expect(page.getByTestId('crm-roles')).toContainText('Supplier');
    await page.getByTestId('crm-link-form').locator('input[name="crm_link[hostId]"]').fill('P-1');
    await page.getByTestId('crm-link-form').locator('button[type="submit"]').click();
    await expect(page.getByTestId('crm-panel')).toContainText('Website relaunch');
    await page.getByTestId('crm-note-form').locator('textarea').fill('First call: they want a quote.');
    await page.getByTestId('crm-note-form').locator('button[type="submit"]').click();
    await expect(page.getByTestId('crm-notes')).toContainText('First call');
    await expect(page.getByTestId('crm-timeline')).toContainText('Note added');

    await page.goto('/crm/contacts/new');
    await page.locator('input[name="crm_record[first_name]"]').fill('Ada');
    await page.locator('input[name="crm_record[last_name]"]').fill('Lovelace');
    await page.locator('input[name="crm_record[email]"]').fill(`ada.${Date.now()}@demo.test`);
    await page.getByTestId('crm-record-form').locator('button[type="submit"]').first().click();
    await expect(page.getByTestId('crm-record')).toContainText('Lovelace');
    const companies = page.getByTestId('crm-affiliation-form').locator('select');
    const value = await companies.locator('option', { hasText: company }).first().getAttribute('value');
    await companies.selectOption(value ?? '');
    await page.getByTestId('crm-affiliation-form').locator('input[name="crm_affiliation[purposes][]"][value="billing"]').check();
    await page.getByTestId('crm-affiliation-form').locator('input[name="crm_affiliation[primary_contact]"]').check();
    await page.getByTestId('crm-affiliation-form').locator('button[type="submit"]').click();
    await expect(page.getByTestId('crm-affiliations')).toContainText(company);
    await expect(page.getByTestId('crm-purposes')).toContainText('Billing');
    await page.getByTestId('crm-affiliations').getByRole('link', { name: company }).click();
    await expect(page.getByTestId('crm-primary-contact')).toBeVisible();
    await logout(page);
  });

  test('mia imports contacts from a CSV file', async ({ page }) => {
    await login(page, 'mia');
    await page.goto('/crm/import');
    await page.locator('input[type="file"]').setInputFiles({
      name: 'people.csv',
      mimeType: 'text/csv',
      buffer: Buffer.from(`first_name,last_name,email\nGrace,Hopper,grace.${Date.now()}@demo.test\nAlan,Turing,alan.${Date.now()}@demo.test\n`),
    });
    await page.getByTestId('crm-import-upload').locator('button[type="submit"]').first().click();
    await expect(page.getByTestId('crm-import-rows')).toContainText('Grace');
    await page.locator('form[name="crm_import_confirm"] button[type="submit"]').first().click();
    await expect(page.getByTestId('crm-import-summary')).toHaveAttribute('data-status', 'done');
    await logout(page);
  });

  test('ana reads but cannot create (403), and lists fit a phone', async ({ page }) => {
    await login(page, 'ana');
    await page.goto(`/crm/companies?q=${encodeURIComponent(company)}&role=supplier`);
    await expect(page.getByTestId('crm-list-company')).toContainText(company);
    await page.goto(`/crm/companies?q=${encodeURIComponent(company)}&role=distributor`);
    await expect(page.getByTestId('crm-list-company')).not.toContainText(company);
    const response = await page.goto('/crm/companies/new');
    expect(response?.status()).toBe(403);
    await page.setViewportSize({ width: 375, height: 800 });
    await page.goto('/crm/contacts');
    await expectNoHorizontalScroll(page);
    await page.goto('/');
    await logout(page);
  });

  test('admin creates a tag and a custom field in their dialogs', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/crm/admin/tags');
    // 0.8.0: the form is not in the page; the dialog loads it on demand (X-Nowo-Dialog).
    await expect(page.locator('form[name="crm_tag"]')).toHaveCount(0);
    await page.getByTestId('crm-tag-new').click();
    const tagDialog = page.getByTestId('crm-tag-dialog');
    await expect(tagDialog.locator('input[name="crm_tag[name]"]')).toBeVisible();
    const tag = `Key ${Date.now() % 100000}`;
    await tagDialog.locator('input[name="crm_tag[name]"]').fill(tag);
    await tagDialog.getByTestId('crm-tag-form-dialog-form-submit').click();
    await expect(page.getByTestId('crm-tags')).toContainText(tag);
    await expect(tagDialog).toBeHidden();

    await page.goto('/crm/admin/fields');
    await page.getByTestId('crm-field-new-company').click();
    const dialog = page.getByTestId('crm-field-dialog');
    await expect(dialog.locator('input[name="crm_field[key]"]')).toBeVisible();
    // An invalid key answers 422: the form is re-rendered in the dialog with the error.
    await dialog.locator('input[name="crm_field[key]"]').fill('Bad Key');
    await dialog.locator('input[name="crm_field[label]"]').fill('Tier');
    await dialog.getByTestId('crm-field-form-company-dialog-form-submit').click();
    await expect(dialog.locator('[aria-invalid="true"]').first()).toBeVisible();
    await expect(page).toHaveURL(/\/crm\/admin\/fields$/);
    await dialog.locator('input[name="crm_field[key]"]').fill(`tier_${Date.now() % 100000}`);
    await dialog.getByTestId('crm-field-form-company-dialog-form-submit').click();
    await expect(page.getByTestId('crm-fields')).toContainText('Tier');

    // ?new=1, the opener's href, is the page with the form card (no JavaScript needed).
    await page.goto('/crm/admin/fields?for=contact&new=1');
    await expect(page.getByTestId('crm-field-form').locator('form[name="crm_field"]')).toBeVisible();
    await logout(page);
  });
});
