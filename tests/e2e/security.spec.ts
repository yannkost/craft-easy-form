import { test, expect } from '@playwright/test';
import { apiSubmit } from './submit';
import { formId, countByEmail, dataValueByEmail } from './db';

// Server-side anti-abuse guarantees. Several are only observable in what gets
// *stored*, so they read the DB after submitting. Each test uses a unique
// primaryEmail marker to find its own submission — robust against other tests
// submitting to the same form in parallel.

const uniqueEmail = (tag: string) => `${tag}-${Date.now()}-${Math.floor(Math.random() * 1e6)}@example.test`;

test.describe('Submission security guarantees', () => {
  test('a honeypot-filled submission silently succeeds but is not stored', async ({ request }) => {
    const email = uniqueEmail('honeypot');
    const res = await apiSubmit(request, {
      formId: formId('e2eContact'),
      fields: { name: 'Spammer', email, message: 'buy now' },
      extra: { honeypot: 'i-am-a-bot' },
    });

    // The response mimics success (so bots get no signal)...
    expect(res.success).toBe(true);
    // ...but nothing is written for this submission.
    expect(countByEmail('e2eContact', email)).toBe(0);
  });

  // The test instance configures blockedKeywords = "spamzilla\nbuy-followers-now".
  test('a submission containing a globally blocked keyword silently succeeds but is not stored', async ({ request }) => {
    const email = uniqueEmail('keyword');
    const res = await apiSubmit(request, {
      formId: formId('e2eContact'),
      fields: { name: 'Spammer', email, message: 'great deals on SpamZilla today' },
    });
    // Same silent-success contract as the honeypot, but nothing is stored.
    expect(res.success).toBe(true);
    expect(countByEmail('e2eContact', email)).toBe(0);

    // Control: the same form with no blocked keyword stores normally.
    const cleanEmail = uniqueEmail('keyword-clean');
    const ok = await apiSubmit(request, {
      formId: formId('e2eContact'),
      fields: { name: 'Real Person', email: cleanEmail, message: 'a perfectly normal message' },
    });
    expect(ok.success).toBe(true);
    expect(countByEmail('e2eContact', cleanEmail)).toBe(1);
  });

  test('undeclared (tampered) field keys are stripped, declared frontend fields are kept', async ({ request }) => {
    const email = uniqueEmail('tamper');
    const res = await apiSubmit(request, {
      formId: formId('e2eContact'),
      fields: {
        name: 'Jane',
        email,
        message: 'hello',
        utm_source: 'newsletter', // declared frontend field (allow-listed)
        evil: 'INJECTED',          // not in the schema or allow-list
      },
    });
    expect(res.success).toBe(true);

    expect(dataValueByEmail('e2eContact', '$.values.evil', email)).toBe('NULL');
    expect(dataValueByEmail('e2eContact', '$.frontend.evil', email)).toBe('NULL');
    expect(dataValueByEmail('e2eContact', '$.frontend.utm_source', email)).toBe('"newsletter"');
  });

  test('a conditionally-hidden field cannot be injected by posting its value', async ({ request }) => {
    const fid = formId('e2eMultipage');

    // type=personal → the "company" field is hidden; posting it must be discarded.
    const hiddenEmail = uniqueEmail('hidden');
    const hidden = await apiSubmit(request, {
      formId: fid,
      fields: { name: 'Bob', type: 'personal', email: hiddenEmail, company: 'HACKED' },
    });
    expect(hidden.success).toBe(true);
    expect(dataValueByEmail('e2eMultipage', '$.values.company', hiddenEmail)).toBe('NULL');

    // type=business → the field is visible, so its value is stored normally.
    const shownEmail = uniqueEmail('shown');
    const shown = await apiSubmit(request, {
      formId: fid,
      fields: { name: 'Acme', type: 'business', email: shownEmail, company: 'AcmeInc' },
    });
    expect(shown.success).toBe(true);
    expect(dataValueByEmail('e2eMultipage', '$.values.company', shownEmail)).toBe('"AcmeInc"');
  });

  test('dangerous control characters are stripped from stored values', async ({ request }) => {
    const email = uniqueEmail('ctrl');
    // Bell (\x07) and a unit-separator (\x1F) must be removed.
    const res = await apiSubmit(request, {
      formId: formId('e2eContact'),
      fields: { name: 'Ctrl', email, message: 'clean\x07te\x1Fxt' },
    });
    expect(res.success).toBe(true);
    expect(dataValueByEmail('e2eContact', '$.values.message', email)).toBe('"cleantext"');
  });

  test('SQL-injection-style values are stored verbatim, never executed', async ({ request }) => {
    const email = uniqueEmail('sqli');
    const namePayload = "Robert'); DROP TABLE easyform_submissions;--";
    const msgPayload = "x'; DELETE FROM easyform_forms WHERE 1=1; --";

    const res = await apiSubmit(request, {
      formId: formId('e2eContact'),
      fields: { name: namePayload, email, message: msgPayload },
    });
    expect(res.success).toBe(true);
    expect(res.submissionId).toBeGreaterThan(0);

    // If either payload had executed, the following reads would error or return
    // 0/empty. They don't, because every value is parameter-bound by ActiveRecord:
    // the submission row is stored…
    expect(countByEmail('e2eContact', email)).toBe(1);
    // …the forms table survived the DELETE payload…
    expect(formId('e2eContact')).not.toBe('');
    // …and the values round-trip byte-for-byte (stored as data, not run as SQL).
    expect(dataValueByEmail('e2eContact', '$.values.name', email)).toContain('DROP TABLE easyform_submissions');
    expect(dataValueByEmail('e2eContact', '$.values.message', email)).toContain('DELETE FROM easyform_forms');
  });
});
