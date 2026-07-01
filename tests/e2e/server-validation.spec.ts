import { test, expect } from '@playwright/test';
import { apiSubmit } from './submit';
import { formId } from './db';

// Validation must be enforced on the server, independent of the client-side JS
// (which a bot/script bypasses). These submit directly to the action endpoint.

test.describe('Server-side validation', () => {
  let fid: string;
  test.beforeAll(() => { fid = formId('e2eValidation'); });

  test('rejects a missing required field and an invalid email', async ({ request }) => {
    const res = await apiSubmit(request, { formId: fid, fields: { email: 'not-an-email' } });
    expect(res.success).toBe(false);
    expect(res.errors.username?.[0]).toMatch(/required/i);
    expect(res.errors.email?.[0]).toMatch(/valid email/i);
  });

  test('enforces minLength / maxLength on text', async ({ request }) => {
    const tooShort = await apiSubmit(request, { formId: fid, fields: { username: 'ab', email: 'a@b.co' } });
    expect(tooShort.success).toBe(false);
    expect(tooShort.errors.username?.[0]).toMatch(/at least 3/i);

    const tooLong = await apiSubmit(request, { formId: fid, fields: { username: 'abcdefghi', email: 'a@b.co' } });
    expect(tooLong.success).toBe(false);
    expect(tooLong.errors.username?.[0]).toMatch(/no more than 8/i);
  });

  test('enforces numeric min / max', async ({ request }) => {
    const tooYoung = await apiSubmit(request, { formId: fid, fields: { username: 'valid1', email: 'a@b.co', age: '5' } });
    expect(tooYoung.success).toBe(false);
    expect(tooYoung.errors.age?.[0]).toMatch(/at least 18/i);

    const tooOld = await apiSubmit(request, { formId: fid, fields: { username: 'valid1', email: 'a@b.co', age: '150' } });
    expect(tooOld.success).toBe(false);
    expect(tooOld.errors.age?.[0]).toMatch(/(no more than|at most|maximum).*99|99/i);
  });

  test('coerces/validates allow-listed frontend fields (number)', async ({ request }) => {
    const res = await apiSubmit(request, {
      formId: fid,
      fields: { username: 'valid1', email: 'a@b.co', score: 'abc' },
    });
    expect(res.success).toBe(false);
    expect(res.errors.score?.[0]).toMatch(/number/i);
  });

  test('accepts a fully valid submission', async ({ request }) => {
    const res = await apiSubmit(request, {
      formId: fid,
      fields: { username: 'valid1', email: 'jane@example.com', age: '42', score: '7' },
    });
    expect(res.success).toBe(true);
    expect(res.submissionId).toBeGreaterThan(0);
  });
});

// Hardening fixes from the status-report review (e2eValHard has no required fields).
test.describe('Validation hardening', () => {
  let fid: string;
  test.beforeAll(() => { fid = formId('e2eValHard'); });

  test('a number field with a blank max accepts positive values (#3)', async ({ request }) => {
    const res = await apiSubmit(request, { formId: fid, fields: { qtyNoMax: '50' } });
    expect(res.success).toBe(true);
  });

  test('a capped number field still enforces min/max', async ({ request }) => {
    const over = await apiSubmit(request, { formId: fid, fields: { capped: '50' } });
    expect(over.success).toBe(false);
    expect(over.errors.capped?.[0]).toMatch(/no more than 10/i);

    const ok = await apiSubmit(request, { formId: fid, fields: { capped: '5' } });
    expect(ok.success).toBe(true);
  });

  test('select rejects a value not in the configured options (#2)', async ({ request }) => {
    const bad = await apiSubmit(request, { formId: fid, fields: { choice: 'zzz' } });
    expect(bad.success).toBe(false);
    expect(bad.errors.choice?.[0]).toMatch(/invalid/i);

    const ok = await apiSubmit(request, { formId: fid, fields: { choice: 'a' } });
    expect(ok.success).toBe(true);
  });

  test('checkboxes reject an invalid item (#2)', async ({ request }) => {
    const bad = await apiSubmit(request, { formId: fid, fields: { boxes: ['x', 'HACK'] } });
    expect(bad.success).toBe(false);
    expect(bad.errors.boxes?.[0]).toMatch(/invalid/i);

    const ok = await apiSubmit(request, { formId: fid, fields: { boxes: ['x', 'y'] } });
    expect(ok.success).toBe(true);
  });

  test('date rejects loose formats and accepts YYYY-MM-DD (#12)', async ({ request }) => {
    const loose = await apiSubmit(request, { formId: fid, fields: { when: 'tomorrow' } });
    expect(loose.success).toBe(false);
    expect(loose.errors.when?.[0]).toMatch(/valid date/i);

    const ok = await apiSubmit(request, { formId: fid, fields: { when: '2026-01-15' } });
    expect(ok.success).toBe(true);
  });
});
