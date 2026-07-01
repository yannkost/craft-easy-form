import { test, expect } from '@playwright/test';

test('CP: forms index loads when authenticated', async ({ page }) => {
  await page.goto('/cp/easy-form/forms');
  await expect(page.getByRole('link', { name: /new form/i }).first()).toBeVisible();
});
