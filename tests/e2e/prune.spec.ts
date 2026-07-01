import { test, expect } from '@playwright/test';
import { apiSubmit } from './submit';
import { formId, countByEmail, backdateByEmail, craft } from './db';

const uniqueEmail = (tag: string) => `${tag}-${Date.now()}-${Math.floor(Math.random() * 1e6)}@example.test`;

// Retention pruning deletes submissions older than the cutoff. e2ePrune is a
// dedicated form so back-dated rows don't affect other tests.
test.describe('Retention pruning', () => {
  test('prunes submissions older than the cutoff, keeps recent ones (and --dry-run deletes nothing)', async ({ request }) => {
    const fid = formId('e2ePrune');
    const oldEmail = uniqueEmail('old');
    const newEmail = uniqueEmail('new');

    await apiSubmit(request, { formId: fid, fields: { name: 'Old', email: oldEmail } });
    await apiSubmit(request, { formId: fid, fields: { name: 'New', email: newEmail } });
    backdateByEmail('e2ePrune', oldEmail, 400); // 400 days ago

    // Dry run reports a count but changes nothing.
    const dry = craft(['easy-form/submissions/prune', '--days=365', `--form-id=${fid}`, '--dry-run']);
    expect(dry).toMatch(/\[dry run\]\s+\d+ submission\(s\) older than 365 days/);
    expect(countByEmail('e2ePrune', oldEmail)).toBe(1);
    expect(countByEmail('e2ePrune', newEmail)).toBe(1);

    // Real prune removes only the back-dated row.
    const out = craft(['easy-form/submissions/prune', '--days=365', `--form-id=${fid}`]);
    expect(out).toMatch(/Pruned \d+ submission\(s\) older than 365 days/);
    expect(countByEmail('e2ePrune', oldEmail)).toBe(0);
    expect(countByEmail('e2ePrune', newEmail)).toBe(1);
  });
});
