import { test, expect, type Locator, type Page } from '@playwright/test';

// The agree field's Link tab manages a per-site list of links: each row pairs a
// phrase ("Text to link") with a target (a linked Entry or a custom URL), added
// via an "Add link" button. The field is server-rendered, so the row — including
// Craft's Entry selector — is fetched and initialised without saving first.

function agreeField(page: Page): Locator {
  return page.locator('.field-in-row[data-field-type="agree"]').first();
}

test('CP: agree links — add a per-site link row and round-trip text + custom URL', async ({ page }) => {
  const handle = 'e2eAgreeLink' + Date.now().toString().slice(-6);
  await page.goto('/cp/easy-form/forms/new');
  await page.fill('#name', 'E2E Agree Link');
  await page.fill('#handle', handle);

  // Build a row and add a (server-rendered) agree field.
  await page.locator('.add-row-btn[data-page-index="0"]').first().click();
  const row = page.locator('.page-pane[data-page-pane="0"] .layout-row').last();
  await row.locator('.layout-row-label').first().click();
  await page.locator('.ef-field-palette .ef-palette-item[data-field-type="agree"] .ef-palette-item-add').click();

  const field = row.locator('.field-in-row[data-field-type="agree"]').last();
  await expect(field).toBeVisible();
  await expect(field.locator('.ef-field-loading')).toHaveCount(0);

  await field.locator('.toggle-field-settings').click();
  await field.locator('.field-label-input').first().fill('I accept the Privacy Policy');
  await field.locator('.field-handle-input').first().fill('agreeField');

  // The Link tab shows a per-site list with an Add link button. (The list
  // itself is empty/zero-height until a row is added, so assert on the button.)
  await field.locator('.ef-tab[data-tab="link"]').click();
  await expect(field.locator('.ef-link-list').first()).toBeAttached();
  const addBtn = field.locator('.add-link-row').first();
  await expect(addBtn).toBeVisible();

  // Adding a row yields text + Entry select + custom URL on one line, and the
  // element select initialises in the fresh row (works without saving first).
  await addBtn.click();
  await expect(field.locator('.ef-link-row')).toHaveCount(1);
  const linkRow = field.locator('.ef-link-row').last();
  await expect(linkRow.locator('.ef-link-text')).toBeVisible();
  await expect(linkRow.locator('.ef-link-url')).toBeVisible();
  await expect(linkRow.locator('.elementselect')).toBeVisible();
  const tops = await linkRow.evaluate((r) =>
    Array.from(r.querySelectorAll('.ef-link-text, .ef-link-entry, .ef-link-url'))
      .map((c) => Math.round(c.getBoundingClientRect().top))
  );
  expect(Math.max(...tops) - Math.min(...tops)).toBeLessThan(6);

  // Fill phrase + custom URL, save the field, save the form.
  await linkRow.locator('.ef-link-text').fill('Privacy Policy');
  await linkRow.locator('.ef-link-url').fill('/privacy');
  await field.locator('.field-dialog-save').first().click();
  await page.keyboard.press('Control+s');
  await expect(page).toHaveURL(/\/cp\/easy-form\/forms\/\d+/);

  // Reload and confirm the link row persisted with its text and URL.
  await page.reload();
  const saved = agreeField(page);
  await saved.locator('.toggle-field-settings').click();
  await saved.locator('.ef-tab[data-tab="link"]').click();
  const savedRow = saved.locator('.ef-link-row').first();
  await expect(savedRow.locator('.ef-link-text')).toHaveValue('Privacy Policy');
  await expect(savedRow.locator('.ef-link-url')).toHaveValue('/privacy');
});
