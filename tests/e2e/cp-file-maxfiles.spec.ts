import { test, expect } from '@playwright/test';
import { newForm, addRow, addField, setBasics, fieldTab, closeField, save } from './builder';
import { apiSubmitWithFiles } from './submit';
import { formId, deleteForm } from './db';

// "Max number of files" (only meaningful with Allow Multiple) is enforced
// server-side with a translatable message.
test.describe('file upload: max number of files', () => {
  test.describe.configure({ mode: 'serial' });
  const handle = 'fileMaxFiles';
  const file = (name: string) => ({ name, mimeType: 'text/plain', buffer: Buffer.from('x') });

  test.afterAll(() => deleteForm(handle));

  test('rejects more files than the configured maximum', async ({ page, request }) => {
    await newForm(page, 'File Max', handle);
    const row = await addRow(page);
    const f = await addField(row, 'file');
    await setBasics(f, 'Docs', 'docs');
    await fieldTab(f, 'validation');
    // Allow multiple, cap at 2.
    const sw = f.locator('.validation-file-fields .lightswitch').first();
    await sw.click();
    await expect(sw).toHaveClass(/(^|\s)on(\s|$)/);
    await f.locator('input[name$="[maxFiles]"]').fill('2');
    await closeField(f);
    await save(page);

    const fid = formId(handle);

    // Three files → rejected with the count message (keyed to the field).
    const tooMany = await apiSubmitWithFiles(request, {
      formId: fid,
      handle: 'docs',
      files: [file('a.txt'), file('b.txt'), file('c.txt')],
    });
    expect(tooMany.success).toBe(false);
    expect(tooMany.errors?.docs?.[0]).toMatch(/no more than 2/i);

    // Two files → accepted.
    const ok = await apiSubmitWithFiles(request, {
      formId: fid,
      handle: 'docs',
      files: [file('a.txt'), file('b.txt')],
    });
    expect(ok.success).toBe(true);
  });
});
