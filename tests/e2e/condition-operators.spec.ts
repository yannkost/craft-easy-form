import { test, expect } from '@playwright/test';
import { apiSubmit } from './submit';
import { formId, dataValueByEmail } from './db';

// e2eCondOps wires one conditional row per operator so each can be toggled in
// isolation (contains / notContains / isEmpty / isNotEmpty), plus an `any` (OR)
// row. Only `equals`/`all` were exercised e2e before; this covers the rest both
// in the browser (visibility) and on the server (anti-tamper discard).
const uniqueEmail = (tag: string) => `${tag}-${Date.now()}-${Math.floor(Math.random() * 1e6)}@example.test`;

const row = (page: import('@playwright/test').Page, handle: string) =>
  page.locator(`input[name="fields[${handle}]"]`);

test('frontend: every operator toggles its row as the trigger fields change', async ({ page }) => {
  await page.goto('/dev/form?f=e2eCondOps');

  // Initial state: trigger empty, comment empty.
  await expect(row(page, 'vipPerk')).toBeHidden(); // contains "vip" → false
  await expect(row(page, 'standardNote')).toBeVisible(); // notContains "vip" → true
  await expect(row(page, 'needComment')).toBeVisible(); // comment isEmpty → true
  await expect(row(page, 'thanksComment')).toBeHidden(); // comment isNotEmpty → false
  await expect(row(page, 'xyField')).toBeHidden(); // any(equals x | y) → false

  // trigger now contains "vip" → flips the contains/notContains pair.
  await page.fill('input[name="fields[trigger]"]', 'vip-member');
  await expect(row(page, 'vipPerk')).toBeVisible();
  await expect(row(page, 'standardNote')).toBeHidden();

  // comment non-empty → flips the isEmpty/isNotEmpty pair.
  await page.fill('input[name="fields[comment]"]', 'hello');
  await expect(row(page, 'needComment')).toBeHidden();
  await expect(row(page, 'thanksComment')).toBeVisible();

  // trigger = "x" satisfies the OR row (and no longer contains "vip").
  await page.fill('input[name="fields[trigger]"]', 'x');
  await expect(row(page, 'xyField')).toBeVisible();
  await expect(row(page, 'vipPerk')).toBeHidden();

  // trigger = "y" also satisfies the OR row.
  await page.fill('input[name="fields[trigger]"]', 'y');
  await expect(row(page, 'xyField')).toBeVisible();

  // trigger = "z" satisfies neither → OR row hidden again.
  await page.fill('input[name="fields[trigger]"]', 'z');
  await expect(row(page, 'xyField')).toBeHidden();
});

test('server: values whose operator-gated row is hidden are discarded (anti-tamper)', async ({ request }) => {
  const email = uniqueEmail('condops');
  // trigger empty → vipPerk (contains) and xyField (any) hidden; standardNote
  // (notContains) and needComment (isEmpty) visible. Post every gated value.
  const res = await apiSubmit(request, {
    formId: formId('e2eCondOps'),
    fields: {
      name: 'Tamper',
      email,
      trigger: '',
      comment: '',
      vipPerk: 'SNEAK', // row hidden → must drop
      xyField: 'SNEAK', // row hidden → must drop
      standardNote: 'kept', // row visible → kept
      needComment: 'kept', // row visible → kept
    },
  });
  expect(res.success).toBe(true);

  expect(dataValueByEmail('e2eCondOps', '$.values.vipPerk', email)).toBe('NULL');
  expect(dataValueByEmail('e2eCondOps', '$.values.xyField', email)).toBe('NULL');
  expect(dataValueByEmail('e2eCondOps', '$.values.standardNote', email)).toBe('"kept"');
  expect(dataValueByEmail('e2eCondOps', '$.values.needComment', email)).toBe('"kept"');
});
