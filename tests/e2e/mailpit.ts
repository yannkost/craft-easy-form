import { type APIRequestContext, expect } from '@playwright/test';

// Mailpit (bundled with DDEV) captures all outgoing mail. Its HTTP API lets the
// e2e suite assert on what the plugin actually sent.
// Default: same host as the site, on DDEV's Mailpit port 8026.
export function mailpitBaseUrl(): string {
  if (process.env.EASY_FORM_MAILPIT_URL) return process.env.EASY_FORM_MAILPIT_URL;
  const site = process.env.EASY_FORM_BASE_URL || 'https://plugins-tester.ddev.site';
  return site.replace(/(:\d+)?$/, '') + ':8026';
}

/** Delete every captured message. */
export async function clearMailpit(request: APIRequestContext): Promise<void> {
  const res = await request.delete(`${mailpitBaseUrl()}/api/v1/messages`);
  expect(res.ok()).toBeTruthy();
}

type Address = { Address: string; Name?: string };
type Summary = { ID: string; Subject: string; To: Address[]; Cc?: Address[]; Bcc?: Address[] };

/** Poll until at least `min` messages match the recipient, then return summaries. */
export async function waitForMessages(
  request: APIRequestContext,
  toAddress: string,
  min = 1,
  timeoutMs = 15_000,
): Promise<Summary[]> {
  const deadline = Date.now() + timeoutMs;
  for (;;) {
    const res = await request.get(`${mailpitBaseUrl()}/api/v1/messages`);
    if (res.ok()) {
      const body = await res.json();
      const matches: Summary[] = (body.messages || []).filter((m: Summary) =>
        (m.To || []).some((t) => t.Address.toLowerCase() === toAddress.toLowerCase()),
      );
      if (matches.length >= min) return matches;
    }
    if (Date.now() > deadline) {
      throw new Error(`Timed out waiting for ${min} message(s) to ${toAddress}`);
    }
    await new Promise((r) => setTimeout(r, 400));
  }
}

/** Fetch a single message's rendered HTML body. */
export async function messageHtml(request: APIRequestContext, id: string): Promise<string> {
  const res = await request.get(`${mailpitBaseUrl()}/api/v1/message/${id}`);
  expect(res.ok()).toBeTruthy();
  const body = await res.json();
  return (body.HTML as string) || (body.Text as string) || '';
}

/** Fetch a single message's attachment list (FileName + Size). */
export async function messageAttachments(
  request: APIRequestContext,
  id: string,
): Promise<{ FileName: string; Size: number }[]> {
  const res = await request.get(`${mailpitBaseUrl()}/api/v1/message/${id}`);
  expect(res.ok()).toBeTruthy();
  const body = await res.json();
  return (body.Attachments as { FileName: string; Size: number }[]) || [];
}
