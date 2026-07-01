import { test, expect, type APIRequestContext, type Page } from '@playwright/test';
import { apiSubmit, actionJson } from './submit';
import { formId, deleteSubmissions, submissionCountWhere, seedSubmissions } from './db';
import { processQueue } from './queue';

// High-volume exercise of the Exports + Submissions screens. A mid-sized form
// (e2eBulk) is bulk-seeded with EASY_FORM_BULK_COUNT (default 30,000) rows via
// the dev console seeder, then we drive real exports and list filters and
// compare every result against the DB's own count.
//
// Prereq: the e2eBulk fixture must be seeded (it is, by `seed-forms`). Exports
// are queued, so a backend queue runner (processQueue → `ddev … queue/run`)
// must run for an export to build — these tests assert exactly that.

const HANDLE = 'e2eBulk';
const COUNT = parseInt(process.env.EASY_FORM_BULK_COUNT || '30000', 10);
const QUEUE_TIMEOUT = 180_000;
const JSON_HEADERS = { Accept: 'application/json' };

let fid: string;

function iso(d: Date): string {
  return d.toISOString().slice(0, 10);
}
function isoDaysAgo(n: number): string {
  const d = new Date();
  d.setUTCDate(d.getUTCDate() - n);
  return iso(d);
}

/** Data rows in a CSV (total lines minus the header; seeded data has no embedded newlines). */
function dataRows(csv: string): number {
  return Math.max(0, csv.split('\n').filter((l) => l.trim() !== '').length - 1);
}

/** Enqueue an export, run the queue, poll until ready, return the downloaded CSV. */
async function runExport(request: APIRequestContext, params: Record<string, string>): Promise<string> {
  const resp = await actionJson(request, 'easy-form/submissions/export', { formId: fid, ...params });
  expect(resp.success, JSON.stringify(resp)).toBe(true);
  const token = resp.token as string;
  expect(token).toBeTruthy();

  let ready = false;
  for (let i = 0; i < 30 && !ready; i++) {
    processQueue(QUEUE_TIMEOUT); // the backend runner that builds the queued file
    const check = await (
      await request.get(`/actions/easy-form/submissions/export-check?key=${encodeURIComponent(token)}`, { headers: JSON_HEADERS })
    ).json();
    if (check.failed) throw new Error('export failed: ' + (check.message || ''));
    ready = !!check.ready;
    if (!ready) await new Promise((r) => setTimeout(r, 1000));
  }
  expect(ready, 'export should be ready after the queue runs').toBe(true);

  const dl = await request.get(`/actions/easy-form/submissions/export-download?key=${encodeURIComponent(token)}`);
  expect(dl.ok()).toBeTruthy();
  expect(dl.headers()['content-type']).toContain('text/csv');
  return dl.text();
}

const rows = (page: Page) => page.locator('.ef-subs tbody tr[data-id]');

