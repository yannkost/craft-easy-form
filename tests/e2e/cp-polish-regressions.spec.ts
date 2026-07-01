import { test, expect } from '@playwright/test';
import { newForm, addRow, addField, setBasics, setSiteOptions, fieldTab, closeField, save } from './builder';
import { apiSubmit, actionJson } from './submit';
import { formId, deleteForm } from './db';
import { processQueue } from './queue';

const JSON_HEADERS = { Accept: 'application/json' };

// F8 — select/checkboxes options must fall back to the PRIMARY site when a
// secondary site has no per-site override. The builder stores the primary list
// in siteOptions[default] (no base `options`), so before the fix an untranslated
// site rendered an empty <select>.
test.describe('F8: options fall back to the primary site', () => {
  test.describe.configure({ mode: 'serial' });
  const handle = 'f8OptionsFallback';

  test.afterAll(() => deleteForm(handle));

  test('an untranslated French site renders the primary options', async ({ page }) => {
    await newForm(page, 'F8 Options Fallback', handle);
    const row = await addRow(page);
    const field = await addField(row, 'select');
    await setBasics(field, 'Fruit', 'fruit');
    // Stored under siteOptions[default] — the bug case (no base options, no French).
    await setSiteOptions(field, 'default', 'apple:Apple\nbanana:Banana');
    await closeField(field);
    await save(page);

    // English (primary) site shows them...
    await page.goto(`/dev/form?f=${handle}`);
    await expect(page.locator('select[name="fields[fruit]"] option')).toContainText(['Apple', 'Banana']);

    // ...and the untranslated French site falls back to the same list.
    await page.goto(`/fr/dev/form?f=${handle}`);
    const frOptions = page.locator('select[name="fields[fruit]"] option');
    await expect(frOptions).toContainText(['Apple', 'Banana']);
    // More than just the empty "Select an option" placeholder.
    expect(await frOptions.count()).toBeGreaterThan(1);
  });
});

// F4 — presentational fields (heading/divider/callout) carry no value, so they
// must not become empty CSV columns. e2ePresentational has headingContact,
// dividerOne, calloutNotice plus real name/email fields.
test('F4: presentational fields are not export columns', async ({ request }) => {
  const fid = formId('e2ePresentational');
  const marker = 'F4Marker' + Date.now().toString().slice(-6);
  await apiSubmit(request, {
    formId: fid,
    fields: { name: marker, email: `${marker.toLowerCase()}@example.test` },
  });

  const resp = await actionJson(request, 'easy-form/submissions/export', { formId: fid });
  expect(resp.success).toBe(true);
  const token = resp.token as string;

  processQueue();
  let ready = false;
  for (let i = 0; i < 15 && !ready; i++) {
    const check = await (
      await request.get(`/actions/easy-form/submissions/export-check?key=${encodeURIComponent(token)}`, { headers: JSON_HEADERS })
    ).json();
    if (check.failed) throw new Error('export failed: ' + (check.message || ''));
    ready = !!check.ready;
    if (!ready) await new Promise((r) => setTimeout(r, 1000));
  }
  expect(ready).toBe(true);

  const dl = await request.get(`/actions/easy-form/submissions/export-download?key=${encodeURIComponent(token)}`);
  const csv = await dl.text();
  const header = csv.split('\n')[0];

  // Real fields are columns; presentational labels are not.
  expect(header).toContain('Name');
  expect(header).toContain('Email');
  expect(header).not.toContain('Contact details'); // heading label
  expect(header).not.toContain('Divider');         // divider label
  expect(csv).toContain(marker);
});

// F3 — tel and url expose min/max length in the builder; it must be enforced
// server-side too (browser maxlength alone is bypassable by a scripted POST).
test.describe('F3: tel/url length is enforced server-side', () => {
  test.describe.configure({ mode: 'serial' });
  const handle = 'f3LenServer';

  test.afterAll(() => deleteForm(handle));

  test('a too-long tel / url is rejected by the server', async ({ page, request }) => {
    await newForm(page, 'F3 Length Server', handle);
    const row = await addRow(page);

    const tel = await addField(row, 'tel');
    await setBasics(tel, 'Phone', 'phone');
    await fieldTab(tel, 'validation');
    await tel.locator('input[name$="[maxLength]"]').fill('8');
    await closeField(tel);

    const url = await addField(row, 'url');
    await setBasics(url, 'Website', 'website');
    await fieldTab(url, 'validation');
    await url.locator('input[name$="[maxLength]"]').fill('15');
    await closeField(url);

    await save(page);
    const fid = formId(handle);

    // Over the tel max (8): a valid-looking but too-long number.
    const longTel = await apiSubmit(request, { formId: fid, fields: { phone: '123456789012' } });
    expect(longTel.success).toBe(false);
    expect(longTel.errors.phone?.[0]).toMatch(/no more than 8|too long/i);

    // Over the url max (15).
    const longUrl = await apiSubmit(request, { formId: fid, fields: { website: 'https://example.com/very/long/path' } });
    expect(longUrl.success).toBe(false);
    expect(longUrl.errors.website?.[0]).toMatch(/no more than 15|too long/i);

    // Within limits → accepted.
    const ok = await apiSubmit(request, { formId: fid, fields: { phone: '12345678', website: 'https://a.co' } });
    expect(ok.success).toBe(true);
  });
});
