import { test, expect } from '@playwright/test';
import { readFileSync } from 'fs';
import { apiSubmit, actionJson } from './submit';
import { formId } from './db';
import { processQueue } from './queue';

const JSON_HEADERS = { Accept: 'application/json' };

// The Submissions-page export must honor the active search filter (matching the
// list's primaryName / primaryEmail search), so the CSV reflects what's on
// screen rather than the whole form.
test.describe('export honors the search filter', () => {
  const fid = () => formId('e2eContact');
  const stamp = Date.now().toString().slice(-6);
  const hitName = `ZzExportHit${stamp}`;
  const hitEmail = `hit-${stamp}@example.test`;
  const missEmail = `miss-${stamp}@example.test`;

  test.beforeAll(async ({ request }) => {
    // One row that matches the search, one that doesn't.
    expect((await apiSubmit(request, { formId: fid(), fields: { name: hitName, email: hitEmail } })).success).toBe(true);
    expect((await apiSubmit(request, { formId: fid(), fields: { name: `Control${stamp}`, email: missEmail } })).success).toBe(true);
  });

  test('a search-scoped export includes only matching rows (API path)', async ({ request }) => {
    const resp = await actionJson(request, 'easy-form/submissions/export', { formId: fid(), search: hitName });
    expect(resp.success).toBe(true);
    const token = resp.token as string;

    let ready = false;
    for (let i = 0; i < 15 && !ready; i++) {
      processQueue();
      const check = await (
        await request.get(`/actions/easy-form/submissions/export-check?key=${encodeURIComponent(token)}`, { headers: JSON_HEADERS })
      ).json();
      if (check.failed) throw new Error('export failed: ' + (check.message || ''));
      ready = !!check.ready;
      if (!ready) await new Promise((r) => setTimeout(r, 1000));
    }
    expect(ready).toBe(true);

    const dl = await request.get(`/actions/easy-form/submissions/export-download?key=${encodeURIComponent(token)}`);
    const csv = await dl.text();
    expect(csv).toContain(hitEmail);
    expect(csv).not.toContain(missEmail);
  });

  test('the Submissions toolbar export carries the active search (UI path)', async ({ page }) => {
    await page.goto(`/cp/easy-form/submissions?formId=${fid()}&search=${hitName}`);
    const exportBtn = page.locator('#ef-export-csv');
    await expect(exportBtn).toHaveAttribute('data-search', hitName);

    // Export now opens a modal (infobox + column picker) and downloads in the
    // background, keeping the user on the page with their filters intact.
    await exportBtn.click();
    await expect(page.locator('#ef-export-modal')).toBeVisible();
    await expect(page.locator('#ef-export-cols-list input[type=checkbox]').first()).toBeVisible();

    const downloadPromise = page.waitForEvent('download', { timeout: 30000 });
    await page.locator('#ef-export-go').click();
    await expect(page.locator('#ef-export-modal')).toBeHidden(); // job queued
    processQueue();                                              // run it; page polls + downloads
    const download = await downloadPromise;
    const csv = readFileSync(await download.path(), 'utf8');
    expect(csv).toContain(hitEmail);
    expect(csv).not.toContain(missEmail);
  });
});
