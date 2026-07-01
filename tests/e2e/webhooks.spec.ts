import { test, expect, type APIRequestContext } from '@playwright/test';
import { apiSubmit } from './submit';
import { formId } from './db';
import { processQueue } from './queue';

// e2eWebhook (full) and e2eWebhookData (data-only) POST to web/webhook-sink.php,
// which records the last request to a runtime file readable via webhook-last.php.
// The tester's config/app.php registers a before-send handler that injects
// `X-Easy-Form-Test` for the sink URL, proving EVENT_BEFORE_SEND header control.

async function resetSink(request: APIRequestContext) {
  await request.get('/webhook-last.php?reset=1');
}

async function readSink(request: APIRequestContext, timeoutMs = 15_000): Promise<any> {
  const deadline = Date.now() + timeoutMs;
  for (;;) {
    const res = await request.get('/webhook-last.php');
    if (res.ok()) {
      const cap = await res.json();
      if (cap && cap.body) return cap;
    }
    if (Date.now() > deadline) throw new Error('Timed out waiting for a webhook capture');
    await new Promise((r) => setTimeout(r, 300));
  }
}

test.describe('Webhooks', () => {
  // The sink is a single shared file — run these serially so captures don't clobber.
  test.describe.configure({ mode: 'serial' });

  test('full payload: wrapped {values, frontend, meta} + before-send header injection', async ({ request }) => {
    await resetSink(request);

    const res = await apiSubmit(request, {
      formId: formId('e2eWebhook'),
      fields: { name: 'Hooky', email: 'hook@example.test', utm_source: 'newsletter' },
    });
    expect(res.success).toBe(true);
    processQueue();

    const cap = await readSink(request);
    expect(cap.method).toBe('POST');
    // EVENT_BEFORE_SEND added this header on the way out.
    expect(cap.headers['X-Easy-Form-Test']).toBe('before-send-ok');
    expect((cap.headers['Content-Type'] || '')).toContain('application/json');

    const body = JSON.parse(cap.body);
    expect(body.handle).toBe('e2eWebhook');
    expect(body.submissionId).toBeGreaterThan(0);
    expect(body.values).toMatchObject({ name: 'Hooky', email: 'hook@example.test' });
    expect(body.frontend).toMatchObject({ utm_source: 'newsletter' });
    expect(body.meta).toBeTruthy();
  });

  test('data-only payload: a flat {handle: value} map with no wrapper keys', async ({ request }) => {
    await resetSink(request);

    const res = await apiSubmit(request, {
      formId: formId('e2eWebhookData'),
      fields: { name: 'Flat', email: 'flat@example.test' },
    });
    expect(res.success).toBe(true);
    processQueue();

    const cap = await readSink(request);
    const body = JSON.parse(cap.body);
    expect(body).toMatchObject({ name: 'Flat', email: 'flat@example.test' });
    expect(body.values).toBeUndefined();
    expect(body.meta).toBeUndefined();
  });
});
