import { test, expect } from '@playwright/test';
import { newForm, addRow, addField, setBasics, closeField, openField } from './builder';

// The field settings dialog commits its edits only on "Save field". Cancel (and
// the ✕ / backdrop) discard them, and Save validates the label + handle. None of
// these tests save the form, so there's nothing to clean up.

test('CP field dialog: Cancel reverts edits to the last saved state', async ({ page }) => {
  await newForm(page, 'Dialog Cancel', 'dialogCancel' + Date.now().toString().slice(-6));
  const row = await addRow(page, 0);
  const field = await addField(row, 'text');
  await setBasics(field, 'Original', 'original');
  await closeField(field); // commit

  // Re-open, change the label (which also re-derives the handle), then Cancel.
  // Cancelling a dirty dialog prompts for confirmation — accept it.
  await openField(field);
  await field.locator('.field-label-input').fill('Changed');
  page.once('dialog', (d) => d.accept());
  await field.locator('.field-dialog-cancel').click();
  await expect(field.locator('.field-popover-backdrop')).toBeHidden();

  // The change was discarded: both inputs and the card title are unchanged.
  await openField(field);
  await expect(field.locator('.field-label-input')).toHaveValue('Original');
  await expect(field.locator('.field-handle-input')).toHaveValue('original');
  await expect(field.locator('.field-label-text')).toHaveText('Original');
});

test('CP field dialog: dismissing the discard prompt keeps the dialog open and the edit', async ({ page }) => {
  await newForm(page, 'Dialog Keep', 'dialogKeep' + Date.now().toString().slice(-6));
  const row = await addRow(page, 0);
  const field = await addField(row, 'text');
  await setBasics(field, 'Original', 'original');
  await closeField(field);

  await openField(field);
  await field.locator('.field-label-input').fill('Changed');
  // Decline the discard prompt — the dialog stays open with the edit intact.
  page.once('dialog', (d) => d.dismiss());
  await field.locator('.field-dialog-cancel').click();
  await expect(field.locator('.field-popover-backdrop')).toBeVisible();
  await expect(field.locator('.field-label-input')).toHaveValue('Changed');
});

test('CP field dialog: an untouched dialog closes without a prompt', async ({ page }) => {
  await newForm(page, 'Dialog Clean', 'dialogClean' + Date.now().toString().slice(-6));
  const row = await addRow(page, 0);
  const field = await addField(row, 'text');
  await setBasics(field, 'Stable', 'stable');
  await closeField(field);

  // Re-open and immediately cancel without changing anything: no prompt should
  // fire (a prompt here would hang/dismiss and leave the dialog open).
  let prompted = false;
  page.on('dialog', (d) => { prompted = true; d.dismiss(); });
  await openField(field);
  await field.locator('.field-dialog-cancel').click();
  await expect(field.locator('.field-popover-backdrop')).toBeHidden();
  expect(prompted).toBe(false);
});

test('CP field dialog: Save blocks a duplicate handle', async ({ page }) => {
  await newForm(page, 'Dialog Dup', 'dialogDup' + Date.now().toString().slice(-6));
  const row = await addRow(page, 0);

  const first = await addField(row, 'text');
  await setBasics(first, 'First', 'shared');
  await closeField(first);

  const second = await addField(row, 'text');
  await setBasics(second, 'Second', 'second');
  // Collide with the first field's handle and try to save.
  await second.locator('.field-handle-input').fill('shared');
  await second.locator('.field-dialog-save').click();

  await expect(second.locator('.field-dialog-error')).toBeVisible();
  await expect(second.locator('.field-dialog-error')).toContainText('already used');
  // The dialog stays open so the user can fix it.
  await expect(second.locator('.field-popover-backdrop')).toBeVisible();
});

test('CP field dialog: Save blocks a missing handle', async ({ page }) => {
  await newForm(page, 'Dialog Empty', 'dialogEmpty' + Date.now().toString().slice(-6));
  const row = await addRow(page, 0);
  const field = await addField(row, 'text');

  await openField(field);
  await field.locator('.field-label-input').fill('No Handle');
  await field.locator('.field-handle-input').fill(''); // clear the auto-derived handle
  await field.locator('.field-dialog-save').click();

  await expect(field.locator('.field-dialog-error')).toBeVisible();
  await expect(field.locator('.field-handle-input')).toHaveClass(/(^|\s)error(\s|$)/);
  await expect(field.locator('.field-popover-backdrop')).toBeVisible();
});
