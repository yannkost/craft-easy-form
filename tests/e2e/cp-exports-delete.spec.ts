import { test, expect } from '@playwright/test';
import { apiSubmit } from './submit';
import { formId } from './db';

// The Exports page has a per-form Delete that reuses the filter modal and adds a
// confirmation step (with a live count). Uses a dedicated form so it can't touch
// data other specs rely on.
test('CP: delete modal confirms a filtered count and removes the submissions', async ({ page, request }) => {
  const fid = formId('e2eDelHub');
  for (let i = 0; i < 3; i++) {
    await apiSubmit(request, {
      formId: fid,
      siteId: '1',
      fields: { name: 'Del', email: `del-${Date.now()}-${i}@example.test` },
    });
  }

  await page.goto('/cp/easy-form/exports?search=e2eDelHub');
  const row = page.locator('.ef-table tbody tr').filter({ hasText: 'e2eDelHub' }).first();
  await expect(row.locator('.ef-cell-subs')).toHaveText('3');

  // Open the delete modal.
  await row.locator('.ef-modal-open[data-mode="delete"]').click();
  const modal = page.locator('#ef-modal');
  await expect(modal).toBeVisible();
  await expect(page.locator('#ef-modal-title')).toHaveText('Delete submissions');

  // A non-matching status filter → confirm count is 0, delete disabled.
  await page.locator('#ef-f-status').selectOption('spam');
  await page.locator('#ef-do-delete').click();
  await expect(page.locator('#ef-confirm-msg')).toContainText('0 submission');
  await expect(page.locator('#ef-confirm-delete')).toBeDisabled();

  // Back, clear the filter → confirm count is 3.
  await page.locator('[data-confirm-back]').click();
  await page.locator('#ef-f-status').selectOption('');
  await page.locator('#ef-do-delete').click();
  await expect(page.locator('#ef-confirm-msg')).toContainText('3 submission');

  // Confirm the delete; the page reloads and the count is gone.
  await page.locator('#ef-confirm-delete').click();
  await expect(row.locator('.ef-cell-subs')).toHaveText('0');
  await expect(row.locator('.ef-modal-open[data-mode="delete"]')).toBeDisabled();
});
