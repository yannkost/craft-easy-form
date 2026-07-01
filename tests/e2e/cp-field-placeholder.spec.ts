import { test, expect, type Page } from '@playwright/test';
import { gotoFormByHandle } from './helpers';

// The Placeholder setting only makes sense for free-text inputs. Choice fields
// (agree → single checkbox; checkboxes → checkbox/radio group) hide it; text
// and select (whose placeholder is the empty first option) keep it.
//
// Field settings open in a per-field popover; the placeholder block is the only
// one tagged with data-hide-for-types containing "agree".
function field(page: Page, type: string) {
  return page.locator(`.field-in-row[data-field-type="${type}"]`).first();
}
function placeholderOf(page: Page, type: string) {
  return field(page, type).locator('[data-hide-for-types*="agree"]');
}
async function openSettings(page: Page, type: string) {
  await field(page, type).locator('.toggle-field-settings').click();
}
async function closeSettings(page: Page, type: string) {
  await field(page, type).locator('.field-popover-close').click();
  await expect(field(page, type).locator('.field-popover-backdrop')).toBeHidden();
}

test('CP: choice fields hide the Placeholder setting; text/select keep it', async ({ page }) => {
  await gotoFormByHandle(page, 'galleryAllFields'); // has all field types

  for (const type of ['agree', 'checkboxes']) {
    await openSettings(page, type);
    await expect(placeholderOf(page, type)).toBeHidden();
    await closeSettings(page, type);
  }

  for (const type of ['text', 'select']) {
    await openSettings(page, type);
    await expect(placeholderOf(page, type)).toBeVisible();
    await closeSettings(page, type);
  }
});
