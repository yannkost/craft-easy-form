import { test, expect } from '@playwright/test';
import { actionJson } from './submit';
import { formId, formHandleById, formLayoutText, deleteForm } from './db';

// FormsController::actionDuplicate clones a form: a brand-new unique handle, but
// the full layout (pages/rows/fields/conditions) and behavior settings copied
// verbatim. The source form (e2eMultipage) has multiple pages with conditions,
// so an identical layout proves the deep copy.
test('CP: duplicating a form clones it with a new handle and preserves the layout', async ({ request }) => {
  const sourceHandle = 'e2eMultipage';
  const sourceId = formId(sourceHandle);
  expect(sourceId).toBeTruthy();
  const sourceLayout = formLayoutText(sourceHandle);

  const newName = 'Dup Multipage ' + Date.now();
  const resp = await actionJson(request, 'easy-form/forms/duplicate', { id: sourceId, newName });
  expect(resp.success).toBe(true);
  expect(resp.formId).toBeTruthy();

  const newHandle = formHandleById(resp.formId);
  try {
    // A distinct, auto-generated handle (never the source's).
    expect(newHandle).not.toBe('');
    expect(newHandle).not.toBe(sourceHandle);

    // The layout JSON is copied byte-for-byte (pages, rows, fields, conditions).
    expect(formLayoutText(newHandle)).toBe(sourceLayout);
  } finally {
    deleteForm(newHandle);
  }
});

// Duplicating twice from the same source must not collide on the generated
// handle — the controller appends a counter until the handle is unique.
test('CP: duplicating the same form twice yields two distinct handles', async ({ request }) => {
  const sourceId = formId('e2eContact');
  const newName = 'Dup Collide ' + Date.now();

  const first = await actionJson(request, 'easy-form/forms/duplicate', { id: sourceId, newName });
  const second = await actionJson(request, 'easy-form/forms/duplicate', { id: sourceId, newName });
  expect(first.success).toBe(true);
  expect(second.success).toBe(true);

  const h1 = formHandleById(first.formId);
  const h2 = formHandleById(second.formId);
  try {
    expect(h1).toBeTruthy();
    expect(h2).toBeTruthy();
    expect(h1).not.toBe(h2);
  } finally {
    deleteForm(h1);
    deleteForm(h2);
  }
});
