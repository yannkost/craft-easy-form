import { test, expect, type Locator } from '@playwright/test';

// Mirror of the helper in cp-builder.spec.ts: add a field to a row and label it.
async function addField(row: Locator, type: string, label: string, handle: string) {
  const page = row.page();
  await row.locator('.layout-row-label').first().click();
  await page
    .locator(`.ef-field-palette .ef-palette-item[data-field-type="${type}"] .ef-palette-item-add`)
    .click();
  const field = row.locator('.field-in-row').last();
  await field.locator('.toggle-field-settings').click();
  await field.locator('.field-label-input').fill(label);
  await field.locator('.field-handle-input').fill(handle);
  await field.locator('.field-dialog-save').click();
}

test('CP: per-page Next/Previous + submit button labels persist', async ({ page }) => {
  const handle = 'e2eNavLbl' + Date.now().toString().slice(-6);

  await page.goto('/cp/easy-form/forms/new');
  await page.fill('#name', 'E2E Nav Labels');
  await page.fill('#handle', handle);

  // Page 1 (Twig-rendered pane): a field + the per-page Next label.
  await page.locator('.add-row-btn[data-page-index="0"]').first().click();
  const pane0 = page.locator('.page-pane[data-page-pane="0"]');
  const page1Row = pane0.locator('.layout-row').last();
  await addField(page1Row, 'text', 'Full Name', 'fullName');
  // Nav labels live in the page's "Labels" inner tab.
  await pane0.locator('.ef-page-inner-tabs .ef-tab', { hasText: 'Labels' }).click();
  await page.fill('input[name="pages[0][nextLabel]"]', 'Continue');

  // Page 2 (JS-built pane via addPage): a field + the per-page Previous label.
  await page.locator('#add-page-btn').click();
  await page.locator('#page-tabs .page-tab').nth(1).click();
  await page.locator('.add-row-btn[data-page-index="1"]').first().click();
  const pane1 = page.locator('.page-pane[data-page-pane="1"]');
  const page2Row = pane1.locator('.layout-row').last();
  await addField(page2Row, 'textarea', 'Message', 'message');
  await pane1.locator('.ef-page-inner-tabs .ef-tab', { hasText: 'Labels' }).click();
  await page.fill('input[name="pages[1][prevLabel]"]', 'Go back');

  // Messages tab: the form-level submit button label.
  await page.locator('.ef-tabnav-item[data-pane="messages"]').click();
  await page.fill('input[name="submitButtonLabel"]', 'Send it');

  await page.keyboard.press('Control+s');
  await expect(page).toHaveURL(/\/cp\/easy-form\/forms\/\d+/);

  // Values persisted (read via inputValue so tab/page visibility doesn't matter).
  expect(await page.locator('input[name="pages[0][nextLabel]"]').inputValue()).toBe('Continue');
  expect(await page.locator('input[name="pages[1][prevLabel]"]').inputValue()).toBe('Go back');
  expect(await page.locator('input[name="submitButtonLabel"]').inputValue()).toBe('Send it');

  // Cleanup.
  page.on('dialog', (d) => d.accept());
  await page.goto('/cp/easy-form/forms?search=' + encodeURIComponent(handle));
  const row = page.locator('.ef-table tbody tr').filter({ hasText: handle });
  await row.locator('.ef-row-actions .ef-delete').click();
  await expect(row).toHaveCount(0);
});
