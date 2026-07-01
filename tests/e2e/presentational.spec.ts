import { test, expect } from '@playwright/test';
import { apiSubmit } from './submit';
import { formId, dataValueByEmail } from './db';

// e2ePresentational has a heading (h2, French override), a warning callout with
// a custom hex accent, a divider, then real name/email fields.

test('heading, divider and callout render as presentational elements', async ({ page }) => {
  await page.goto('/dev/form?f=e2ePresentational');

  await expect(page.locator('h2.easy-form-heading')).toHaveText('Contact details');
  await expect(page.locator('hr.easy-form-divider')).toBeVisible();

  const callout = page.locator('.easy-form-callout.easy-form-callout-warning');
  await expect(callout).toContainText('We reply within one business day');
  await expect(callout).toHaveAttribute('style', /#ff5722/);

  // Presentational fields have no inputs.
  await expect(page.locator('[name="fields[headingContact]"]')).toHaveCount(0);
  await expect(page.locator('[name="fields[calloutNotice]"]')).toHaveCount(0);
});

test('presentational text is translated per site', async ({ page }) => {
  await page.goto('/fr/dev/form?f=e2ePresentational');
  await expect(page.locator('h2.easy-form-heading')).toHaveText('Coordonnées');
});

test('a submission ignores presentational fields (not required, not stored)', async ({ request }) => {
  const email = `pres-${Date.now()}-${Math.floor(Math.random() * 1e6)}@example.test`;
  const res = await apiSubmit(request, {
    formId: formId('e2ePresentational'),
    fields: { name: 'P', email },
  });
  expect(res.success).toBe(true);
  // Their handles never land in stored values.
  expect(dataValueByEmail('e2ePresentational', '$.values.headingContact', email)).toBe('NULL');
  expect(dataValueByEmail('e2ePresentational', '$.values.dividerOne', email)).toBe('NULL');
});
