import { test, expect } from '@playwright/test';

// Render-time options on easyForm()/easyFormLayout(), driven through the dev
// harness (templates/dev/form.twig forwards query params to the options array).

test('includeStyles toggles the bundled stylesheet', async ({ page }) => {
  // Default: the bundled form-render.css is registered.
  await page.goto('/dev/form?f=e2eContact');
  await expect(page.locator('link[href*="form-render"]')).toHaveCount(1);

  // includeStyles:false (?styles=0): no bundled CSS, so a site can style its own.
  await page.goto('/dev/form?f=e2eContact&styles=0');
  await expect(page.locator('link[href*="form-render"]')).toHaveCount(0);
  // The form itself still renders.
  await expect(page.locator('form.easy-form')).toBeVisible();
});

test('submitButtonText / submitButtonClass override the submit control', async ({ page }) => {
  await page.goto('/dev/form?f=e2eContact&submitText=Go%20Now&submitClass=ef-custom-submit');
  const submit = page.locator('form.easy-form button[type=submit]');
  await expect(submit).toHaveText('Go Now');
  await expect(submit).toHaveClass(/ef-custom-submit/);
});

test('disableFrontendValidation marks the form so the client skips validation', async ({ page }) => {
  await page.goto('/dev/form?f=e2eContact&novalidate=1');
  await expect(page.locator('form.easy-form')).toHaveAttribute('data-disable-frontend-validation', 'true');
});

test('easyFormLayout() exposes the page → row → field structure', async ({ page }) => {
  await page.goto('/dev/form?f=e2eContact&layout=1');
  const dump = page.locator('#ef-layout-dump');
  await expect(dump).toBeVisible();
  // e2eContact's known fields appear in the structural dump.
  await expect(dump.locator('.ef-dump-field')).not.toHaveCount(0);
  await expect(dump.locator('.ef-dump-field', { hasText: 'email' })).toHaveCount(1);
});
