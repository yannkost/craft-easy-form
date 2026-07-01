import { test, expect } from '@playwright/test';
import { apiSubmitWithFile, apiSubmitWithFiles } from './submit';
import { formId, uploadFileCount } from './db';

// The e2eFileUpload form's "document" field caps size at 1MB and only allows
// .txt. Uploads are validated server-side regardless of the client.

test.describe('File upload validation', () => {
  let fid: string;
  test.beforeAll(() => { fid = formId('e2eFileUpload'); });

  test('rejects a file over the size limit', async ({ request }) => {
    const res = await apiSubmitWithFile(request, {
      formId: fid,
      fields: { name: 'Joe' },
      file: { handle: 'document', name: 'big.txt', mimeType: 'text/plain', buffer: Buffer.alloc(2 * 1024 * 1024, 0x61) },
    });
    expect(res.success).toBe(false);
    expect(JSON.stringify(res.errors)).toMatch(/exceeds maximum size|too (big|large)/i);
  });

  test('rejects a disallowed file type', async ({ request }) => {
    const res = await apiSubmitWithFile(request, {
      formId: fid,
      fields: { name: 'Joe' },
      file: { handle: 'document', name: 'data.csv', mimeType: 'text/csv', buffer: Buffer.from('a,b,c\n1,2,3\n') },
    });
    expect(res.success).toBe(false);
    expect(JSON.stringify(res.errors)).toMatch(/not allowed|invalid file type/i);
  });

  test('accepts an allowed file within the size limit', async ({ request }) => {
    const res = await apiSubmitWithFile(request, {
      formId: fid,
      fields: { name: 'Joe' },
      file: { handle: 'document', name: 'notes.txt', mimeType: 'text/plain', buffer: Buffer.from('hello world') },
    });
    expect(res.success).toBe(true);
    expect(res.submissionId).toBeGreaterThan(0);
  });
});

// A submission that fails validation after a (valid) file is stored must not
// leave the file behind — the finally block cleans it up. (#1)
test.describe('Upload orphan cleanup', () => {
  test('a failed submission removes its uploaded file; a successful one keeps it', async ({ request }) => {
    const fid = formId('e2eUploadCleanup');

    // Missing the required `name` → fails validation after the file is written.
    const token = 'orphan' + Date.now();
    const fail = await apiSubmitWithFile(request, {
      formId: fid,
      fields: {},
      file: { handle: 'doc', name: `${token}.txt`, mimeType: 'text/plain', buffer: Buffer.from('orphan') },
    });
    expect(fail.success).toBe(false);
    expect(uploadFileCount(token)).toBe(0);

    // Control: a valid submission keeps its file.
    const keptToken = 'kept' + Date.now();
    const ok = await apiSubmitWithFile(request, {
      formId: fid,
      fields: { name: 'Keeper' },
      file: { handle: 'doc', name: `${keptToken}.txt`, mimeType: 'text/plain', buffer: Buffer.from('keep') },
    });
    expect(ok.success).toBe(true);
    expect(uploadFileCount(keptToken)).toBe(1);
  });
});

// allowMultiple: a single file field accepts several files in one submission,
// and every one is stored. (Runs in whatever upload mode the instance is set to —
// filesystem here; Asset mode is a global switch, exercised on its own instance.)
test.describe('Multiple-file upload', () => {
  test('a single field with allowMultiple stores every uploaded file', async ({ request }) => {
    const token = 'multi' + Date.now();
    const res = await apiSubmitWithFiles(request, {
      formId: formId('e2eMultiFile'),
      fields: { name: 'Multi' },
      handle: 'docs',
      files: [
        { name: `${token}-1.txt`, mimeType: 'text/plain', buffer: Buffer.from('one') },
        { name: `${token}-2.txt`, mimeType: 'text/plain', buffer: Buffer.from('two') },
      ],
    });
    expect(res.success).toBe(true);
    expect(uploadFileCount(token)).toBe(2);
  });

  test('a disallowed type among multiple files rejects the whole submission', async ({ request }) => {
    const token = 'multibad' + Date.now();
    const res = await apiSubmitWithFiles(request, {
      formId: formId('e2eMultiFile'),
      fields: { name: 'Multi' },
      handle: 'docs',
      files: [
        { name: `${token}-ok.txt`, mimeType: 'text/plain', buffer: Buffer.from('ok') },
        { name: `${token}-bad.csv`, mimeType: 'text/csv', buffer: Buffer.from('a,b') },
      ],
    });
    expect(res.success).toBe(false);
    // The whole submission fails, leaving no files behind (orphan cleanup).
    expect(uploadFileCount(token)).toBe(0);
  });
});
