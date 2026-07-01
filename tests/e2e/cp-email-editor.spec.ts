import { test, expect } from '@playwright/test';
import { newForm, addRow, addField, setBasics, closeField, save } from './builder';
import { deleteForm } from './db';

// The notification "Email Content" textarea is upgraded into a chip editor: an
// "Insert value" dropdown drops a {field[handle]} chip, backed by the hidden
// textarea that's actually submitted.
test.describe('email content chip editor', () => {
  test.describe.configure({ mode: 'serial' });
  const handle = 'emailEditorForm';

  test.afterAll(() => deleteForm(handle));

  test('inserting a field chip writes the token and persists', async ({ page }) => {
    await newForm(page, 'Email Editor', handle);
    const row = await addRow(page);
    const field = await addField(row, 'text');
    await setBasics(field, 'Full name', 'name');
    await closeField(field);
    await save(page);
    await page.reload();

    // Notifications tab → add a notification → expand it.
    await page.locator('.ef-tabnav-item[data-pane="emailnotifications"]').click();
    await page.locator('#add-notification-btn').click();
    const block = page.locator('#notifications-list .notification-block').last();
    await block.locator('.notification-header').click();

    const surface = block.locator('.ef-email-surface').first();
    await expect(surface).toBeVisible();
    const source = block.locator('textarea[name*="[siteContent][default]"]').first();

    // Insert a value chip for the `name` field.
    await block.locator('.ef-email-insert-value').first().selectOption('name');
    await expect(block.locator('.ef-token-field')).toHaveCount(1);
    await expect(source).toHaveValue(/\{field\[name\]\}/);

    // Insert a label + value (combo) chip too.
    await block.locator('.ef-email-insert-combo').first().selectOption('name');
    await expect(block.locator('.ef-token-combo')).toHaveCount(1);
    await expect(source).toHaveValue(/\{combo\[name\]\}/);

    // The combo chip's layout toggle flips stacked (combo) → inline (comboInline).
    await block.locator('.ef-token-combo .ef-token-layout').click();
    await expect(source).toHaveValue(/\{comboInline\[name\]\}/);
    await expect(source).not.toHaveValue(/\{combo\[name\]\}/);

    // Insert a field table via the picker (pick the `name` field). Scope to the
    // first (default-site) editor — the block has one editor per site.
    const editor = block.locator('.ef-email-editor').first();
    await editor.locator('.ef-email-table-btn').click();
    const picker = editor.locator('.ef-table-picker');
    await expect(picker).toBeVisible();
    await picker.locator('.ef-table-picker-list input[value="name"]').check();
    await picker.locator('.ef-tp-insert').click();
    await expect(editor.locator('.ef-token-table')).toHaveCount(1);
    await expect(source).toHaveValue(/\{table\[name\]\}/);

    // Pick a non-default content format — it must survive the round-trip.
    await block.locator('select[name*="[contentFormat]"]').selectOption('markdown');

    // Persist and reload — the tokens survive and render as chips again.
    await save(page);
    await page.reload();
    await page.locator('.ef-tabnav-item[data-pane="emailnotifications"]').click();
    const saved = page.locator('#notifications-list .notification-block').first();
    await saved.locator('.notification-header').click();
    const savedSource = saved.locator('textarea[name*="[siteContent][default]"]').first();
    await expect(saved.locator('.ef-token-field')).toHaveCount(1);
    await expect(saved.locator('.ef-token-combo')).toHaveCount(1);
    await expect(saved.locator('.ef-token-table')).toHaveCount(1);
    await expect(savedSource).toHaveValue(/\{field\[name\]\}/);
    await expect(savedSource).toHaveValue(/\{comboInline\[name\]\}/);
    await expect(savedSource).toHaveValue(/\{table\[name\]\}/);
    await expect(saved.locator('select[name*="[contentFormat]"]')).toHaveValue('markdown');
  });
});
