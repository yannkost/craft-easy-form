import { test, expect } from '@playwright/test';

// CAPTCHA coverage uses the providers' OFFICIAL test keys (configured in the
// host project's config/easy-form.php), which pass/fail deterministically:
//   - Turnstile 1x… site/secret → always passes
//   - reCAPTCHA v2 6Le…/6Le… test keys → always pass
//   - reCAPTCHA v3 has no public test key; it reuses the v2 keys, so only the
//     rejection path is meaningful (no deterministic happy path).
//
// Verification POSTs to the real Cloudflare/Google siteverify endpoints, so
// these tests need outbound network from the app.

const PROVIDERS = [
  { name: 'Turnstile', handle: 'e2eCaptchaTurnstile', scriptRe: /challenges\.cloudflare\.com/ },
  { name: 'reCAPTCHA v2', handle: 'e2eCaptchaV2', scriptRe: /google\.com\/recaptcha/ },
  { name: 'reCAPTCHA v3', handle: 'e2eCaptchaV3', scriptRe: /google\.com\/recaptcha/ },
];

test.describe('CAPTCHA-protected forms', () => {
  // Rejection (all providers): block the provider script so no token is ever
  // produced — the server must reject the submission. This is the security
  // guarantee and the primary coverage for the invisible v3 provider.
  for (const p of PROVIDERS) {
    test(`${p.name}: a submission with no CAPTCHA token is rejected`, async ({ page }) => {
      await page.route(p.scriptRe, (r) => r.abort());
      await page.goto(`/dev/form?f=${p.handle}`);
      await page.fill('input[name="fields[name]"]', 'Bot McBotface');
      await page.fill('input[name="fields[email]"]', 'bot@example.com');
      await page.locator('form.easy-form button[type=submit]').click();

      await expect(page.locator('.easy-form-message.error')).toContainText(/CAPTCHA/i);
      await expect(page.locator('.easy-form-message.success')).toHaveCount(0);
    });
  }

  // Success (Turnstile): the managed test widget auto-injects a passing token.
  test('Turnstile: a valid token passes verification and the form submits', async ({ page }) => {
    await page.goto('/dev/form?f=e2eCaptchaTurnstile');
    await page.fill('input[name="fields[name]"]', 'Jane Human');
    await page.fill('input[name="fields[email]"]', 'jane@example.com');

    // Wait for the widget to populate its hidden response field.
    await expect(page.locator('input[name="cf-turnstile-response"]'))
      .not.toHaveValue('', { timeout: 20_000 });

    await page.locator('form.easy-form button[type=submit]').click();
    await expect(page.locator('.easy-form-message.success')).toBeVisible();
  });

  // Success (reCAPTCHA v2): the test key passes as soon as the checkbox is ticked.
  test('reCAPTCHA v2: ticking the checkbox passes verification and the form submits', async ({ page }) => {
    await page.goto('/dev/form?f=e2eCaptchaV2');
    await page.fill('input[name="fields[name]"]', 'John Human');
    await page.fill('input[name="fields[email]"]', 'john@example.com');

    // Click the "I'm not a robot" checkbox inside the reCAPTCHA anchor iframe.
    const anchor = page.frameLocator('iframe[title="reCAPTCHA"]');
    await anchor.locator('#recaptcha-anchor').click();

    await expect(page.locator('textarea[name="g-recaptcha-response"]'))
      .not.toHaveValue('', { timeout: 20_000 });

    await page.locator('form.easy-form button[type=submit]').click();
    await expect(page.locator('.easy-form-message.success')).toBeVisible();
  });
});
