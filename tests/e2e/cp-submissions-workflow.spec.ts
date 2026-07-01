import { test, expect } from '@playwright/test';
import { apiSubmit, actionJson } from './submit';
import { formId, submissionStatus, submissionActive } from './db';

// The submission workflow (status changes, bulk actions, filtering) is in the
// authenticated CP. Runs in the cp project (admin session via storageState).

const uniqueEmail = (tag: string) => `${tag}-${Date.now()}-${Math.floor(Math.random() * 1e6)}@example.test`;

async function createSubmission(request: any, name: string): Promise<{ id: number; email: string }> {
  const email = uniqueEmail('wf');
  const res = await apiSubmit(request, {
    formId: formId('e2eContact'),
    fields: { name, email, message: 'workflow' },
  });
  expect(res.success).toBe(true);
  return { id: res.submissionId, email };
}

test.describe('CP submission workflow', () => {
  test('a single submission status can be updated', async ({ request }) => {
    const { id } = await createSubmission(request, 'StatusOne');
    expect(submissionStatus(id)).toBe('pending');

    const res = await actionJson(request, 'easy-form/submissions/update-status', { id, status: 'approved' });
    expect(res.success).toBe(true);
    expect(submissionStatus(id)).toBe('approved');
  });

  test('rejects an invalid status', async ({ request }) => {
    const { id } = await createSubmission(request, 'StatusBad');
    const res = await actionJson(request, 'easy-form/submissions/update-status', { id, status: 'banana' });
    expect(res.success).toBe(false);
    expect(submissionStatus(id)).toBe('pending');
  });

  test('bulk set-status and bulk delete affect every selected submission', async ({ request }) => {
    const a = (await createSubmission(request, 'BulkA')).id;
    const b = (await createSubmission(request, 'BulkB')).id;

    const setStatus = await actionJson(request, 'easy-form/submissions/bulk', {
      bulkAction: 'status',
      status: 'archived',
      ids: [a, b],
    });
    expect(setStatus.success).toBe(true);
    expect(setStatus.affected).toBe(2);
    expect(submissionStatus(a)).toBe('archived');
    expect(submissionStatus(b)).toBe('archived');

    const del = await actionJson(request, 'easy-form/submissions/bulk', { bulkAction: 'delete', ids: [a, b] });
    expect(del.success).toBe(true);
    expect(del.affected).toBe(2);
    expect(submissionActive(a)).toBe(false);
    expect(submissionActive(b)).toBe(false);
  });

  test('search and status filters scope the submissions list', async ({ request }) => {
    // Search by the (unique) name; assert presence/absence by the unique EMAIL,
    // which never appears in the query and so isn't echoed by the search box.
    const marker = 'FilterMark' + Date.now().toString().slice(-6);
    const { id, email } = await createSubmission(request, marker);
    const fid = formId('e2eContact');

    const found = await (await request.get(`/cp/easy-form/submissions?formId=${fid}&search=${marker}`)).text();
    expect(found).toContain(email);

    // A non-matching search excludes the row.
    const missing = await (await request.get(`/cp/easy-form/submissions?formId=${fid}&search=zzz-no-such-term-xyz`)).text();
    expect(missing).not.toContain(email);

    // Approve it: it shows under status=approved but not status=spam.
    await actionJson(request, 'easy-form/submissions/update-status', { id, status: 'approved' });
    const approved = await (await request.get(`/cp/easy-form/submissions?formId=${fid}&status=approved&search=${marker}`)).text();
    expect(approved).toContain(email);
    const spam = await (await request.get(`/cp/easy-form/submissions?formId=${fid}&status=spam&search=${marker}`)).text();
    expect(spam).not.toContain(email);
  });

  test('an injection-style search term is handled safely (no SQL error)', async ({ request }) => {
    const fid = formId('e2eContact');
    const evil = encodeURIComponent("'; DROP TABLE easyform_submissions; --");
    const resp = await request.get(`/cp/easy-form/submissions?formId=${fid}&search=${evil}`);
    // 200 — the search term is bound into the LIKE, so the query doesn't break.
    expect(resp.ok()).toBe(true);
    // It rendered the list page (empty state for a no-match term), not an error.
    expect(await resp.text()).toMatch(/ef-subs|ef-empty-state/);
    // And the table is still there afterwards.
    expect(formId('e2eContact')).not.toBe('');
  });
});
