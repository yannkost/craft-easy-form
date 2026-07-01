import { test, expect, type Locator, type Page } from '@playwright/test';
import { newForm, addRow, addField, setBasics, fieldTab, closeField, save, deleteForm } from './builder';

/**
 * Coverage for the field-settings additions: the Date & time / Time field types,
 * the URL "require scheme" toggle (+ hint), number decimal places, and the
 * relaxed phone check. Builds a single-page form, then drives the rendered form.
 */

const form = (page: Page) => page.locator('form.easy-form');

// The dialog is already open from setBasics, so just switch tab and fill.
async function fillNumberDecimals(field: Locator, decimals: string) {
  await fieldTab(field, 'validation');
  await field.locator('input[name$="[decimals]"]').fill(decimals);
}

test('CP: new field settings render and validate end to end', async ({ page }) => {
  test.setTimeout(120_000);
  const handle = 'e2eNewFs' + Date.now().toString().slice(-6);
  await newForm(page, 'E2E New Field Settings', handle);

  const row = await addRow(page, 0);

  const when = await addField(row, 'datetime');
  await setBasics(when, 'Appointment', 'appointment');
  await closeField(when);

  const at = await addField(row, 'time');
  await setBasics(at, 'Start time', 'startTime');
  await closeField(at);

  // URL with the scheme requirement turned on.
  const site = await addField(row, 'url');
  await setBasics(site, 'Website', 'website');
  await fieldTab(site, 'validation');
  await site.locator('.validation-url-fields .lightswitch').click();
  await closeField(site);

  // Number limited to 2 decimal places.
  const price = await addField(row, 'number');
  await setBasics(price, 'Price', 'price');
  await fillNumberDecimals(price, '2');
  await closeField(price);

  const phone = await addField(row, 'tel');
  await setBasics(phone, 'Phone', 'phone');
  await closeField(phone);

  await save(page);

  // ---- Front end: rendered attributes -------------------------------------
  await page.goto(`/dev/form?f=${handle}`);
  await expect(form(page)).toBeVisible();

  await expect(page.locator('input[name="fields[appointment]"]')).toHaveAttribute('type', 'datetime-local');
  await expect(page.locator('input[name="fields[startTime]"]')).toHaveAttribute('type', 'time');

  const url = page.locator('input[name="fields[website]"]');
  await expect(url).toHaveAttribute('data-require-scheme', 'true');
  await expect(page.locator('.easy-form-field[data-field-handle="website"] .easy-form-field-hint')).toBeVisible();

  await expect(page.locator('input[name="fields[price]"]')).toHaveAttribute('data-decimals', '2');

  // ---- Client-side validation of the new rules ----------------------------
  // URL without a scheme is rejected when the toggle is on.
  await url.fill('example.com');
  await form(page).locator('button[type=submit]').click();
  await expect(page.locator('.easy-form-field[data-field-handle="website"] .field-error'))
    .toContainText(/http/i);
  await url.fill('https://example.com');

  // Too many decimals is rejected; two is fine.
  const priceInput = page.locator('input[name="fields[price]"]');
  await priceInput.fill('9.999');
  await form(page).locator('button[type=submit]').click();
  await expect(page.locator('.easy-form-field[data-field-handle="price"] .field-error'))
    .toContainText(/decimal/i);
  await priceInput.fill('9.99');

  // A grouped international number passes the relaxed phone check.
  await page.locator('input[name="fields[phone]"]').fill('+41 44 668 18 00');
  await form(page).locator('button[type=submit]').click();
  await expect(page.locator('.easy-form-message.success')).toBeVisible();

  await deleteForm(page, handle);
});
