import { test, expect } from '@playwright/test';
import { newForm, addRow, addField, setBasics, closeField, save, deleteForm } from './builder';

const ON = /(^|\s)on(\s|$)/;

// Regression: our custom builder lightswitch handler used to also fire on Craft's
// native <button class="lightswitch"> elements, double-toggling them against
// Garnish. A native lightswitch must turn on with a single click and persist.
test('CP: a native lightswitch toggles once and persists (no double-toggle)', async ({ page }) => {
  const handle = 'e2eLsw' + Date.now().toString().slice(-6);

  await newForm(page, 'E2E Lightswitch', handle);
  const row = await addRow(page, 0);
  const field = await addField(row, 'text');
  await setBasics(field, 'Name', 'name');
  await closeField(field);

  // Behavior tab → a Craft-native lightswitch.
  await page.locator('.ef-tabnav-item[data-pane="behavior"]').click();
  const sw = page.locator('#allowUrlPrefill');
  await expect(sw).not.toHaveClass(ON);

  await sw.click();
  await expect(sw).toHaveClass(ON); // single click → on (not bounced back off)

  await save(page);
  await page.reload();
  await page.locator('.ef-tabnav-item[data-pane="behavior"]').click();
  await expect(page.locator('#allowUrlPrefill')).toHaveClass(ON);

  await deleteForm(page, handle);
});
