import { test, expect } from '@playwright/test';
import { apiSubmit } from './submit';
import { formId, craft } from './db';

// e2eRateLimitIp allows 3 submissions per IP per window. A 4th must be blocked.
test('per-IP rate limit blocks submissions beyond the form limit (#4)', async ({ request }) => {
  // Reset cached rate-limit buckets so the count starts clean.
  craft(['clear-caches/data']);

  const fid = formId('e2eRateLimitIp');

  for (let i = 0; i < 3; i++) {
    const ok = await apiSubmit(request, {
      formId: fid,
      fields: { name: `RL${i}`, email: `rl-${Date.now()}-${i}@example.test` },
    });
    expect(ok.success, `submission ${i} should succeed`).toBe(true);
  }

  const blocked = await apiSubmit(request, {
    formId: fid,
    fields: { name: 'RL4', email: `rl-block-${Date.now()}@example.test` },
  });
  expect(blocked.success).toBe(false);
  expect(blocked.error).toMatch(/too many/i);
});
