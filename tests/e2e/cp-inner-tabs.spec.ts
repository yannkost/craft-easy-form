import { test, expect } from '@playwright/test';
import { gotoFormByHandle } from './helpers';

test('CP: page pane inner tabs (Fields/Labels/Sites) switch content', async ({ page }) => {
  await gotoFormByHandle(page, 'e2eMultipage');

  const pane = page.locator('.page-pane[data-page-pane="0"]');
  const nav = pane.locator('.ef-page-inner-tabs');
  await expect(nav.locator('.ef-tab', { hasText: 'Fields' })).toBeVisible();
  await expect(nav.locator('.ef-tab', { hasText: 'Labels' })).toBeVisible();
  await expect(nav.locator('.ef-tab', { hasText: 'Sites' })).toBeVisible();

  // Default: Fields pane visible, others hidden.
  await expect(pane.locator('.page-inner-pane[data-inner-pane="fields"]')).toBeVisible();
  await expect(pane.locator('.page-inner-pane[data-inner-pane="labels"]')).toBeHidden();
  await expect(pane.locator('input[name="pages[0][nextLabel]"]')).toBeHidden();

  // Switch to Labels: nav-label input becomes visible.
  await nav.locator('.ef-tab', { hasText: 'Labels' }).click();
  await expect(pane.locator('input[name="pages[0][nextLabel]"]')).toBeVisible();
  await expect(pane.locator('.page-inner-pane[data-inner-pane="fields"]')).toBeHidden();

  // Switch to Sites: site-enable lightswitch becomes visible.
  await nav.locator('.ef-tab', { hasText: 'Sites' }).click();
  await expect(pane.locator('.page-inner-pane[data-inner-pane="sites"]')).toBeVisible();
  await expect(pane.locator('input[name="pages[0][nextLabel]"]')).toBeHidden();
});
