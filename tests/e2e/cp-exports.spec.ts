import { test, expect } from '@playwright/test';
import { readFileSync } from 'fs';
import { apiSubmit } from './submit';
import { formId } from './db';
import { processQueue } from './queue';

// Exports are always queued: the modal POSTs, the browser lands on the status
// page, and once the queue runs the page auto-downloads. Drive that flow and
// return the downloaded CSV text.
async function downloadAfterExport(page: import('@playwright/test').Page): Promise<string> {
  await expect(page).toHaveURL(/exports\/status/);
  const downloadPromise = page.waitForEvent('download');
  processQueue(); // build the queued export file
  const download = await downloadPromise;
  return readFileSync(await download.path(), 'utf8');
}

// The Exports page lists every form; a per-form modal applies site/status/date
// filters and downloads a CSV. Default site = 1, French site = 2.
test('CP: exports modal downloads a CSV, and the site filter scopes the rows', async ({ page, request }) => {
  const email = `export-${Date.now()}-${Math.floor(Math.random() * 1e6)}@example.test`;
  // A submission on the default site (siteId 1).
  const res = await apiSubmit(request, {
    formId: formId('e2eContact'),
    siteId: '1',
    fields: { name: 'Exporter', email },
  });
  expect(res.success).toBe(true);

  // Self-contained: queueing navigates to the status page, so re-open the
  // exports list each time. Search so the form is on the first page.
  async function exportWithSite(siteValue: string): Promise<string> {
    await page.goto('/cp/easy-form/exports?search=e2eContact');
    const row = page.locator('.ef-table tbody tr').filter({ hasText: 'e2eContact' }).first();
    await expect(row).toBeVisible();
    await row.locator('.ef-modal-open[data-mode="export"]').click();
    await expect(page.locator('#ef-modal')).toBeVisible();
    await page.locator('#ef-f-site').selectOption(siteValue);
    await page.locator('#ef-do-export').click();
    return downloadAfterExport(page);
  }

  // Default site (1) → the row is present.
  const defaultCsv = await exportWithSite('1');
  expect(defaultCsv).toContain('ID,Status,'); // header
  expect(defaultCsv).toContain(email);

  // French site (2) → the default-site submission must be filtered out.
  const frenchCsv = await exportWithSite('2');
  expect(frenchCsv).toContain('ID,Status,');
  expect(frenchCsv).not.toContain(email);
});

test('CP: CSV export neutralizes spreadsheet formula injection', async ({ page, request }) => {
  const token = 'INJ' + Date.now();
  const res = await apiSubmit(request, {
    formId: formId('e2eContact'),
    siteId: '1',
    fields: { name: 'Inj', email: `inj-${token}@example.test`, message: `=SUM(${token})` },
  });
  expect(res.success).toBe(true);

  await page.goto('/cp/easy-form/exports?search=e2eContact');
  const row = page.locator('.ef-table tbody tr').filter({ hasText: 'e2eContact' }).first();
  await row.locator('.ef-modal-open[data-mode="export"]').click();
  await page.locator('#ef-f-site').selectOption('1');
  await page.locator('#ef-do-export').click();
  const csv = await downloadAfterExport(page);

  // The formula was written as text (single-quote prefixed), not executable.
  expect(csv).toContain(`'=SUM(${token})`);
});

test('CP: exports page has working search and pagination', async ({ page }) => {
  await page.goto('/cp/easy-form/exports');
  await expect(page.locator('.ef-table')).toBeVisible();
  // Many seeded forms, 20 per page → pagination is present.
  await expect(page.locator('.ef-pagination')).toBeVisible();

  // Searching narrows the list (and drops pagination for the small result).
  await page.fill('.ef-search input', 'e2eContact');
  await page.locator('.ef-search input').press('Enter');
  await expect(page).toHaveURL(/[?&]search=e2eContact/);
  await expect(page.locator('.ef-table tbody tr').filter({ hasText: 'e2eContact' }).first()).toBeVisible();
  await expect(page.locator('.ef-pagination')).toHaveCount(0);
});
