import { test, expect } from '@playwright/test';
import { apiSubmit } from './submit';
import { formId, dataValueByEmail } from './db';

const uniqueEmail = (tag: string) => `${tag}-${Date.now()}-${Math.floor(Math.random() * 1e6)}@example.test`;

// e2eRowCond: the "company" field lives in a row shown only when type = business.

test('a hidden row keeps its value in the DOM but excludes it from the payload', async ({ page }) => {
  await page.goto('/dev/form?f=e2eRowCond');

  const company = page.locator('input[name="fields[company]"]');
  await expect(company).toBeHidden(); // row hidden initially

  const email = uniqueEmail('rowcond');
  await page.fill('input[name="fields[name]"]', 'Row Tester');
  await page.fill('input[name="fields[email]"]', email);

  // Show the row, type into the now-visible field.
  await page.fill('input[name="fields[type]"]', 'business');
  await expect(company).toBeVisible();
  await company.fill('AcmeData');

  // Hide it again — the DOM value is preserved, but it must not be submitted.
  await page.fill('input[name="fields[type]"]', 'personal');
  await expect(company).toBeHidden();
  await expect(company).toHaveValue('AcmeData'); // value kept in the browser

  await page.locator('form.easy-form button[type=submit]').click();
  await expect(page.locator('.easy-form-message.success')).toBeVisible();

  // The hidden row's value was filtered out (and the server discards it too).
  expect(dataValueByEmail('e2eRowCond', '$.values.company', email)).toBe('NULL');
});

test('a required field in a hidden row does not block submission', async ({ page }) => {
  await page.goto('/dev/form?f=e2eRowCond');
  await page.fill('input[name="fields[name]"]', 'Personal');
  await page.fill('input[name="fields[email]"]', uniqueEmail('rowcond-personal'));
  // type stays empty → company row hidden → its required rule must not fire.
  await page.locator('form.easy-form button[type=submit]').click();
  await expect(page.locator('.easy-form-message.success')).toBeVisible();
});

test('a required field in a visible row is enforced', async ({ page }) => {
  await page.goto('/dev/form?f=e2eRowCond');
  await page.fill('input[name="fields[name]"]', 'Biz');
  await page.fill('input[name="fields[email]"]', uniqueEmail('rowcond-biz'));
  await page.fill('input[name="fields[type]"]', 'business'); // company row visible + required
  await page.locator('form.easy-form button[type=submit]').click();
  await expect(page.locator('.field-error').first()).toBeVisible();
  await expect(page.locator('.easy-form-message.success')).toHaveCount(0);
});

test('server discards a hidden-row value posted directly (anti-tamper)', async ({ request }) => {
  const email = uniqueEmail('rowcond-tamper');
  const res = await apiSubmit(request, {
    formId: formId('e2eRowCond'),
    fields: { name: 'Tamper', email, type: 'personal', company: 'HACKED' },
  });
  expect(res.success).toBe(true);
  expect(dataValueByEmail('e2eRowCond', '$.values.company', email)).toBe('NULL');
});
