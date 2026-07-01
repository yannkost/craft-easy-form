import { test, expect } from '@playwright/test';
import { newForm, addRow, addField, openField, fieldTab, closeField, save, deleteForm } from './builder';

// Build heading / callout / divider in the CP and confirm they render on the
// front end (exercises the field-manager presentational UI + actionSave).
test('CP: presentational fields can be added and render on the front end', async ({ page }) => {
  const handle = 'e2ePresUi' + Date.now().toString().slice(-6);

  await newForm(page, 'E2E Pres UI', handle);
  const row = await addRow(page, 0);

  // Heading (auto-opens). Set its text + level.
  const heading = await addField(row, 'heading');
  await openField(heading);
  await heading.locator('.field-label-input').fill('My Section');
  await fieldTab(heading, 'content');
  await heading.locator('select[name$="[headingLevel]"]').selectOption('h2');
  await closeField(heading);

  // Callout with a custom style + hex accent.
  const callout = await addField(row, 'callout');
  await openField(callout);
  await callout.locator('.field-label-input').fill('Heads up');
  await fieldTab(callout, 'content');
  await callout.locator('select[name$="[calloutStyle]"]').selectOption('warning');
  await callout.locator('input[name$="[calloutColor]"]').fill('#abc123');
  await closeField(callout);

  // Divider (no settings).
  await addField(row, 'divider');

  await save(page);

  // Front end reflects all three.
  await page.goto(`/dev/form?f=${handle}`);
  await expect(page.locator('h2.easy-form-heading')).toHaveText('My Section');
  const callout2 = page.locator('.easy-form-callout.easy-form-callout-warning');
  await expect(callout2).toContainText('Heads up');
  await expect(callout2).toHaveAttribute('style', /#abc123/);
  await expect(page.locator('hr.easy-form-divider')).toBeVisible();

  await deleteForm(page, handle);
});
