import { test, expect } from '@playwright/test';

const uniqueEmail = (tag: string) => `${tag}-${Date.now()}-${Math.floor(Math.random() * 1e6)}@example.test`;

// Per-form behavior flags rendered on the front end:
//  - e2eContact      → allowUrlPrefill = true
//  - e2eMultipage    → showStepIndicator = true (allowUrlPrefill off)
//  - e2eRedirect     → redirectUrl set
//  - e2eHideSuccess  → hideFormOnSuccess = true
//  - e2eAutoHide     → keepSuccessMessage = false, successMessageDuration = 2

test.describe('URL pre-fill', () => {
  test('matching query params pre-fill fields when enabled', async ({ page }) => {
    await page.goto('/dev/form?f=e2eContact&name=Jane%20Doe&email=jane@acme.com&message=Hi%20there');

    await expect(page.locator('input[name="fields[name]"]')).toHaveValue('Jane Doe');
    await expect(page.locator('input[name="fields[email]"]')).toHaveValue('jane@acme.com');
    await expect(page.locator('textarea[name="fields[message]"]')).toHaveValue('Hi there');
  });

  test('unknown params are ignored and do not error', async ({ page }) => {
    await page.goto('/dev/form?f=e2eContact&name=Only%20Name&bogus=should-be-ignored');
    await expect(page.locator('input[name="fields[name]"]')).toHaveValue('Only Name');
    // No field for "bogus"; the form still renders normally.
    await expect(page.locator('form.easy-form')).toBeVisible();
  });

  test('a form without the flag ignores URL params', async ({ page }) => {
    await page.goto('/dev/form?f=e2eMultipage&name=ShouldNotFill');
    await expect(page.locator('input[name="fields[name]"]')).toHaveValue('');
  });
});

test.describe('Step indicator', () => {
  test('highlights the current page and advances with navigation', async ({ page }) => {
    await page.goto('/dev/form?f=e2eMultipage');

    const steps = page.locator('.easy-form-step');
    await expect(steps).toHaveCount(2);
    await expect(steps.nth(0)).toHaveClass(/is-active/);
    await expect(steps.nth(1)).not.toHaveClass(/is-active/);

    // Fill page 1's required fields, then advance.
    await page.fill('input[name="fields[name]"]', 'Stepper');
    await page.selectOption('select[name="fields[type]"]', 'personal');
    await page.locator('.easy-form-next').click();

    await expect(steps.nth(1)).toHaveClass(/is-active/);
    await expect(steps.nth(0)).toHaveClass(/is-complete/);
  });
});

test.describe('Success behavior', () => {
  test('redirectUrl sends the browser to the configured URL after success', async ({ page }) => {
    await page.goto('/dev/form?f=e2eRedirect');
    await page.fill('input[name="fields[name]"]', 'Redirector');
    await page.fill('input[name="fields[email]"]', uniqueEmail('redirect'));
    await page.locator('form.easy-form button[type=submit]').click();

    // The success message shows first, then the JS redirects after a short delay.
    await expect(page.locator('.easy-form-message.success')).toBeVisible();
    await page.waitForURL(/[?&]redirected=ok/, { timeout: 7_000 });
  });

  test('hideFormOnSuccess hides the form fields after success', async ({ page }) => {
    await page.goto('/dev/form?f=e2eHideSuccess');
    const name = page.locator('input[name="fields[name]"]');
    await name.fill('Hider');
    await page.fill('input[name="fields[email]"]', uniqueEmail('hide'));
    await page.locator('form.easy-form button[type=submit]').click();

    await expect(page.locator('.easy-form-message.success')).toBeVisible();
    // The fields (inside now-hidden rows) and the submit actions are hidden.
    await expect(name).toBeHidden();
    await expect(page.locator('form.easy-form .easy-form-actions')).toBeHidden();
  });

  test('success message stays visible by default until the page reloads', async ({ page }) => {
    // e2eContact uses the default keepSuccessMessage = true.
    await page.goto('/dev/form?f=e2eContact');
    await page.fill('input[name="fields[name]"]', 'Stay');
    await page.fill('input[name="fields[email]"]', uniqueEmail('stay'));
    await page.locator('form.easy-form button[type=submit]').click();

    const message = page.locator('.easy-form-message.success');
    await expect(message).toBeVisible();
    // It is still there well past any auto-hide window.
    await page.waitForTimeout(3_000);
    await expect(message).toBeVisible();
  });

  test('success message auto-hides after successMessageDuration when keep is off', async ({ page }) => {
    // e2eAutoHide sets keepSuccessMessage = false, successMessageDuration = 2.
    await page.goto('/dev/form?f=e2eAutoHide');
    await page.fill('input[name="fields[name]"]', 'Fader');
    await page.fill('input[name="fields[email]"]', uniqueEmail('autohide'));
    await page.locator('form.easy-form button[type=submit]').click();

    const message = page.locator('.easy-form-message.success');
    await expect(message).toBeVisible();
    // Gone after the configured 2s delay (allow margin for the timer).
    await expect(message).toHaveCount(0, { timeout: 5_000 });
  });
});
