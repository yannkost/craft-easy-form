import { test, expect } from '@playwright/test';
import { apiSubmit, actionJson } from './submit';
import { formId } from './db';
import { processQueue } from './queue';

const JSON_HEADERS = { Accept: 'application/json' };

// CSV export is an authenticated CP action and is now **always queued**: POST to
// enqueue → run the queue → poll the status endpoint → download via token.
test('CP: queued CSV export builds a file with the header and the submitted row', async ({ request }) => {
  const fid = formId('e2ePrune');
  const marker = 'CsvMarker' + Date.now().toString().slice(-6);

  await apiSubmit(request, {
    formId: fid,
    fields: { name: marker, email: `${marker.toLowerCase()}@example.test` },
  });

  // Queue the export.
  const resp = await actionJson(request, 'easy-form/submissions/export', { formId: fid });
  expect(resp.success).toBe(true);
  const token = resp.token as string;
  expect(token).toBeTruthy();

  // Run the queue so the export job builds the file, then poll until ready.
  processQueue();
  let ready = false;
  for (let i = 0; i < 15 && !ready; i++) {
    const check = await (
      await request.get(`/actions/easy-form/submissions/export-check?key=${encodeURIComponent(token)}`, { headers: JSON_HEADERS })
    ).json();
    if (check.failed) throw new Error('export failed: ' + (check.message || ''));
    ready = !!check.ready;
    if (!ready) await new Promise((r) => setTimeout(r, 1000));
  }
  expect(ready, 'export should become ready after the queue runs').toBe(true);

  // Download via the tokenized endpoint.
  const dl = await request.get(`/actions/easy-form/submissions/export-download?key=${encodeURIComponent(token)}`);
  expect(dl.ok()).toBeTruthy();
  expect(dl.headers()['content-type']).toContain('text/csv');

  const csv = await dl.text();
  const header = csv.split('\n')[0];
  expect(header).toContain('Name');
  expect(header).toContain('Email');
  expect(csv).toContain(marker);
});
