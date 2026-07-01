import { test, expect } from '@playwright/test';
import { apiSubmit } from './submit';
import { formId, countByEmail, ipByEmail, craft } from './db';

const uniqueEmail = (tag: string) => `${tag}-${Date.now()}-${Math.floor(Math.random() * 1e6)}@example.test`;

test.describe('GDPR / privacy', () => {
  test('stored IP addresses are anonymized', async ({ request }) => {
    // The test instance is configured with ipStorageMode = 'anonymized'.
    const email = uniqueEmail('ip');
    const res = await apiSubmit(request, {
      formId: formId('e2eContact'),
      fields: { name: 'Ada', email, message: 'hi' },
    });
    expect(res.success).toBe(true);

    const ip = ipByEmail('e2eContact', email);
    expect(ip).not.toBe('NULL');
    // IPv4 anonymization zeroes the last octet.
    expect(ip).toMatch(/\.0$/);
  });

  test('per-email export returns the right submissions; erasure removes them', async ({ request }) => {
    const email = uniqueEmail('subject');
    const fid = formId('e2eContact');

    await apiSubmit(request, { formId: fid, fields: { name: 'Subj One', email, message: 'first' } });
    await apiSubmit(request, { formId: fid, fields: { name: 'Subj Two', email, message: 'second' } });
    expect(countByEmail('e2eContact', email)).toBe(2);

    // Export (data-subject access request).
    const out = craft(['easy-form/privacy/export', `--email=${email}`]);
    const data = JSON.parse(out.slice(out.indexOf('{')));
    expect(data.email).toBe(email);
    expect(data.submissionCount).toBe(2);
    expect(JSON.stringify(data.submissions)).toContain('first');
    expect(JSON.stringify(data.submissions)).toContain('second');

    // Erasure (right to be forgotten) — non-interactive.
    const forget = craft(['easy-form/privacy/forget', `--email=${email}`, '--interactive=0']);
    expect(forget).toMatch(/Erased 2 submission/);
    expect(countByEmail('e2eContact', email)).toBe(0);

    // A subsequent export finds nothing.
    const afterOut = craft(['easy-form/privacy/export', `--email=${email}`]);
    const after = JSON.parse(afterOut.slice(afterOut.indexOf('{')));
    expect(after.submissionCount).toBe(0);
  });
});
