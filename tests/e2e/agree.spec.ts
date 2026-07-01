import { test, expect } from '@playwright/test';

// The agree field splices an admin-configured link into admin-configured label
// text. Hostile values must be escaped, and javascript: URLs dropped.
test('agree field escapes label HTML and drops a javascript: link URL', async ({ page }) => {
  await page.goto('/dev/form?f=e2eAgreeXss');

  const agree = page.locator('.easy-form-field[data-field-handle="agreeField"]');
  await expect(agree).toBeVisible();

  // The <script> from agreeText must NOT exist as a real element.
  await expect(page.locator('.checkbox-text script')).toHaveCount(0);
  // It should be visible as escaped text instead.
  await expect(agree.locator('.checkbox-text')).toContainText('<script>alert(1)</script>');

  // The javascript: scheme is not allowed, so the "Terms" link is dropped.
  await expect(agree.locator('a[href^="javascript:"]')).toHaveCount(0);
  await expect(agree.locator('a', { hasText: 'Terms' })).toHaveCount(0);

  // The benign second link in the per-site list still renders — the list
  // supports multiple links and a bad one doesn't suppress the good ones.
  const privacy = agree.locator('a', { hasText: 'Privacy' });
  await expect(privacy).toHaveCount(1);
  await expect(privacy).toHaveAttribute('href', '/privacy');

  // Single id on the checkbox input.
  const ids = await agree.locator('input[type="checkbox"]').evaluate((el) => el.getAttributeNames().filter((n) => n === 'id').length);
  expect(ids).toBe(1);
});
