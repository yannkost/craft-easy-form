import { test, expect } from '@playwright/test';
import { actionJson } from './submit';
import {
  formId,
  formHandleById,
  deleteForm,
  deleteSubmissions,
  seedSubmissions,
  submissionCount,
  submissionCountWhere,
} from './db';

// Deleting a form must NOT destroy its submissions. The FK is ON DELETE SET NULL
// and the controller orphans submissions by default (formId → NULL, formHandle
// snapshot retained). The user can opt in to a hard cascade via `deleteSubmissions`.

// Spin up a throwaway form (duplicate of a fixture) so we never delete real data.
async function makeFormWithSubmissions(request: any, n: number): Promise<{ id: number; handle: string }> {
  const resp = await actionJson(request, 'easy-form/forms/duplicate', {
    id: formId('e2eContact'),
    newName: 'Del Orphan ' + Date.now() + '-' + Math.random().toString(36).slice(2, 7),
  });
  expect(resp.success).toBe(true);
  const handle = formHandleById(resp.formId);
  seedSubmissions(handle, n);
  expect(submissionCount(handle)).toBe(n);
  // All start out attached to the form.
  expect(submissionCountWhere(handle, 'formId IS NOT NULL')).toBe(n);
  return { id: resp.formId, handle };
}

test('CP: deleting a form keeps its submissions (orphaned by default)', async ({ request }) => {
  const { id, handle } = await makeFormWithSubmissions(request, 3);
  try {
    const resp = await actionJson(request, 'easy-form/forms/delete', { id, deleteSubmissions: 0 });
    expect(resp.success).toBe(true);
    expect(resp.submissionsDeleted).toBe(0);

    // Form is gone…
    expect(formId(handle)).toBeFalsy();
    // …but every submission survives, now orphaned (formId → NULL).
    expect(submissionCount(handle)).toBe(3);
    expect(submissionCountWhere(handle, 'formId IS NULL')).toBe(3);
  } finally {
    deleteSubmissions(handle); // form already deleted; clear the orphans
  }
});

test('CP: opting in (deleteSubmissions) hard-deletes the submissions too', async ({ request }) => {
  const { id, handle } = await makeFormWithSubmissions(request, 3);
  try {
    const resp = await actionJson(request, 'easy-form/forms/delete', { id, deleteSubmissions: 1 });
    expect(resp.success).toBe(true);
    expect(resp.submissionsDeleted).toBe(3);

    expect(formId(handle)).toBeFalsy();
    expect(submissionCount(handle)).toBe(0);
  } finally {
    deleteForm(handle); // idempotent safety net
    deleteSubmissions(handle);
  }
});

test('CP: the delete modal explains submissions are kept and offers an opt-in cascade', async ({ page, request }) => {
  // A throwaway form with submissions so the modal shows the count + cascade.
  const { id, handle } = await makeFormWithSubmissions(request, 2);
  try {
    await page.goto(`/cp/easy-form/forms?search=${handle}`);
    const row = page.locator(`.ef-table tbody tr[data-id="${id}"]`);
    await row.hover();
    await row.locator('.ef-delete').click();

    const overlay = page.locator('#ef-delete-overlay');
    await expect(overlay).toBeVisible();

    // The info box must say submissions are KEPT — never the old false claim
    // that deleting the form deletes them.
    const info = await page.locator('#ef-delete-info-text').innerText();
    expect(info).toMatch(/kept/i);
    expect(info).toMatch(/Orphaned/i);
    expect(await overlay.innerText()).not.toMatch(/also deletes (its|their) submissions/i);

    // Opt-in cascade is present and OFF by default.
    const cascade = page.locator('#ef-delete-cascade');
    await expect(cascade).toBeVisible();
    await expect(cascade).not.toBeChecked();

    // Export-first escape hatch is offered.
    await expect(page.locator('#ef-delete-export')).toBeVisible();

    // Cancel makes no changes.
    await page.locator('#ef-delete-cancel').click();
    await expect(overlay).toBeHidden();
    expect(formId(handle)).toBeTruthy();
    expect(submissionCount(handle)).toBe(2);
  } finally {
    deleteForm(handle);
    deleteSubmissions(handle);
  }
});

