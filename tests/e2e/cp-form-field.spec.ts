import { test, expect } from '@playwright/test';
import { actionJson } from './submit';
import { craftFieldRow } from './db';

// The plugin ships a native Craft field type (fields/FormField.php) so a form can
// be picked from any element's field layout. The end-to-end value round-trip
// (getInputHtml → serializeValue → normalizeValue on a saved entry) needs the
// field attached to an entry type's layout — and Craft's layout designer is
// drag-and-drop, which headless Chromium can't drive. What we CAN verify here,
// robustly and without DnD, is that the type is registered and persists through
// Craft's own field pipeline: createField($type) resolves our class and
// saveField() stores it. That covers the most upgrade-fragile part (registration
// via EVENT_REGISTER_FIELD_TYPES + the displayName/icon contract).

const FIELD_CLASS = 'yannkost\\easyform\\fields\\FormField';

test('CP: the easy-form Form field type registers and round-trips through Craft', async ({ request }) => {
  const handle = 'e2eFormPicker' + Date.now().toString().slice(-6);

  // Create the field through Craft's fields/save-field action (AJAX → JSON).
  const saved = await actionJson(request, 'fields/save-field', {
    type: FIELD_CLASS,
    name: 'E2E Form Picker',
    handle,
  });
  expect(saved.message, JSON.stringify(saved)).toMatch(/saved/i);

  const row = craftFieldRow(handle);
  try {
    // It persisted, typed as our class — proving the type is a registered,
    // saveable Craft field, not just a loose class reference.
    expect(row).not.toBeNull();
    // `mysql -N` escapes backslashes in its output, so collapse `\\` → `\`.
    expect(row!.type.replace(/\\\\/g, '\\')).toBe(FIELD_CLASS);
  } finally {
    if (row) {
      const del = await actionJson(request, 'fields/delete-field', { id: row.id });
      expect(del.message, JSON.stringify(del)).toMatch(/deleted/i);
    }
  }

  // Cleanup removed it.
  expect(craftFieldRow(handle)).toBeNull();
});
