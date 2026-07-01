import { test, expect } from '@playwright/test';
import { apiSubmit } from './submit';
import { formId, dataValueByEmail } from './db';

// extraFieldPolicy governs what non-builder keys a submission may carry:
//   strict      → only builder fields; declared frontend + unknown keys dropped
//   allowListed → declared frontend fields kept (coerced), unknown dropped (default,
//                 already covered by security.spec)
//   open        → unknown keys also kept, under data.frontend
// Plus per-field maxItems / maxLength caps on declared frontend fields.
const uniqueEmail = (tag: string) => `${tag}-${Date.now()}-${Math.floor(Math.random() * 1e6)}@example.test`;

test('strict policy drops declared frontend fields and unknown keys alike', async ({ request }) => {
  const email = uniqueEmail('strict');
  const res = await apiSubmit(request, {
    formId: formId('e2ePolicyStrict'),
    fields: { name: 'Strict', email, note: 'declared-but-ignored', evil: 'INJECTED' },
  });
  expect(res.success).toBe(true);

  // Builder field kept; both extra keys dropped.
  expect(dataValueByEmail('e2ePolicyStrict', '$.values.name', email)).toBe('"Strict"');
  expect(dataValueByEmail('e2ePolicyStrict', '$.frontend.note', email)).toBe('NULL');
  expect(dataValueByEmail('e2ePolicyStrict', '$.frontend.evil', email)).toBe('NULL');
});

test('open policy keeps unknown keys under data.frontend', async ({ request }) => {
  const email = uniqueEmail('open');
  const res = await apiSubmit(request, {
    formId: formId('e2ePolicyOpen'),
    fields: { name: 'Open', email, custom1: 'kept-anyway' },
  });
  expect(res.success).toBe(true);

  expect(dataValueByEmail('e2ePolicyOpen', '$.frontend.custom1', email)).toBe('"kept-anyway"');
});

test('maxItems rejects a list that is too long', async ({ request }) => {
  const res = await apiSubmit(request, {
    formId: formId('e2eFrontendCaps'),
    fields: { name: 'Caps', email: uniqueEmail('caps-items'), tags: ['a', 'b', 'c'] },
  });
  expect(res.success).toBe(false);
  expect(res.errors.tags?.[0]).toMatch(/at most 2 items/i);
});

test('maxLength rejects a string that is too long', async ({ request }) => {
  const res = await apiSubmit(request, {
    formId: formId('e2eFrontendCaps'),
    fields: { name: 'Caps', email: uniqueEmail('caps-len'), bio: 'abcdefgh' },
  });
  expect(res.success).toBe(false);
  expect(res.errors.bio?.[0]).toMatch(/at most 5 characters/i);
});

test('within-cap frontend values are accepted and stored', async ({ request }) => {
  const email = uniqueEmail('caps-ok');
  const res = await apiSubmit(request, {
    formId: formId('e2eFrontendCaps'),
    fields: { name: 'Caps', email, tags: ['a', 'b'], bio: 'abc' },
  });
  expect(res.success).toBe(true);
  expect(dataValueByEmail('e2eFrontendCaps', '$.frontend.bio', email)).toBe('"abc"');
});
