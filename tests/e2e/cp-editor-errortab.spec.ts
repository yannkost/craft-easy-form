import { test, expect } from '@playwright/test';
import { newForm, addRow, addField, setBasics, closeField } from './builder';

// After a failed save, the editor flags the tab whose pane holds the errors
// (and jumps to it). A duplicate handle makes the save fail with a handle error
// in the Form Layout pane — and creates no form, so there's nothing to clean up.
test('CP editor: a failed save flags the errored tab', async ({ page }) => {
  await newForm(page, 'Error Tab Test', 'contactTest'); // contactTest already exists
  const row = await addRow(page, 0);
  const field = await addField(row, 'text');
  await setBasics(field, 'Foo', 'foo');
  await closeField(field);

  await page.keyboard.press('Control+s');
  await page.waitForLoadState('networkidle');

  // The handle error renders in #layout, and its tab is flagged + activated.
  await expect(page.locator('#layout ul.errors li').first()).toBeVisible();
  const layoutTab = page.locator('.ef-tabnav-item[data-pane="layout"]');
  await expect(layoutTab).toHaveClass(/has-error/);
  await expect(layoutTab).toHaveClass(/is-active/);
});
