import { test, expect, type Locator } from '@playwright/test';

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

test('CP: a unique field error message round-trips through save', async ({ page }) => {
  const handle = 'e2eUniqMsg' + Date.now().toString().slice(-6);
  await page.goto('/cp/easy-form/forms/new');
  await page.fill('#name', 'E2E Unique Msg');
  await page.fill('#handle', handle);

  await page.locator('.add-row-btn[data-page-index="0"]').first().click();
  const row = page.locator('.page-pane[data-page-pane="0"] .layout-row').last();
  await addField(row, 'text', 'Username', 'username');

  const field = row.locator('.field-in-row[data-field-type="text"]').last();
  await field.locator('.toggle-field-settings').click();
  await field.locator('.ef-tab[data-tab="validation"]').click();
  // Turn unique on and set a custom message.
  await field.locator('.validation-unique-field .lightswitch').first().click();
  await field.locator('input[name$="[siteUniqueMessages][default]"]').fill('That username is taken.');
  await field.locator('.field-dialog-save').click();

  await page.keyboard.press('Control+s');
  await expect(page).toHaveURL(/\/cp\/easy-form\/forms\/\d+/);

  // Reload and confirm both the unique flag and the message persisted.
  await page.reload();
  const saved = page.locator('.field-in-row[data-field-type="text"]').last();
  await saved.locator('.toggle-field-settings').click();
  await saved.locator('.ef-tab[data-tab="validation"]').click();
  await expect(saved.locator('input[name$="[unique]"]')).toHaveValue('1');
  await expect(saved.locator('input[name$="[siteUniqueMessages][default]"]')).toHaveValue('That username is taken.');
});

test('CP: condition Action and Logic sit on the same row', async ({ page }) => {
  await page.goto('/cp/easy-form/forms/1921'); // number field has a condition
  const field = page.locator('.field-in-row[data-field-type="number"]').first();
  await field.locator('.toggle-field-settings').click();
  await field.locator('.ef-tab[data-tab="conditions"]').click();

  const action = field.locator('.ef-cond-action-logic > .field').nth(0);
  const logic = field.locator('.ef-cond-action-logic > .field').nth(1);
  const addRule = field.locator('.ef-cond-action-logic > .add-condition-rule');
  const a = await action.boundingBox();
  const l = await logic.boundingBox();
  const r = await addRule.boundingBox();
  expect(a && l && r).toBeTruthy();
  // Same row: tops aligned and laid out left → right (Action, Logic, Add Rule).
  expect(Math.abs(a!.y - l!.y)).toBeLessThan(8);
  expect(l!.x).toBeGreaterThan(a!.x + a!.width - 4);
  expect(r!.x).toBeGreaterThan(l!.x + l!.width - 4);
  // The button shares the row vertically (overlaps the selects' band).
  expect(r!.y).toBeLessThan(a!.y + a!.height);
  expect(r!.y + r!.height).toBeGreaterThan(a!.y);
});

test('CP: a saved condition rule renders its parts on one row', async ({ page }) => {
  await page.goto('/cp/easy-form/forms/1921'); // number field has a saved rule
  const field = page.locator('.field-in-row[data-field-type="number"]').first();
  await field.locator('.toggle-field-settings').click();
  await field.locator('.ef-tab[data-tab="conditions"]').click();

  const rule = field.locator('.condition-rule').first();
  // field input, operator select, value input, site select all share the row top.
  const tops = await rule.evaluate((r) =>
    Array.from(r.querySelectorAll('input, select')).map(c => Math.round(c.getBoundingClientRect().top))
  );
  expect(tops.length).toBeGreaterThanOrEqual(4);
  expect(Math.max(...tops) - Math.min(...tops)).toBeLessThan(6);
  // And the saved values are bound onto the raw inputs.
  await expect(rule.locator('input[name$="[field]"]')).toHaveValue('text');
  await expect(rule.locator('select[name$="[site]"]')).toHaveValue('french');
});
