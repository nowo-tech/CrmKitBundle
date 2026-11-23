import { expect, type Page } from '@playwright/test';

/** Signs in through the form login (all three demo users have the password "demo"). */
export async function login(page: Page, user: 'ana' | 'mia' | 'admin'): Promise<void> {
  await page.goto('/login');
  await page.locator('input[name="_username"]').fill(user);
  await page.locator('input[name="_password"]').fill('demo');
  await page.getByTestId('login-form').locator('button[type="submit"]').click();
  await expect(page.getByTestId('demo-user')).toHaveText(user);
}

/** Signs out through the logout route (the shell's Account item points at it; behind the burger on a phone). */
export async function logout(page: Page): Promise<void> {
  await page.goto('/logout');
  await expect(page.getByTestId('demo-nav-login')).toBeAttached();
}

/** No horizontal scroll: the document is not wider than the viewport. */
export async function expectNoHorizontalScroll(page: Page): Promise<void> {
  const overflow = await page.evaluate(() => (document.scrollingElement?.scrollWidth ?? 0) - window.innerWidth);
  expect(overflow, 'horizontal overflow in px').toBeLessThanOrEqual(0);
}
