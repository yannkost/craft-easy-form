import { test, expect } from '@playwright/test';
import { apiSubmit } from './submit';
import { formId } from './db';
import { processQueue } from './queue';
import { clearMailpit, waitForMessages } from './mailpit';

// The submission detail page (submissions/view) renders the stored values and
// offers a "Resend notification" action. e2eNotifyCc is the only seeded form
// with a configured notification, so the resend modal is available there.
const unique = (tag: string) => `${tag}-${Date.now()}-${Math.floor(Math.random() * 1e6)}`;

test('CP: the submission detail page renders the stored values', async ({ page, request }) => {
  const name = 'Viewer ' + unique('n');
  const email = `${unique('view')}@example.test`;
  const res = await apiSubmit(request, {
    formId: formId('e2eNotifyCc'),
    fields: { name, email },
  });
  expect(res.success).toBe(true);

  await page.goto(`/cp/easy-form/submissions/${res.submissionId}`);

  // The data card lists each submitted field's label and value.
  const dataCard = page.locator('.ef-data-card');
  await expect(dataCard).toBeVisible();
  await expect(dataCard).toContainText(name);
  // The email value renders as a mailto link.
  await expect(dataCard.locator(`a[href="mailto:${email}"]`)).toBeVisible();
});

test('CP: resending a notification re-queues the email to the override recipient', async ({ page, request }) => {
  const email = `${unique('resend')}@example.test`;
  const res = await apiSubmit(request, {
    formId: formId('e2eNotifyCc'),
    fields: { name: 'Resend Me', email },
  });
  expect(res.success).toBe(true);

  // The submission's own notification fires on submit; drain + clear it so we
  // only observe the manual resend below.
  processQueue();
  await clearMailpit(request);

  const override = `${unique('override')}@example.test`;
  await page.goto(`/cp/easy-form/submissions/${res.submissionId}`);

  // Open the resend modal, target a fresh recipient, send.
  await page.locator('#ef-resend').click();
  const overlay = page.locator('#ef-notif-overlay');
  await expect(overlay).toHaveClass(/active/);
  await page.locator('#ef-notif-to').fill(override);
  await page.locator('#ef-notif-form button[type="submit"]').click();

  // The action queues a SendNotificationJob; run the queue and assert delivery
  // to the override address (proving the resend path, not the original send).
  await expect(overlay).not.toHaveClass(/active/);
  processQueue();
  const msgs = await waitForMessages(request, override);
  expect(msgs[0].Subject).toContain('New CC/BCC submission');
});
