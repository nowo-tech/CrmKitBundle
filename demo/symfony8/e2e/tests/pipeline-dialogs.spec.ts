import { test, expect } from '@playwright/test';
import { login, logout } from './helpers';

/**
 * 0.9.0 (`ui.pipeline_forms: dialog`): the funnel pages load every form on demand into one remote form dialog — a new
 * funnel, a stage edit, a deal's move to a stage, its next action and its trash confirm. An invalid submission stays in
 * the dialog (422), a saved one goes to the page the server names (204 + X-Nowo-Redirect).
 */
test.describe.configure({ mode: 'serial' });

test.describe('CRM Kit 0.9.0 pipeline dialogs', () => {
  test('mia runs a funnel and a deal through the dialogs', async ({ page }) => {
    await login(page, 'mia');
    const dialog = page.getByTestId('crm-pipeline-dialog');

    // New funnel: no form under the list, the header link opens it.
    await page.goto('/crm/admin/funnels');
    await expect(page.locator('form[name="crm_funnel"]')).toHaveCount(0);
    await expect(dialog).toBeHidden();
    await page.getByTestId('crm-funnel-new').click();
    await expect(dialog).toBeVisible();
    await dialog.getByTestId('crm-funnel-new-dialog-form-submit').click();
    await expect(dialog).toBeVisible();
    await expect(page).toHaveURL(/\/crm\/admin\/funnels$/);
    const funnel = `Sales ${Date.now() % 100000}`;
    await dialog.locator('input[name="crm_funnel[name]"]').fill(funnel);
    await dialog.getByTestId('crm-funnel-new-dialog-form-submit').click();
    await expect(page).toHaveURL(/\/crm\/admin\/funnels\/[0-9a-f-]{36}$/);
    const funnelUrl = page.url();

    // A stage: no form per stage, one link each.
    await expect(page.locator('form[name="crm_stage"]')).toHaveCount(0);
    await page.getByTestId('crm-stage-edit-open').first().click();
    await expect(dialog).toBeVisible();
    await dialog.locator('input[name="crm_stage[name]"]').fill('Lead in');
    await dialog.getByTestId('crm-stage-edit-dialog-form-submit').click();
    await expect(page).toHaveURL(funnelUrl);
    await expect(page.getByTestId('crm-stages')).toContainText('Lead in');

    // A company, then a deal of that funnel for it.
    await page.goto('/crm/companies/new');
    await page.locator('input[name="crm_record[name]"]').fill(`Deal Co ${Date.now() % 100000}`);
    await page.getByTestId('crm-record-form').locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/crm\/companies\/[0-9a-f-]{36}$/);
    const company = page.url().split('/').pop() ?? '';
    await page.goto(`/crm/deals/new?company=${company}`);
    await page.locator('input[name="crm_deal[title]"]').fill('Engines');
    const stage = await page.locator('select[name="crm_deal[stage]"] option', { hasText: `${funnel} › Lead in` }).getAttribute('value');
    await page.locator('select[name="crm_deal[stage]"]').selectOption(stage ?? '');
    await page.getByTestId('crm-deal-form').locator('button[type="submit"]').click();
    await expect(page).toHaveURL(/\/crm\/deals\/[0-9a-f-]{36}$/);
    const dealUrl = page.url();

    // Move to a stage and a next action, in the dialog.
    await expect(page.locator('form[name="crm_stage_move"], form[name="crm_next_action"]')).toHaveCount(0);
    await page.getByTestId('crm-deal-move-open').click();
    await expect(dialog.locator('select[name="crm_stage_move[stage]"]')).toBeVisible();
    const qualified = await dialog.locator('select[name="crm_stage_move[stage]"] option', { hasText: 'Qualified' }).getAttribute('value');
    await dialog.locator('select[name="crm_stage_move[stage]"]').selectOption(qualified ?? '');
    await dialog.getByTestId('crm-deal-move-dialog-form-submit').click();
    await expect(page).toHaveURL(dealUrl);
    await expect(page.getByTestId('crm-stage-change')).toHaveCount(2);

    await page.getByTestId('crm-next-action-open').click();
    await dialog.locator('input[name="crm_next_action[title]"]').fill('Call back');
    await dialog.getByTestId('crm-next-action-dialog-form-submit').click();
    await expect(page).toHaveURL(dealUrl);
    await expect(page.getByTestId('crm-next-actions')).toContainText('Call back');

    // The trash confirm: Cancel first, then confirm.
    await page.getByTestId('crm-deal-trash').click();
    await expect(dialog.getByTestId('crm-deal-trash-dialog-form-intro')).toBeVisible();
    await dialog.getByTestId('crm-deal-trash-dialog-form-cancel').click();
    await expect(dialog).toBeHidden();
    await expect(page.getByTestId('crm-deal-trash')).toBeFocused();
    await page.getByTestId('crm-deal-trash').click();
    await dialog.getByTestId('crm-deal-trash-dialog-form-submit').click();
    await expect(page).toHaveURL(/\/crm\/deals$/);
    await logout(page);
  });

  test('without JavaScript the links open the form pages', async ({ browser }) => {
    const context = await browser.newContext({ javaScriptEnabled: false });
    const page = await context.newPage();
    await login(page, 'mia');
    await page.goto('/crm/admin/funnels');
    await page.getByTestId('crm-funnel-new').click();
    await expect(page).toHaveURL(/\/crm\/admin\/funnels\/new$/);
    await expect(page.getByTestId('crm-funnel-new').locator('form[name="crm_funnel"]')).toBeVisible();
    await context.close();
  });
});
