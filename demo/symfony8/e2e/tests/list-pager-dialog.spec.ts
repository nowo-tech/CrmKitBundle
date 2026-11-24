import { test, expect } from '@playwright/test';
import { login, logout } from './helpers';

/**
 * 0.8.0 (Beacon requests): "New company" in a remote form dialog loaded on demand (an invalid submission stays in the
 * dialog, a valid one goes to the new record), "Rows per page" in the filter card with the pager count under the
 * list, and the name search + pager on Tags.
 */
test.describe.configure({ mode: 'serial' });

test.describe('CRM Kit 0.8.0 lists and create dialog', () => {
  test('mia creates a company in the dialog', async ({ page }) => {
    await login(page, 'mia');
    await page.goto('/crm/companies');
    const opener = page.getByTestId('crm-record-new');
    await expect(opener).toHaveAttribute('href', /\/crm\/companies\/new$/);
    const dialog = page.getByTestId('crm-record-dialog');
    await expect(dialog).toBeHidden();
    await expect(page.locator('form[name="crm_record"]')).toHaveCount(0);
    await opener.click();
    await expect(dialog).toBeVisible();
    const name = dialog.locator('input[name="crm_record[name]"]');
    await expect(name).toBeVisible();
    await expect(page).toHaveURL(/\/crm\/companies$/);

    // Empty name: 422, re-rendered in the dialog, which stays open.
    await name.fill('');
    await dialog.getByTestId('crm-record-dialog-form-submit').click();
    await expect(dialog).toBeVisible();
    await expect(page).toHaveURL(/\/crm\/companies$/);

    const company = `Dialog Co ${Date.now() % 100000}`;
    await dialog.locator('input[name="crm_record[name]"]').fill(company);
    await dialog.getByTestId('crm-record-dialog-form-submit').click();
    await expect(page).toHaveURL(/\/crm\/companies\/[0-9a-f-]{36}$/);
    await expect(page.getByTestId('crm-record')).toContainText(company);

    // Cancel closes it and gives the focus back to the opener.
    await page.goto('/crm/companies');
    await page.getByTestId('crm-record-new').click();
    await expect(dialog.locator('input[name="crm_record[name]"]')).toBeVisible();
    await dialog.getByTestId('crm-record-dialog-form-cancel').click();
    await expect(dialog).toBeHidden();
    await expect(page.getByTestId('crm-record-new')).toBeFocused();
    await logout(page);
  });

  test('rows per page and the pager count', async ({ page }) => {
    await login(page, 'mia');
    await page.goto('/crm/companies?per_page=10');
    const select = page.locator('[data-nowo-ui-region="filters"] select[name="per_page"]');
    await expect(select).toHaveValue('10');
    // 0.10.2: compact ("10 per page"), named "Results per page" by a visually hidden label.
    await expect(page.getByLabel('Results per page')).toBeVisible();
    await expect(select.locator('option:checked')).toHaveText('10 per page');
    await select.selectOption('25');
    await expect(page).toHaveURL(/per_page=10/);
    await page.locator('[data-nowo-ui-region="filters"] button[type="submit"]').first().click();
    await expect(page).toHaveURL(/per_page=25/);
    await expect(page.getByTestId('crm-list-company').locator('[data-nowo-ui-region="pagination"]')).toContainText(/\d/);

    await page.goto('/crm/companies?per_page=7');
    await expect(page.locator('select[name="per_page"]')).toHaveValue('25');
    await logout(page);
  });

  test('admin searches tags by name with the pager', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/crm/admin/tags');
    await page.getByTestId('crm-tag-new').click();
    const tag = `Pager ${Date.now() % 100000}`;
    const tagDialog = page.getByTestId('crm-tag-dialog');
    await tagDialog.locator('input[name="crm_tag[name]"]').fill(tag);
    await tagDialog.getByTestId('crm-tag-form-dialog-form-submit').click();
    await expect(page.getByTestId('crm-tags')).toContainText(tag);

    const filters = page.locator('[data-nowo-ui-region="filters"]');
    await filters.locator('input[name="q"]').fill(tag.toLowerCase());
    await filters.locator('button[type="submit"]').first().click();
    await expect(page).toHaveURL(/q=pager/i);
    await expect(page.getByTestId('crm-tags').locator('tbody tr')).toHaveCount(1);
    await expect(page.getByTestId('crm-tags').locator('[data-nowo-ui-region="pagination"]')).toContainText('1');

    await page.goto('/crm/admin/fields?q=zzz-nothing');
    await expect(page.getByTestId('crm-fields')).toContainText('Nothing matches the search.');
    await logout(page);
  });
});
