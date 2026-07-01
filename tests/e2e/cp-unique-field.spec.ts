import { test, expect } from '@playwright/test';
import { newForm, addRow, addField, setBasics, fieldTab, closeField, save } from './builder';
import { apiSubmit } from './submit';
import { formId, deleteForm } from './db';

// A field marked "Must be unique" rejects a second submission with the same value.
test.describe('unique field constraint', () => {
  test.describe.configure({ mode: 'serial' });
  const handle = 'uniqueFieldForm';

  test.afterAll(() => deleteForm(handle));

  test('a duplicate value for a unique field is rejected server-side', async ({ page, request }) => {
    await newForm(page, 'Unique Field', handle);
    const row = await addRow(page);
    const field = await addField(row, 'email');
    await setBasics(field, 'Email', 'email');
    await fieldTab(field, 'validation');
    const sw = field.locator('.validation-unique-field .lightswitch').first();
    await sw.click();
    await expect(sw).toHaveClass(/(^|\s)on(\s|$)/);
    await closeField(field);
    await save(page);

    const fid = formId(handle);
    const email = `uniq-${Date.now()}@example.test`;

    // First submission with the value → accepted.
    const first = await apiSubmit(request, { formId: fid, fields: { email } });
    expect(first.success).toBe(true);

    // Same value again → rejected, with the error keyed to the field.
    const dup = await apiSubmit(request, { formId: fid, fields: { email } });
    expect(dup.success).toBe(false);
    expect(dup.errors?.email?.[0]).toMatch(/already been used/i);

    // A different value → accepted.
    const other = await apiSubmit(request, { formId: fid, fields: { email: `other-${Date.now()}@example.test` } });
    expect(other.success).toBe(true);
  });
});
