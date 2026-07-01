import { type Page, expect } from '@playwright/test';

/**
 * Navigate to a form's CP edit page by its handle.
 *
 * Form IDs are not stable across re-seeds (seed-forms deletes + recreates each
 * fixture, bumping auto-increment IDs), so tests resolve the current ID from the
 * forms index — where each row carries data-handle / data-url — instead of
 * hardcoding it.
 */
export async function gotoFormByHandle(page: Page, handle: string): Promise<void> {
  // The index paginates (20/page) and supports a name/handle search, so filter to
  // the target rather than assuming it lands on the first page.
  await page.goto(`/cp/easy-form/forms?search=${encodeURIComponent(handle)}`);
  const row = page.locator(`tr[data-handle="${handle}"]`);
  await expect(row).toHaveCount(1);
  const url = await row.getAttribute('data-url');
  if (!url) throw new Error(`No edit URL for form handle "${handle}"`);
  await page.goto(url);
}
