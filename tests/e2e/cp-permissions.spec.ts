import { test, expect, type BrowserContext } from '@playwright/test';
import { ensureLimitedUser, LIMITED_USER } from './db';

// Every plugin controller gates its actions behind an easy-form:* permission in
// beforeAction(). This spec drives a non-admin CP user that holds ONLY accessCp
// (so it can reach the control panel) and asserts every plugin screen refuses it.
//
// It needs a second user, which Craft **Solo** forbids (1-user cap). The test
// instance is Solo, so this whole file auto-skips there; it runs for real on any
// Pro instance (Pro is free for local dev). The spec is self-contained — it logs
// the limited user in via its own browser context rather than a shared fixture.

const BASE = process.env.EASY_FORM_BASE_URL || 'https://plugins-tester.ddev.site';

let available = false;
let ctx: BrowserContext;

test.beforeAll(async ({ browser }) => {
  available = ensureLimitedUser();
  if (!available) return;

  // A fresh context (no admin storageState) logged in as the limited user.
  ctx = await browser.newContext({ baseURL: BASE, ignoreHTTPSErrors: true });
  const page = await ctx.newPage();
  await page.goto('/cp/login');
  await page.locator('input[name="username"]:visible').first().fill(LIMITED_USER.username);
  await page.locator('input[name="password"]:visible').first().fill(LIMITED_USER.password);
  await page.getByRole('button', { name: 'Sign in' }).first().click();
  await expect(page.locator('#global-sidebar')).toBeVisible({ timeout: 15_000 });
  await page.close();
});

test.afterAll(async () => {
  await ctx?.close();
});

test.beforeEach(() => {
  test.skip(!available, 'Requires Craft Pro (a non-admin user); the test instance is Solo.');
});

test('control: the limited user can reach the CP (session + accessCp work)', async () => {
  const page = await ctx.newPage();
  const resp = await page.goto('/cp/dashboard');
  expect(resp?.status()).toBe(200);
  await expect(page.locator('#global-sidebar')).toBeVisible();
  await page.close();
});

// Each route maps to a different permission gate; an authenticated user without
// it gets a 403 (ForbiddenHttpException), not a login redirect (the guest case).
const gatedPages: Array<[string, string]> = [
  ['easy-form:manageForms', '/cp/easy-form'],
  ['easy-form:manageForms', '/cp/easy-form/settings'],
  ['easy-form:viewSubmissions', '/cp/easy-form/submissions'],
  ['easy-form:viewSubmissions', '/cp/easy-form/exports'],
  ['easy-form:viewSubmissions', '/cp/easy-form/privacy'],
];

for (const [perm, path] of gatedPages) {
  test(`denies ${path} (needs ${perm})`, async () => {
    const page = await ctx.newPage();
    const resp = await page.goto(path);
    expect(resp?.status()).toBe(403);
    await page.close();
  });
}

test('denies the delete-submission action (needs easy-form:deleteSubmissions)', async () => {
  // POST actions are gated too; the limited session carries no deleteSubmissions
  // permission, so Craft refuses before the action body runs.
  const csrf = await (
    await ctx.request.get('/actions/easy-form/forms/get-csrf-token', { headers: { Accept: 'application/json' } })
  ).json();
  const resp = await ctx.request.post('/', {
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    form: { action: 'easy-form/submissions/delete', CRAFT_CSRF_TOKEN: csrf.csrfToken, id: '1' },
  });
  expect(resp.status()).toBe(403);
});
