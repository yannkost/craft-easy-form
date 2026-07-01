import { test, expect } from '@playwright/test';
import { newForm, addRow, addField, setBasics, closeField, save, deleteForm } from './builder';

// Build a conditional notification in the CP, save, and confirm it round-trips
// (exercises notifications.js rule-builder + actionSave::parseNotificationConditions).
test('CP: a conditional notification built in the UI persists through a save', async ({ page }) => {
  const handle = 'e2eCondUi' + Date.now().toString().slice(-6);

  await newForm(page, 'E2E Cond Notify UI', handle);
  const row = await addRow(page, 0);
  const field = await addField(row, 'text');
  await setBasics(field, 'Topic', 'topic');
  await closeField(field);

  // Notifications tab → add a notification.
  await page.locator('.ef-tabnav-item[data-pane="emailnotifications"]').click();
  await page.locator('#add-notification-btn').click();

  const block = page.locator('#notifications-list .notification-block').last();
  // Newly added blocks render collapsed — expand to edit.
  await block.locator('.toggle-notification').click();

  await block.locator('.notification-name-input').fill('Topic = sales');
  await block.locator('input[name$="[recipients]"]').fill('cond-ui@example.test');

  // Conditional sending: only if rules match.
  await block.locator('select[name$="[conditions][action]"]').selectOption('show');
  await block.locator('.add-notification-rule').click();
  const rule = block.locator('.notification-rule').last();
  await rule.locator('input[name$="[field]"]').fill('topic');
  await rule.locator('select[name$="[operator]"]').selectOption('equals');
  await rule.locator('input[name$="[value]"]').fill('sales');

  await save(page);

  // Reload and confirm the condition + rule survived.
  await page.reload();
  await page.locator('.ef-tabnav-item[data-pane="emailnotifications"]').click();
  const saved = page.locator('#notifications-list .notification-block').first();
  await saved.locator('.toggle-notification').click();

  await expect(saved.locator('select[name$="[conditions][action]"]')).toHaveValue('show');
  const savedRule = saved.locator('.notification-rule').first();
  await expect(savedRule.locator('input[name$="[field]"]')).toHaveValue('topic');
  await expect(savedRule.locator('select[name$="[operator]"]')).toHaveValue('equals');
  await expect(savedRule.locator('input[name$="[value]"]')).toHaveValue('sales');

  await deleteForm(page, handle);
});
