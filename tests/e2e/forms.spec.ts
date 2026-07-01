import { test, expect, type Page } from '@playwright/test';

const form = (page: Page) => page.locator('form.easy-form');

test.describe('Frontend form rendering & submission', () => {
  test('contact form: required validation, then successful submit', async ({ page }) => {
    await page.goto('/dev/form?f=e2eContact');
    await expect(form(page)).toBeVisible();

    // Submit empty → client-side required validation fires.
    await form(page).locator('button[type=submit]').click();
    await expect(page.locator('.field-error').first()).toBeVisible();
    // Error is associated for screen readers.
    await expect(page.locator('input[name="fields[name]"]')).toHaveAttribute('aria-invalid', 'true');

    // Fill valid values and submit.
    await page.fill('input[name="fields[name]"]', 'Playwright Tester');
    await page.fill('input[name="fields[email]"]', 'pw@example.com');
    await page.fill('textarea[name="fields[message]"]', 'Hello from the e2e suite.');
    await form(page).locator('button[type=submit]').click();

    await expect(page.locator('.easy-form-message.success')).toBeVisible();
  });

  test('email field rejects an invalid address', async ({ page }) => {
    await page.goto('/dev/form?f=e2eContact');
    await page.fill('input[name="fields[name]"]', 'Bad Email');
    await page.fill('input[name="fields[email]"]', 'not-an-email');
    await form(page).locator('button[type=submit]').click();
    await expect(page.locator('.easy-form-field-email .field-error')).toBeVisible();
    await expect(page.locator('.easy-form-message.success')).toHaveCount(0);
  });

  test('multipage: stepper + conditional field shows for business', async ({ page }) => {
    await page.goto('/dev/form?f=e2eMultipage');
    await expect(form(page)).toBeVisible();

    // Only the first page is visible initially.
    await expect(page.locator('.easy-form-page').nth(1)).toBeHidden();

    await page.fill('input[name="fields[name]"]', 'Acme Inc');
    await page.selectOption('select[name="fields[type]"]', 'business');
    await page.locator('.easy-form-next').click();

    // Page 2 visible; the conditional "company" field shows for business.
    await expect(page.locator('.easy-form-page').nth(1)).toBeVisible();
    await expect(page.locator('.easy-form-field[data-field-handle="company"]')).toBeVisible();
  });

  test('multipage: returns to the first page after a successful submit', async ({ page }) => {
    await page.goto('/dev/form?f=e2eMultipage');

    // Page 1 → fill and advance.
    await page.fill('input[name="fields[name]"]', 'Jane Personal');
    await page.selectOption('select[name="fields[type]"]', 'personal');
    await page.locator('.easy-form-next').click();

    // Page 2 → fill required email and submit.
    await expect(page.locator('.easy-form-page').nth(1)).toBeVisible();
    await page.fill('input[name="fields[email]"]', 'jane@example.com');
    await form(page).locator('button[type=submit]').click();

    await expect(page.locator('.easy-form-message.success')).toBeVisible();
    // Form is cleared AND stepped back to page 1 (not left on the last page).
    await expect(page.locator('.easy-form-page').nth(0)).toBeVisible();
    await expect(page.locator('.easy-form-page').nth(1)).toBeHidden();
    await expect(page.locator('input[name="fields[name]"]')).toHaveValue('');
  });

  test('multipage: conditional field hidden for personal', async ({ page }) => {
    await page.goto('/dev/form?f=e2eMultipage');
    await page.fill('input[name="fields[name]"]', 'Jane Personal');
    await page.selectOption('select[name="fields[type]"]', 'personal');
    await page.locator('.easy-form-next').click();
    await expect(page.locator('.easy-form-field[data-field-handle="company"]')).toBeHidden();
  });

  test('gallery: every field type renders', async ({ page }) => {
    await page.goto('/dev/form?f=galleryAllFields');
    await expect(form(page)).toBeVisible();
    for (const sel of [
      'input[type=text][name="fields[textField]"]',
      'input[type=email][name="fields[emailField]"]',
      'input[type=number][name="fields[numberField]"]',
      'input[type=tel][name="fields[phoneField]"]',
      'input[type=date][name="fields[dateField]"]',
      'textarea[name="fields[messageField]"]',
      'select[name="fields[selectField]"]',
      'input[type=hidden][name="fields[hiddenField]"]',
    ]) {
      await expect(page.locator(sel)).toHaveCount(1);
    }
    // Hidden field carries its default value.
    await expect(page.locator('input[name="fields[hiddenField]"]')).toHaveValue('gallery');
  });

  test('default styles toggle: includeStyles controls form-render.css (JS always loads)', async ({ page }) => {
    // On by default.
    await page.goto('/dev/form?f=e2eContact');
    await expect(page.locator('link[href*="form-render.css"]')).toHaveCount(1);
    await expect(page.locator('script[src*="form-submit.js"]')).toHaveCount(1);
    // Off → stylesheet omitted, JS still present.
    await page.goto('/dev/form?f=e2eContact&styles=0');
    await expect(page.locator('link[href*="form-render.css"]')).toHaveCount(0);
    await expect(page.locator('script[src*="form-submit.js"]')).toHaveCount(1);
  });

  test('easyFormLayout() exposes the structured layout for custom rendering', async ({ page }) => {
    // The dev harness loops easyFormLayout(handle).pages → rows → fields.
    await page.goto('/dev/form?f=e2eMultipage&layout=1');
    await expect(page.locator('#ef-layout-dump section')).toHaveCount(2); // two pages
    await expect(page.locator('section[data-page="0"] .ef-dump-field')).toContainText(['name', 'type']);
    await expect(page.locator('section[data-page="1"] .ef-dump-field')).toContainText(['company', 'email']);
  });

  test('default styles stack a row’s fields in a single column', async ({ page }) => {
    await page.goto('/dev/form?f=galleryAllFields'); // has multi-field rows
    const dir = await page.locator('.easy-form-row').first()
      .evaluate((el) => getComputedStyle(el).flexDirection);
    expect(dir).toBe('column');
  });
});
