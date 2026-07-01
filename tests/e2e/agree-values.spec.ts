import { test, expect } from '@playwright/test';
import { apiSubmit } from './submit';
import { formId, dataValueByEmail } from './db';

const uniqueEmail = (tag: string) => `${tag}-${Date.now()}-${Math.floor(Math.random() * 1e6)}@example.test`;

// e2eAgreeValues: agree field "consent" with per-language default + meanings —
// default site: default checked, Ja/Nein; french: default unchecked, oui/non.
test.describe('agree field: per-language values + default state', () => {
  test('default site defaults checked and stores the checked text', async ({ page }) => {
    await page.goto('/dev/form?f=e2eAgreeValues');

    const cb = page.locator('input[type=checkbox][data-field-type=agree]');
    await expect(cb).toBeChecked();                       // siteAgreeDefault default = 1
    await expect(cb).toHaveValue('Ja');                   // checked meaning
    await expect(page.locator('input[type=hidden][name="fields[consent]"]')).toHaveValue('Nein');

    const email = uniqueEmail('agree-de');
    await page.fill('input[name="fields[email]"]', email);
    await page.locator('form.easy-form button[type=submit]').click();
    await expect(page.locator('.easy-form-message.success')).toBeVisible();
    expect(dataValueByEmail('e2eAgreeValues', '$.values.consent', email)).toBe('"Ja"');
  });

  test('french site defaults unchecked and stores the unchecked text', async ({ page }) => {
    await page.goto('/fr/dev/form?f=e2eAgreeValues');

    const cb = page.locator('input[type=checkbox][data-field-type=agree]');
    await expect(cb).not.toBeChecked();                   // siteAgreeDefault french = 0
    await expect(cb).toHaveValue('oui');
    await expect(page.locator('input[type=hidden][name="fields[consent]"]')).toHaveValue('non');

    const email = uniqueEmail('agree-fr');
    await page.fill('input[name="fields[email]"]', email);
    // Leave the box unchecked — the hidden input records "non".
    await page.locator('form.easy-form button[type=submit]').click();
    await expect(page.locator('.easy-form-message.success')).toBeVisible();
    expect(dataValueByEmail('e2eAgreeValues', '$.values.consent', email)).toBe('"non"');
  });

  // e2eAgreeRequired: a required consent box. Server enforces "must be checked",
  // i.e. the submitted value must equal the site's checked value ("Accept").
  test('required consent is enforced server-side', async ({ request }) => {
    const bad = await apiSubmit(request, {
      formId: formId('e2eAgreeRequired'),
      fields: { email: uniqueEmail('terms-no'), terms: 'Decline' },
    });
    expect(bad.success).toBeFalsy();

    const ok = await apiSubmit(request, {
      formId: formId('e2eAgreeRequired'),
      fields: { email: uniqueEmail('terms-yes'), terms: 'Accept' },
    });
    expect(ok.success).toBe(true);
  });
});
