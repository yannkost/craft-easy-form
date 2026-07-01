import { test, expect } from '@playwright/test';
import { apiSubmit } from './submit';
import { formId, dataValueByEmail, statusByEmail, countByEmail } from './db';

const uniqueEmail = (tag: string) => `${tag}-${Date.now()}-${Math.floor(Math.random() * 1e6)}@example.test`;

// The tester registers a beforeValidate handler (config/app.php) that injects an
// allow-listed `serverTier` field for the e2eHookInject form. The browser never
// sends it — this proves beforeValidate mutations are applied and then flow
// through validation + the allow-list / canonicalization, then get stored.
test('a beforeValidate handler can inject an allow-listed, server-computed field', async ({ request }) => {
  const email = uniqueEmail('hook');

  const res = await apiSubmit(request, {
    formId: formId('e2eHookInject'),
    fields: { name: 'Hooked', email }, // no serverTier from the client
  });
  expect(res.success).toBe(true);

  // Allow-listed frontend values live under data.frontend.
  expect(dataValueByEmail('e2eHookInject', '$.frontend.serverTier', email)).toBe('"gold"');
});

// afterValidate is an inspect/cancel hook. The tester's handler for e2eHookAfter
// (a) overwrites `name` to "MUTATED" — which must be ignored — and (b) cancels
// the submit when name === "CancelMe".
test('afterValidate cannot mutate stored data (read-only contract)', async ({ request }) => {
  const email = uniqueEmail('after-readonly');
  const res = await apiSubmit(request, {
    formId: formId('e2eHookAfter'),
    fields: { name: 'Original', email },
  });
  expect(res.success).toBe(true);
  // The handler set name='MUTATED', but afterValidate changes aren't applied.
  expect(dataValueByEmail('e2eHookAfter', '$.values.name', email)).toBe('"Original"');
});

test('afterValidate can cancel a submission', async ({ request }) => {
  const email = uniqueEmail('after-cancel');
  const res = await apiSubmit(request, {
    formId: formId('e2eHookAfter'),
    fields: { name: 'CancelMe', email },
  });
  expect(res.success).toBe(false);
  expect(res.error).toMatch(/blocked by afterValidate/i);
  expect(countByEmail('e2eHookAfter', email)).toBe(0);
});

// beforeSaveSubmission can mutate the final model: the handler flips the stored
// status to "approved" (default would be "pending").
test('beforeSaveSubmission can mutate the stored model', async ({ request }) => {
  const email = uniqueEmail('before-save');
  const res = await apiSubmit(request, {
    formId: formId('e2eHookSave'),
    fields: { name: 'Saver', email },
  });
  expect(res.success).toBe(true);
  expect(statusByEmail('e2eHookSave', email)).toBe('approved');
});
