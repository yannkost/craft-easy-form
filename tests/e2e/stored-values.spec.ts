import { test, expect } from '@playwright/test';
import { apiSubmit } from './submit';
import { formId, dataValueByEmail } from './db';

const uniqueEmail = (tag: string) => `${tag}-${Date.now()}-${Math.floor(Math.random() * 1e6)}@example.test`;

// Stored values keep a type appropriate to the field: scalars for select/date,
// a list for (multiple) checkboxes.
test('stored values: select→string, date→string, checkboxes→array', async ({ request }) => {
  const email = uniqueEmail('values');
  const res = await apiSubmit(request, {
    formId: formId('e2eValues'),
    fields: { email, plan: 'pro', when: '2026-03-14', toppings: ['a', 'c'] },
  });
  expect(res.success).toBe(true);

  expect(dataValueByEmail('e2eValues', '$.values.plan', email)).toBe('"pro"');
  expect(dataValueByEmail('e2eValues', '$.values.when', email)).toBe('"2026-03-14"');
  expect(JSON.parse(dataValueByEmail('e2eValues', '$.values.toppings', email))).toEqual(['a', 'c']);
});