test.describe('High-volume exports & submissions', () => {
  // Serial: one worker shares the seeded dataset; afterAll tears it down once.
  test.describe.configure({ mode: 'serial', timeout: 240_000 });

  // Opt-in: this group bulk-seeds tens of thousands of rows, so it's skipped in
  // the normal suite. Run it deliberately with EASY_FORM_HIGH_VOLUME=1
  // (optionally EASY_FORM_BULK_COUNT=… to scale the dataset).
  test.skip(() => !process.env.EASY_FORM_HIGH_VOLUME, 'Set EASY_FORM_HIGH_VOLUME=1 to run (bulk-seeds rows).');

  test.beforeAll(() => {
    fid = formId(HANDLE);
    if (!fid) {
      throw new Error(`Form "${HANDLE}" is not seeded. Run: ddev exec php craft easy-form/dev/seed-forms`);
    }
    deleteSubmissions(HANDLE);
    seedSubmissions(HANDLE, COUNT, 180);
  });

  test.afterAll(() => deleteSubmissions(HANDLE));

  // ── Exports ──────────────────────────────────────────────────────────────

  test('export ALL: row count matches the DB and presentational fields are not columns', async ({ request }) => {
    const csv = await runExport(request, {});
    const header = csv.split('\n')[0];
    // Real fields are columns…
    expect(header).toContain('Name');
    expect(header).toContain('Email');
    expect(header).toContain('Department');
    expect(header).toContain('Rating');
    // …the heading (presentational) is not.
    expect(header).not.toContain('Your details');
    // Every live row is exported.
    expect(dataRows(csv)).toBe(submissionCountWhere(HANDLE));
  });

  test('export with a STATUS filter matches the DB count', async ({ request }) => {
    const expected = submissionCountWhere(HANDLE, "status='approved'");
    expect(expected).toBeGreaterThan(0);
    const csv = await runExport(request, { status: 'approved' });
    expect(dataRows(csv)).toBe(expected);
  });

  test('export with a DATE RANGE matches the DB count', async ({ request }) => {
    const from = isoDaysAgo(90);
    const to = isoDaysAgo(30);
    // Mirror the export's range semantics: [from 00:00:00, to+1day 00:00:00).
    const expected = submissionCountWhere(
      HANDLE,
      `dateCreated >= '${from}' AND dateCreated < DATE_ADD('${to}', INTERVAL 1 DAY)`,
    );
    expect(expected).toBeGreaterThan(0);
    const csv = await runExport(request, { dateFrom: from, dateTo: to });
    expect(dataRows(csv)).toBe(expected);
  });

  test('export scoped to a site with no rows yields a header-only CSV', async ({ request }) => {
    // All seeded rows are on the primary site (1); site 2 has none.
    const csv = await runExport(request, { siteId: '2' });
    expect(csv.split('\n')[0]).toContain('ID'); // header present
    expect(dataRows(csv)).toBe(0);
  });

  test('an export is queued: not ready until the backend runner processes it', async ({ request }) => {
    // Drain anything already queued so our job is the only one outstanding.
    processQueue(QUEUE_TIMEOUT);

    const resp = await actionJson(request, 'easy-form/submissions/export', { formId: fid });
    expect(resp.success).toBe(true);
    const token = resp.token as string;

    // JSON requests don't trigger Craft's auto-runner, so before we run the
    // queue the file must NOT exist yet — proving the work is truly deferred.
    const before = await (
      await request.get(`/actions/easy-form/submissions/export-check?key=${encodeURIComponent(token)}`, { headers: JSON_HEADERS })
    ).json();
    expect(before.ready).toBeFalsy();

    processQueue(QUEUE_TIMEOUT);
    let ready = false;
    for (let i = 0; i < 30 && !ready; i++) {
      const check = await (
        await request.get(`/actions/easy-form/submissions/export-check?key=${encodeURIComponent(token)}`, { headers: JSON_HEADERS })
      ).json();
      ready = !!check.ready;
      if (!ready) await new Promise((r) => setTimeout(r, 1000));
    }
    expect(ready, 'export becomes ready once the queue runner runs').toBe(true);
  });

  test('the exports delete-count endpoint matches the DB for a filter', async ({ request }) => {
    const expected = submissionCountWhere(HANDLE, "status='spam'");
    const resp = await actionJson(request, 'easy-form/exports/count', { formId: fid, status: 'spam' });
    expect(resp.success).toBe(true);
    expect(resp.count).toBe(expected);
  });

  test('the exports page UI downloads a CSV end to end', async ({ page }) => {
    await page.goto(`/cp/easy-form/exports?search=${HANDLE}`);
    const row = page.locator('.ef-table tbody tr').filter({ hasText: HANDLE }).first();
    await expect(row).toBeVisible();
    await row.locator('.ef-modal-open[data-mode="export"]').click();
    await expect(page.locator('#ef-modal')).toBeVisible();
    // Filter to one status to keep the UI download small and quick.
    await page.locator('#ef-f-status').selectOption('archived');
    const downloadPromise = page.waitForEvent('download');
    await page.locator('#ef-do-export').click();
    await expect(page).toHaveURL(/exports\/status/);
    processQueue(QUEUE_TIMEOUT);
    const download = await downloadPromise;
    expect(await download.path()).toBeTruthy();
  });

  // ── Submissions list ──────────────────────────────────────────────────────

  test('submissions list paginates and a deep page still loads', async ({ page }) => {
    await page.goto(`/cp/easy-form/submissions?formId=${fid}&limit=20`);
    await expect(page.locator('.ef-subs')).toBeVisible();
    await expect(rows(page)).toHaveCount(20);
    await expect(page.locator('.ef-pagination')).toBeVisible();

    // A deep page (≈ middle of 1,500) loads a full page of rows.
    await page.goto(`/cp/easy-form/submissions?formId=${fid}&limit=20&page=750`);
    await expect(rows(page)).toHaveCount(20);
  });

  test('search at volume returns exactly the matching rows', async ({ page, request }) => {
    const marker = 'ZzBulkMarker' + Date.now().toString().slice(-6);
    for (let i = 0; i < 3; i++) {
      const res = await apiSubmit(request, {
        formId: fid,
        fields: { name: `${marker} ${i}`, email: `${marker.toLowerCase()}-${i}@example.test` },
      });
      expect(res.success).toBe(true);
    }

    await page.goto(`/cp/easy-form/submissions?formId=${fid}&search=${marker}&limit=50`);
    await expect(rows(page)).toHaveCount(3);
    await expect(page.locator('.ef-subs')).toContainText(marker);

    // The marker submissions default to 'pending'; status + date filters narrow.
    await page.goto(`/cp/easy-form/submissions?formId=${fid}&search=${marker}&status=pending&limit=50`);
    await expect(rows(page)).toHaveCount(3);
    await page.goto(`/cp/easy-form/submissions?formId=${fid}&search=${marker}&status=spam&limit=50`);
    await expect(rows(page)).toHaveCount(0);
    await expect(page.locator('.ef-empty-state')).toBeVisible();
  });

  test('date filter narrows the list', async ({ page, request }) => {
    const marker = 'ZzDateMarker' + Date.now().toString().slice(-6);
    const res = await apiSubmit(request, {
      formId: fid,
      fields: { name: marker, email: `${marker.toLowerCase()}@example.test` },
    });
    expect(res.success).toBe(true);

    // Created "now" → today's lower bound includes it; an upper bound in the past excludes it.
    await page.goto(`/cp/easy-form/submissions?formId=${fid}&search=${marker}&dateFrom=${isoDaysAgo(0)}&limit=50`);
    await expect(rows(page)).toHaveCount(1);
    await page.goto(`/cp/easy-form/submissions?formId=${fid}&search=${marker}&dateTo=${isoDaysAgo(1)}&limit=50`);
    await expect(rows(page)).toHaveCount(0);
  });

  test('a search with no matches shows the empty state', async ({ page }) => {
    await page.goto(`/cp/easy-form/submissions?formId=${fid}&search=NoSuchSubmitter_${Date.now()}&limit=20`);
    await expect(rows(page)).toHaveCount(0);
    await expect(page.locator('.ef-empty-state')).toBeVisible();
  });
});