test('CP: the modal hides the cascade/export for a no-subs form and never shows stale values', async ({ page, request }) => {
  // One throwaway form with submissions, one without.
  const withSubs = await makeFormWithSubmissions(request, 2);
  const noSubsResp = await actionJson(request, 'easy-form/forms/duplicate', {
    id: formId('e2eContact'),
    newName: 'Del NoSubs ' + Date.now(),
  });
  const noSubsHandle = formHandleById(noSubsResp.formId);
  try {
    await page.goto('/cp/easy-form/forms');

    async function openRow(id: number) {
      const row = page.locator(`.ef-table tbody tr[data-id="${id}"]`);
      await row.hover();
      await row.locator('.ef-delete').click();
    }

    // Form WITH submissions: cascade + export shown.
    await openRow(withSubs.id);
    await expect(page.locator('#ef-delete-cascade-wrap')).toBeVisible();
    await expect(page.locator('#ef-delete-export-wrap')).toBeVisible();
    await page.locator('#ef-delete-cancel').click();

    // Switching to a form with NO submissions must hide both and not leak the
    // previous form's label (the original CSS/`hidden` + stale-text bug).
    await openRow(noSubsResp.formId);
    await expect(page.locator('#ef-delete-cascade-wrap')).toBeHidden();
    await expect(page.locator('#ef-delete-export-wrap')).toBeHidden();
    await expect(page.locator('#ef-delete-cascade-label')).toHaveText('');
    await expect(page.locator('#ef-delete-info-text')).toHaveText(/no submissions/i);
  } finally {
    deleteForm(withSubs.handle);
    deleteForm(noSubsHandle);
    deleteSubmissions(withSubs.handle);
  }
});

test('CP: a single delete shows a named notice and removes the row in place', async ({ page, request }) => {
  // Two sibling forms so the table still has a row after one is deleted
  // (otherwise the now-empty table reloads and the notice can't be observed).
  const pre = 'DelNotice' + Date.now();
  const a = await actionJson(request, 'easy-form/forms/duplicate', { id: formId('e2eContact'), newName: pre + 'A' });
  const b = await actionJson(request, 'easy-form/forms/duplicate', { id: formId('e2eContact'), newName: pre + 'B' });
  const ha = formHandleById(a.formId);
  const hb = formHandleById(b.formId);
  try {
    await page.goto('/cp/easy-form/forms?search=' + pre);
    const rowA = page.locator(`.ef-table tbody tr[data-id="${a.formId}"]`);
    await expect(rowA).toBeVisible();
    await rowA.hover();
    await rowA.locator('.ef-delete').click();
    await page.locator('#ef-delete-confirm').click();

    // Craft notice (bottom-left toast) names the deleted form…
    await expect(page.getByText(pre + 'A” deleted', { exact: false }).first()).toBeVisible();
    // …the row is gone in place, and the sibling remains (no full reload).
    await expect(rowA).toHaveCount(0);
    await expect(page.locator(`.ef-table tbody tr[data-id="${b.formId}"]`)).toBeVisible();
  } finally {
    deleteForm(ha);
    deleteForm(hb);
  }
});

test('CP: orphaned submissions are reachable via the "Orphaned (deleted forms)" filter', async ({ page, request }) => {
  const { id, handle } = await makeFormWithSubmissions(request, 2);
  let orphaned = false;
  try {
    // Delete the form (default: orphan its submissions).
    const resp = await actionJson(request, 'easy-form/forms/delete', { id, deleteSubmissions: 0 });
    expect(resp.success).toBe(true);
    orphaned = true;
    expect(submissionCountWhere(handle, 'formId IS NULL')).toBe(2);

    // The filter option exists and selecting it lists the orphaned rows.
    await page.goto('/cp/easy-form/submissions?formId=orphaned');
    await expect(page.locator('#ef-form option[value="orphaned"]')).toHaveCount(1);
    // Orphaned rows render with the "form deleted" marker, not a live form link.
    await expect(page.locator('.ef-cell-form', { hasText: /form deleted/i }).first()).toBeVisible();
  } finally {
    if (!orphaned) deleteForm(handle);
    deleteSubmissions(handle);
  }
});

test('CP: bulk delete also respects the deleteSubmissions opt-in per form', async ({ request }) => {
  const a = await makeFormWithSubmissions(request, 2);
  const b = await makeFormWithSubmissions(request, 2);
  try {
    // Default bulk delete: both forms gone, all 4 submissions orphaned.
    let resp = await actionJson(request, 'easy-form/forms/bulk', {
      bulkAction: 'delete',
      ids: [a.id],
      deleteSubmissions: 0,
    });
    expect(resp.success).toBe(true);
    expect(formId(a.handle)).toBeFalsy();
    expect(submissionCountWhere(a.handle, 'formId IS NULL')).toBe(2);

    // Opt-in bulk delete: form gone AND submissions hard-deleted.
    resp = await actionJson(request, 'easy-form/forms/bulk', {
      bulkAction: 'delete',
      ids: [b.id],
      deleteSubmissions: 1,
    });
    expect(resp.success).toBe(true);
    expect(formId(b.handle)).toBeFalsy();
    expect(submissionCount(b.handle)).toBe(0);
  } finally {
    deleteForm(a.handle);
    deleteForm(b.handle);
    deleteSubmissions(a.handle);
    deleteSubmissions(b.handle);
  }
});
