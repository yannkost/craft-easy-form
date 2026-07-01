import { test as setup, expect } from '@playwright/test';

const authFile = 'tests/e2e/.auth/admin.json';

// Logs into the Craft control panel once and saves the session for CP specs.
setup('authenticate', async ({ page }) => {
  await page.goto('/cp/login');
  // Craft renders a second (hidden) login form, so target the visible fields.
  await page.locator('input[name="username"]:visible').first().fill(process.env.EASY_FORM_CP_USER || 'admin');
  await page.locator('input[name="password"]:visible').first().fill(process.env.EASY_FORM_CP_PASS || 'N1MK7Sv.');
  await page.getByRole('button', { name: 'Sign in' }).first().click();

  // The global sidebar only renders for an authenticated CP session.
  await expect(page.locator('#global-sidebar')).toBeVisible({ timeout: 15_000 });

  await page.context().storageState({ path: authFile });
});
