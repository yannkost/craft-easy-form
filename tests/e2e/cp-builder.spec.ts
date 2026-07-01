import { test, expect, type Locator } from '@playwright/test';

// Adds a field to a row via the builder UI and sets its label + handle.
// The palette is drag-and-drop; use its "+" button (adds to the active row),
// making `row` active first so the field lands there.
async function addField(row: Locator, type: string, label: string, handle: string) {
  const page = row.page();
  await row.locator('.layout-row-label').first().click();
  await page
    .locator(`.ef-field-palette .ef-palette-item[data-field-type="${type}"] .ef-palette-item-add`)
    .click();

  const field = row.locator('.field-in-row').last();
  // Fields no longer auto-open their popover — open it, fill, then save. The
  // dialog commits its edits only on "Save field" (the ✕ now discards them).
  await field.locator('.toggle-field-settings').click();
  await field.locator('.field-label-input').fill(label);
  await field.locator('.field-handle-input').fill(handle);
  await field.locator('.field-dialog-save').click();
}

test('CP: build a multi-page form with rows/fields/labels and save', async ({ page }) => {
  const handle = 'e2eBuilt' + Date.now().toString().slice(-6);

  await page.goto('/cp/easy-form/forms/new');
  await page.fill('#name', 'E2E Built');
  await page.fill('#handle', handle);

  // Page 1 — add a row with two fields.
  await page.locator('.add-row-btn[data-page-index="0"]').first().click();
  const page1Row = page.locator('.page-pane[data-page-pane="0"] .layout-row').last();
  await addField(page1Row, 'text', 'Full Name', 'fullName');
  await addField(page1Row, 'email', 'Email Address', 'emailAddress');

  // Add a second page, switch to it, add a row + field.
  await page.locator('#add-page-btn').click();
  await page.locator('#page-tabs .page-tab').nth(1).click();
  await page.locator('.add-row-btn[data-page-index="1"]').first().click();
  const page2Row = page.locator('.page-pane[data-page-pane="1"] .layout-row').last();
  await addField(page2Row, 'textarea', 'Your Message', 'message');

  // Save the form (Ctrl+S is Craft's save shortcut — locale-agnostic).
  await page.keyboard.press('Control+s');

  // Redirected to the saved form's edit page, and the fields persisted.
  await expect(page).toHaveURL(/\/cp\/easy-form\/forms\/\d+/);
  // Page 1 fields are on the active pane (visible).
  await expect(page.locator('.field-label-text', { hasText: 'Full Name' })).toBeVisible();
  await expect(page.locator('.field-label-text', { hasText: 'Email Address' })).toBeVisible();
  // Page 2 exists; switch to its tab and confirm its field persisted.
  await page.locator('#page-tabs .page-tab').nth(1).click();
  await expect(page.locator('.field-label-text', { hasText: 'Your Message' })).toBeVisible();

  // Cleanup: delete the form via the index (also exercises the delete flow).
  // Search by handle so it's on the first page regardless of pagination.
  page.on('dialog', (d) => d.accept());
  await page.goto('/cp/easy-form/forms?search=' + encodeURIComponent(handle));
  const row = page.locator('.ef-table tbody tr').filter({ hasText: handle });
  await row.locator('.ef-row-actions .ef-delete').click();
  await expect(row).toHaveCount(0);
});
