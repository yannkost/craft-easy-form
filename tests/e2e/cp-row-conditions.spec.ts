import { test, expect } from '@playwright/test';
import { newForm, addRow, addField, setBasics, closeField, save, deleteForm } from './builder';

// Build a row-level condition in the CP, confirm it persists through a save and
// drives visibility on the front end.
test('CP: a row condition built in the UI hides its row until the trigger matches', async ({ page }) => {
  const handle = 'e2eRowUi' + Date.now().toString().slice(-6);

  await newForm(page, 'E2E Row Cond UI', handle);

  // Row 0: the trigger field.
  const row0 = await addRow(page, 0);
  const trigger = await addField(row0, 'text');
  await setBasics(trigger, 'Type', 'type');
  await closeField(trigger);

  // Row 1: a field plus a row condition (show when type = business).
  const row1 = await addRow(page, 0);
  const dependent = await addField(row1, 'text');
  await setBasics(dependent, 'Company Name', 'companyName');
  await closeField(dependent);

  await row1.locator('.layout-row-content > .ef-tabs > .ef-tab[data-tab="conditions"]').click();
  const cond = row1.locator('.row-tab-pane[data-pane="conditions"]');
  await cond.locator('.add-condition-rule').click();
  const rule = cond.locator('.condition-rule').last();
  // The source input offers the form's known handles via a shared datalist.
  await expect(rule.locator('input[name$="[field]"]')).toHaveAttribute('list', 'ef-condition-handles');
  await expect(page.locator('#ef-condition-handles option[value="type"]')).toHaveCount(1);

  await rule.locator('input[name$="[field]"]').fill('type');
  await rule.locator('select[name$="[operator]"]').selectOption('equals');
  await rule.locator('input[name$="[value]"]').fill('business');

  await save(page);

  // Front end: the company row is hidden until type = business.
  await page.goto(`/dev/form?f=${handle}`);
  const company = page.locator('input[name="fields[companyName]"]');
  await expect(company).toBeHidden();
  await page.fill('input[name="fields[type]"]', 'business');
  await expect(company).toBeVisible();

  // Back in the CP, the rule round-tripped.
  await page.goto('/cp/easy-form/forms?search=' + encodeURIComponent(handle));
  await page.locator('.ef-table tbody tr').filter({ hasText: handle }).locator('.ef-form-name, a').first().click();
  const savedRow = page.locator('.layout-row').nth(1);
  await savedRow.locator('.layout-row-content > .ef-tabs > .ef-tab[data-tab="conditions"]').click();
  await expect(savedRow.locator('.row-tab-pane[data-pane="conditions"] input[name$="[rules][0][field]"]')).toHaveValue('type');
  await expect(savedRow.locator('.row-tab-pane[data-pane="conditions"] input[name$="[rules][0][value]"]')).toHaveValue('business');

  await deleteForm(page, handle);
});
