import { test, expect } from '@playwright/test';
import { newForm, addRow, addField, setBasics, closeField, save, deleteForm } from './builder';

// F1 regression: the quick "Required" toggle in a field's row header must work on
// a SAVED field, whose Required control is Craft's native (Garnish-managed)
// lightswitch — not the custom one used for freshly-added fields.
test.describe('quick required toggle', () => {
  // Run serially in one worker: the shared afterAll deletes the form, so a
  // parallel worker mustn't tear it down while another test is still using it.
  test.describe.configure({ mode: 'serial' });

  const handle = 'f1QuickRequired';

  test.afterAll(async ({ browser }) => {
    const page = await browser.newPage();
    await deleteForm(page, handle).catch(() => {});
    await page.close();
  });

  test('toggles required on a saved field (native lightswitch) and persists', async ({ page }) => {
    // Build a single text field and save it.
    await newForm(page, 'F1 Quick Required', handle);
    const row = await addRow(page);
    const field = await addField(row, 'text');
    await setBasics(field, 'Full name', 'fullName');
    await closeField(field);
    await save(page);

    // Reload so the field is server-rendered with Craft's native lightswitch.
    await page.reload();
    const saved = page.locator('.field-in-row').first();
    const toggle = saved.locator('.ef-quick-required');
    const asterisk = saved.locator('.field-in-row-label .required-indicator');
    const reqSwitch = saved.locator('.field-settings-pane[data-pane="required"] .lightswitch').first();

    // Starts off.
    await expect(toggle).toBeVisible();
    await expect(toggle).not.toHaveClass(/is-on/);
    await expect(asterisk).toHaveCount(0);

    // Click → on. Header pill, card asterisk and the dialog's native switch agree.
    await toggle.click();
    await expect(toggle).toHaveClass(/is-on/);
    await expect(toggle).toHaveAttribute('aria-pressed', 'true');
    await expect(asterisk).toHaveCount(1);
    await saved.locator('.toggle-field-settings').click();
    await expect(saved.locator('.field-popover-backdrop')).toBeVisible();
    await expect(reqSwitch).toHaveClass(/(^|\s)on(\s|$)/);
    await saved.locator('.field-popover-close').click();
    await expect(saved.locator('.field-popover-backdrop')).toBeHidden();

    // Click again → off (proves it isn't a one-shot, and Garnish stays in sync).
    await toggle.click();
    await expect(toggle).not.toHaveClass(/is-on/);
    await expect(asterisk).toHaveCount(0);
    await saved.locator('.toggle-field-settings').click();
    await expect(reqSwitch).not.toHaveClass(/(^|\s)on(\s|$)/);
    await saved.locator('.field-popover-close').click();

    // Turn on, save, reload → the required state persisted. (Save is a full-page
    // submit; wait for the resulting navigation rather than racing it with reload,
    // since the URL already matches /forms/{id} and save() wouldn't block here.)
    await toggle.click();
    await expect(toggle).toHaveClass(/is-on/);
    await Promise.all([
      page.waitForEvent('load'),
      page.keyboard.press('Control+s'),
    ]);

    const reloaded = page.locator('.field-in-row').first();
    await expect(reloaded.locator('.ef-quick-required')).toHaveClass(/is-on/);
    await expect(reloaded.locator('.field-in-row-label .required-indicator')).toHaveCount(1);
    await reloaded.locator('.toggle-field-settings').click();
    await expect(reloaded.locator('.field-settings-pane[data-pane="required"] .lightswitch').first())
      .toHaveClass(/(^|\s)on(\s|$)/);
  });

  test('toggles required on a freshly-added field (custom lightswitch)', async ({ page }) => {
    await newForm(page, 'F1 Fresh', 'f1Fresh');
    const row = await addRow(page);
    const field = await addField(row, 'text');

    const toggle = field.locator('.ef-quick-required');
    const asterisk = field.locator('.field-in-row-label .required-indicator');

    await expect(toggle).not.toHaveClass(/is-on/);
    await toggle.click();
    await expect(toggle).toHaveClass(/is-on/);
    await expect(asterisk).toHaveCount(1);
    // The dialog's custom lightswitch and the hidden value agree.
    await field.locator('.toggle-field-settings').click();
    await expect(field.locator('.field-settings-pane[data-pane="required"] .lightswitch').first())
      .toHaveClass(/(^|\s)on(\s|$)/);
    await expect(field.locator('input[name$="[required]"]')).toHaveValue('1');
    await field.locator('.field-popover-close').click();

    await toggle.click();
    await expect(toggle).not.toHaveClass(/is-on/);
    await expect(asterisk).toHaveCount(0);
    await expect(field.locator('input[name$="[required]"]')).toHaveValue('0');
    // (form is never saved, so nothing to clean up)
  });
});
