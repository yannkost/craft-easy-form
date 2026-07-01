import { test, expect } from '@playwright/test';
import { actionPost } from './submit';
import { formId, formLayoutText, newestFormIdByName, formHandleById, deleteForm } from './db';

// Form export (JSON) → import round-trip, both authenticated CP actions.
test('CP: a form exported as JSON can be re-imported, preserving its fields', async ({ request }) => {
  // Export the seeded gallery form (covers every field type).
  const exportRes = await request.get(`/actions/easy-form/forms/export?formId=${formId('galleryAllFields')}`);
  expect(exportRes.ok()).toBeTruthy();
  expect(exportRes.headers()['content-type']).toContain('application/json');

  const payload = await exportRes.json();
  expect(payload.form.handle).toBe('galleryAllFields');
  const handles = payload.form.fieldLayout.pages
    .flatMap((p: any) => p.rows).flatMap((r: any) => r.fields).map((f: any) => f.handle);
  expect(handles).toContain('selectField');
  expect(handles).toContain('checkboxesField');

  // Re-import via the pasted-definition path under a unique name, so this test
  // finds and cleans up exactly its own import (parallel-safe).
  const importName = `Gallery Import ${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
  payload.form.name = importName;
  const status = await actionPost(request, 'easy-form/forms/import', { definition: JSON.stringify(payload) });
  expect(status).toBeLessThan(400);

  const newId = newestFormIdByName(importName);
  expect(newId).not.toBe('');
  const importedHandle = formHandleById(newId);
  expect(importedHandle).not.toBe('galleryAllFields');

  // The layout round-tripped the field handles. Then clean up.
  const layout = formLayoutText(importedHandle);
  expect(layout).toContain('selectField');
  expect(layout).toContain('checkboxesField');

  deleteForm(importedHandle);
});
