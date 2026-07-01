import { test, expect } from '@playwright/test';
import { apiSubmit } from './submit';
import { formId, countByEmail, statusByEmail } from './db';

// Two spam controls beyond the honeypot/keyword drop covered by security.spec:
//   - blocked email domains → a hard validation rejection (not silent spam).
//     The test instance configures blockedEmailDomains = "blocked-spam-domain.test".
//   - saveSpamSubmissions → per-form switch to STORE spam (flagged) vs drop it.
const uniqueEmail = (tag: string, domain = 'example.test') =>
  `${tag}-${Date.now()}-${Math.floor(Math.random() * 1e6)}@${domain}`;

test('a blocked email domain is rejected with a field error', async ({ request }) => {
  const res = await apiSubmit(request, {
    formId: formId('e2eContact'),
    fields: { name: 'Blocked', email: uniqueEmail('blocked', 'blocked-spam-domain.test'), message: 'hi' },
  });
  expect(res.success).toBe(false);
  expect(res.errors.email?.[0]).toMatch(/not allowed/i);
});

test('an allowed email domain submits normally (control)', async ({ request }) => {
  const email = uniqueEmail('allowed');
  const res = await apiSubmit(request, {
    formId: formId('e2eContact'),
    fields: { name: 'Allowed', email, message: 'hi' },
  });
  expect(res.success).toBe(true);
  expect(countByEmail('e2eContact', email)).toBe(1);
});

test('saveSpamSubmissions stores spam, flagged as spam, instead of dropping it', async ({ request }) => {
  const email = uniqueEmail('savespam');
  const res = await apiSubmit(request, {
    formId: formId('e2eSaveSpam'),
    fields: { name: 'Spammer', email, message: 'hello' },
    extra: { honeypot: 'i-am-a-bot' }, // trips the spam check
  });
  // Silent-success contract is unchanged...
  expect(res.success).toBe(true);
  // ...but unlike the default form, the spam IS stored and flagged.
  expect(countByEmail('e2eSaveSpam', email)).toBe(1);
  expect(statusByEmail('e2eSaveSpam', email)).toBe('spam');
});
