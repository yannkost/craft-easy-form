import { test, expect } from '@playwright/test';
import { apiSubmit } from './submit';
import { formId } from './db';

// The forms index lists a per-form submission count that links to the
// submissions list filtered by that form.
test('forms index shows a submission count linking to the filtered submissions', async ({ page, request }) => {
  const fid = formId('e2eContact');

  // Add two submissions so the count is non-zero and deterministic-ish.
  for (let i = 0; i < 2; i++) {
    await apiSubmit(request, {
      formId: fid,
      fields: { name: 'Counter', email: `count-${Date.now()}-${i}@example.test` },
    });
  }

  await page.goto('/cp/easy-form/forms?search=e2eContact');
  const row = page.locator('.ef-table tbody tr').filter({ hasText: 'e2eContact' });
  const count = row.locator('.ef-cell-subs .ef-sub-count');

  await expect(count).toBeVisible();

  // It links to the submissions list filtered by this form.
  const href = await count.getAttribute('href');
  expect(href).toContain('/submissions');
  expect(href).toContain('formId=');

  // At least the two we just added.
  const n = parseInt((await count.innerText()).trim(), 10);
  expect(n).toBeGreaterThanOrEqual(2);
});
