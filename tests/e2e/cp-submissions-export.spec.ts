import { test, expect } from '@playwright/test';
import { readFileSync } from 'fs';
import { formId } from './db';
import { processQueue } from './queue';

// The Submissions page shows the result count next to Export, and the export
// runs through a modal: an infobox describing exactly what's exported (the
// active list filters) plus a column picker, so the CSV isn't a surprise and
// the user chooses the fields rather than getting a fixed/blank set.

const fid = () => formId('e2eContact');

test('shows the result count next to the export button', async ({ page }) => {
  await page.goto('/cp/easy-form/submissions?formId=' + fid());
  await expect(page.locator('.ef-result-count')).toBeVisible();
  await expect(page.locator('.ef-result-count')).toHaveText(/\d+ results?|1 result/);
});

test('the export modal describes the active filters and offers a column picker', async ({ page }) => {
  await page.goto('/cp/easy-form/submissions?formId=' + fid() + '&status=approved');
  await page.locator('#ef-export-csv').click();

  const modal = page.locator('#ef-export-modal');
  await expect(modal).toBeVisible();
  // Infobox names what's exported and the active filters.
  await expect(page.locator('.ef-export-summary')).toHaveText(/matching the current view/i);
  await expect(page.locator('.ef-export-filters')).toContainText('E2E Contact');
  await expect(page.locator('.ef-export-filters')).toContainText('Approved');

  // Column picker loads, with select-all / none.
  const boxes = page.locator('#ef-export-cols-list input[type=checkbox]');
  await expect(boxes.first()).toBeVisible();
  const n = await boxes.count();
  expect(n).toBeGreaterThan(0);
  await page.locator('[data-cols-none]').click();
  await expect(page.locator('#ef-export-cols-list input:checked')).toHaveCount(0);
  await page.locator('[data-cols-all]').click();
  await expect(page.locator('#ef-export-cols-list input:checked')).toHaveCount(n);

  // The label row fills the picker width (Craft CP otherwise shrink-wraps it to
  // a few px, wrapping the text); the text column gets the resting width.
  const labelW = await page.locator('#ef-export-cols-list .ef-col-check').first().evaluate((el) => el.getBoundingClientRect().width);
  expect(labelW).toBeGreaterThan(300);
});

test('exports only the picked columns (not a fixed/blank set)', async ({ page }) => {
  await page.goto('/cp/easy-form/submissions?formId=' + fid());
  await page.locator('#ef-export-csv').click();
  await expect(page.locator('#ef-export-modal')).toBeVisible();

  const boxes = page.locator('#ef-export-cols-list input[type=checkbox]');
  await expect(boxes.first()).toBeVisible();
  // Keep exactly one column.
  await page.locator('[data-cols-none]').click();
  const firstLabel = (await page.locator('#ef-export-cols-list .ef-col-check').first().innerText()).trim();
  await boxes.first().check();

  const downloadPromise = page.waitForEvent('download', { timeout: 30000 });
  await page.locator('#ef-export-go').click();
  await expect(page.locator('#ef-export-modal')).toBeHidden();
  processQueue();
  const download = await downloadPromise;

  const csv = readFileSync(await download.path(), 'utf8');
  const header = csv.split(/\r?\n/)[0];
  // Header has just the one picked column.
  expect(header).toContain(firstLabel);
  expect(header.split(',').length).toBe(1);
});
