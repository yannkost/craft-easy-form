import { test, expect } from '@playwright/test';

// Fixture: manualtestingform's "number" field has a field-level condition
//   show when text == "go", scoped to the FRENCH site only.
// So on the default site the rule is dropped (field always visible); on French
// the rule is active (field hidden until text == "go").

test('site-scoped condition is inactive on the default site', async ({ page }) => {
  await page.goto('/dev/form?f=manualtestingform');
  // Rule is french-only → dropped here → number field is unconditionally shown.
  await expect(page.locator('input[name="fields[number]"]')).toBeVisible();
});

test('site-scoped condition is active on its site (French)', async ({ page }) => {
  await page.goto('/fr/dev/form?f=manualtestingform');
  const number = page.locator('input[name="fields[number]"]');
  // Rule active: hidden until the text field equals "go".
  await expect(number).toBeHidden();
  await page.fill('input[name="fields[text]"]', 'go');
  await expect(number).toBeVisible();
  await page.fill('input[name="fields[text]"]', 'nope');
  await expect(number).toBeHidden();
});
