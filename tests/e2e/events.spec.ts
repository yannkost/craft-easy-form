import { test, expect, type Page } from '@playwright/test';

// form-submit.js dispatches `easyform:*` CustomEvents on the <form> at each
// lifecycle step. They bubble, so we capture them at the document level.

// Attach the capture listener *before* any page script runs, so `easyform:init`
// (fired during form init) is caught too.
async function captureEvents(page: Page) {
  await page.addInitScript(() => {
    (window as any).__ef = [];
    ['init', 'beforevalidate', 'invalid', 'beforesubmit', 'submit', 'success', 'error', 'pagechange'].forEach((n) =>
      document.addEventListener('easyform:' + n, (e: any) =>
        (window as any).__ef.push({
          n,
          from: e.detail?.from,
          to: e.detail?.to,
          errors: (e.detail?.errors || []).length,
          ok: e.detail?.response?.success ?? null,
        }),
      ),
    );
  });
}
const captured = (page: Page) => page.evaluate(() => (window as any).__ef as any[]);
const names = (ev: any[]) => ev.map((x) => x.n);

test.describe('Frontend lifecycle events', () => {
  test('init / pagechange / beforevalidate / beforesubmit / submit / success on a happy submit', async ({ page }) => {
    await captureEvents(page);
    await page.goto('/dev/form?f=e2eMultipage');

    await page.fill('input[name="fields[name]"]', 'Ev Tester');
    await page.selectOption('select[name="fields[type]"]', 'personal');
    await page.locator('.easy-form-next').click(); // → pagechange
    await page.fill('input[name="fields[email]"]', 'ev@example.com');
    await page.locator('form.easy-form button[type=submit]').click(); // → submit → success
    await expect(page.locator('.easy-form-message.success')).toBeVisible();

    const ev = await captured(page);
    for (const n of ['init', 'pagechange', 'beforevalidate', 'beforesubmit', 'submit', 'success']) {
      expect(names(ev), `expected easyform:${n}`).toContain(n);
    }
    const pc = ev.find((x) => x.n === 'pagechange');
    expect(pc.from).toBe(0);
    expect(pc.to).toBe(1);
  });

  test('invalid fires (with the errors) when client validation fails, and submit does not', async ({ page }) => {
    await captureEvents(page);
    await page.goto('/dev/form?f=e2eContact');
    await page.locator('form.easy-form button[type=submit]').click(); // empty required fields
    await expect(page.locator('.field-error').first()).toBeVisible();

    const ev = await captured(page);
    expect(names(ev)).toContain('invalid');
    expect(ev.find((x) => x.n === 'invalid').errors).toBeGreaterThan(0);
    // Validation stopped it, so the request was never made.
    expect(names(ev)).not.toContain('submit');
    expect(names(ev)).not.toContain('success');
  });

  test('error fires when the server rejects the submission', async ({ page }) => {
    await captureEvents(page);
    // CAPTCHA form with the widget script blocked → no token → server rejects.
    await page.route(/challenges\.cloudflare\.com/, (r) => r.abort());
    await page.goto('/dev/form?f=e2eCaptchaTurnstile');
    await page.fill('input[name="fields[name]"]', 'Bot');
    await page.fill('input[name="fields[email]"]', 'bot@example.com');
    await page.locator('form.easy-form button[type=submit]').click();
    await expect(page.locator('.easy-form-message.error')).toBeVisible();

    const ev = await captured(page);
    expect(names(ev)).toContain('submit'); // it tried…
    expect(names(ev)).toContain('error'); // …and the server said no
    expect(names(ev)).not.toContain('success');
  });

  test('beforesubmit is cancelable (preventDefault stops the submit)', async ({ page }) => {
    await page.goto('/dev/form?f=e2eContact');
    await page.evaluate(() => {
      document.addEventListener('easyform:beforesubmit', (e) => e.preventDefault());
    });
    await page.fill('input[name="fields[name]"]', 'NoSend');
    await page.fill('input[name="fields[email]"]', 'nosend@example.com');
    await page.fill('textarea[name="fields[message]"]', 'should not submit');
    await page.locator('form.easy-form button[type=submit]').click();
    // No success message — the submit was canceled.
    await expect(page.locator('.easy-form-message.success')).toHaveCount(0);
  });
});
