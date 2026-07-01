import { test, expect } from '@playwright/test';
import { apiSubmit } from './submit';
import { formId, dataValueByEmail } from './db';

const uniqueEmail = (tag: string) => `${tag}-${Date.now()}-${Math.floor(Math.random() * 1e6)}@example.test`;

// e2eCondPage's second page is shown only when plan=full. A field on a hidden
// page must be discarded even if its value is posted (page-level anti-tamper),
// and kept when the page is visible.
test.describe('Conditional pages', () => {
  test('a field on a hidden page is discarded; on a visible page it is stored', async ({ request }) => {
    const fid = formId('e2eCondPage');

    const basic = uniqueEmail('basic');
    const hidden = await apiSubmit(request, {
      formId: fid,
      fields: { name: 'Bob', email: basic, plan: 'basic', addons: 'SNEAKY' },
    });
    expect(hidden.success).toBe(true);
    expect(dataValueByEmail('e2eCondPage', '$.values.addons', basic)).toBe('NULL');

    const full = uniqueEmail('full');
    const shown = await apiSubmit(request, {
      formId: fid,
      fields: { name: 'Ann', email: full, plan: 'full', addons: 'PRO' },
    });
    expect(shown.success).toBe(true);
    expect(dataValueByEmail('e2eCondPage', '$.values.addons', full)).toBe('"PRO"');
  });
});
