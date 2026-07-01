import { test, expect, type Locator } from '@playwright/test';

// Read path: the manualtestingform "number" field has a saved condition rule
// scoped to the French site (see conditions-per-site.spec.ts fixture).
test('CP: an existing rule shows its saved Site scope', async ({ page }) => {
  await page.goto('/cp/easy-form/forms/1921');
  const field = page.locator('.field-in-row[data-field-type="number"]').first();
  await field.locator('.toggle-field-settings').click();
  await field.locator('.ef-tab[data-tab="conditions"]').click();
  await expect(field.locator('.condition-rule select[name$="[site]"]').first()).toHaveValue('french');
});

// Write path: build a form, add a condition rule with a Site scope, save, and
// confirm it survives the round-trip (exercises buildConditions + the JS rule
// builder's new Site select).
async function addField(row: Locator, type: string, label: string, handle: string) {
  const page = row.page();
  await row.locator('.layout-row-label').first().click();
  await page.locator(`.ef-field-palette .ef-palette-item[data-field-type="${type}"] .ef-palette-item-add`).click();
  const field = row.locator('.field-in-row').last();
  await field.locator('.toggle-field-settings').click();
  await field.locator('.field-label-input').fill(label);
  await field.locator('.field-handle-input').fill(handle);
  await field.locator('.field-dialog-save').click();
}

test('CP: a new rule Site scope round-trips through save', async ({ page }) => {
  const handle = 'e2eCondSite' + Date.now().toString().slice(-6);
  await page.goto('/cp/easy-form/forms/new');
  await page.fill('#name', 'E2E Cond Site');
  await page.fill('#handle', handle);

  await page.locator('.add-row-btn[data-page-index="0"]').first().click();
  const row = page.locator('.page-pane[data-page-pane="0"] .layout-row').last();
  await addField(row, 'text', 'Trigger', 'trigger');
  await addField(row, 'text', 'Target', 'target');

  // On the Target field, add a rule: show when trigger == "go", on French only.
  const target = row.locator('.field-in-row').last();
  await target.locator('.toggle-field-settings').click();
  await target.locator('.ef-tab[data-tab="conditions"]').click();
  await target.locator('.add-condition-rule').click();
  const rule = target.locator('.condition-rule').last();
  await rule.locator('input[name$="[field]"]').fill('trigger');
  await rule.locator('select[name$="[operator]"]').selectOption('equals');
  await rule.locator('input[name$="[value]"]').fill('go');
  await rule.locator('select[name$="[site]"]').selectOption('french');
  await target.locator('.field-dialog-save').click();

  await page.keyboard.press('Control+s');
  await expect(page).toHaveURL(/\/cp\/easy-form\/forms\/\d+/);

  // Reload and confirm the saved Site scope.
  await page.reload();
  const saved = page.locator('.field-in-row[data-field-type="text"]').last();
  await saved.locator('.toggle-field-settings').click();
  await saved.locator('.ef-tab[data-tab="conditions"]').click();
  await expect(saved.locator('.condition-rule select[name$="[site]"]').first()).toHaveValue('french');
});
