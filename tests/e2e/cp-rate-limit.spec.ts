import { test, expect } from '@playwright/test';
import { apiSubmit } from './submit';
import { formId, deleteSubmissions } from './db';

// maxSubmissionsPerUser is enforced only for logged-in users, so this runs in
// the authenticated (cp) project — the request fixture carries the admin session.
// e2eRateLimit caps at 1 submission per user.

test('per-user submission cap blocks the second submission', async ({ request }) => {
  const fid = formId('e2eRateLimit');
  // Start from a clean slate for this user/form so the cap is deterministic.
  deleteSubmissions('e2eRateLimit');

  const first = await apiSubmit(request, { formId: fid, fields: { name: 'First' } });
  expect(first.success).toBe(true);

  const second = await apiSubmit(request, { formId: fid, fields: { name: 'Second' } });
  expect(second.success).toBe(false);
  expect(second.error).toMatch(/maximum number of submissions/i);
});
