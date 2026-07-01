import { test, expect, type Page } from '@playwright/test';
import { clearMailpit, waitForMessages, messageHtml, messageAttachments } from './mailpit';
import { processQueue } from './queue';
import { apiSubmit, apiSubmitWithFile } from './submit';
import { formId } from './db';

// The e2eI18n form has a notification (recipient easyform-i18n@example.test) and
// French label overrides. A submission's notification email must use the labels
// of the submission's site: English labels for the default site, French labels
// for the /fr site. This exercises the real send path (renderDefaultEmail), with
// Mailpit capturing the message.

const RECIPIENT = 'easyform-i18n@example.test';

async function submitI18nForm(page: Page, url: string, name: string, email: string, message: string) {
  await page.goto(url);
  await page.fill('input[name="fields[name]"]', name);
  await page.fill('input[name="fields[email]"]', email);
  await page.fill('textarea[name="fields[message]"]', message);
  await page.locator('form.easy-form button[type=submit]').click();
  await expect(page.locator('.easy-form-message.success')).toBeVisible();
  // Notification emails are queued; drain the queue so Mailpit receives them.
  processQueue();
}

test.describe('Notification emails use per-site (translated) labels', () => {
  // Mailpit is a shared mailbox; run these serially so each clears + reads its own mail.
  test.describe.configure({ mode: 'serial' });

  test('English submission → English labels in the email', async ({ page, request }) => {
    await clearMailpit(request);
    await submitI18nForm(page, '/dev/form?f=e2eI18n', 'Jane EN', 'jane-en@example.com', 'Hello in English');

    const [msg] = await waitForMessages(request, RECIPIENT);
    const html = await messageHtml(request, msg.ID);

    expect(html).toContain('Name');
    expect(html).toContain('Email');
    expect(html).toContain('jane-en@example.com');
    // The French overrides must NOT appear.
    expect(html).not.toContain('Courriel');
  });

  test('French submission → French labels in the email', async ({ page, request }) => {
    await clearMailpit(request);
    await submitI18nForm(page, '/fr/dev/form?f=e2eI18n', 'Jean FR', 'jean-fr@example.com', 'Bonjour');

    const [msg] = await waitForMessages(request, RECIPIENT);
    const html = await messageHtml(request, msg.ID);

    expect(html).toContain('Nom');
    expect(html).toContain('Courriel');
    expect(html).toContain('Message (fr)');
    expect(html).toContain('jean-fr@example.com');
    // The English labels must NOT appear.
    expect(html).not.toContain('Name');
    expect(html).not.toContain('Email');
  });

  test('Twig template email path also resolves per-site labels', async ({ page, request }) => {
    // e2eI18nTpl renders its notification via the Twig template dev/email-i18n,
    // which calls form.resolveFieldLabel(handle, siteHandle).
    await clearMailpit(request);
    await page.goto('/fr/dev/form?f=e2eI18nTpl');
    await page.fill('input[name="fields[name]"]', 'Twig FR');
    await page.fill('input[name="fields[email]"]', 'twig-fr@example.com');
    await page.locator('form.easy-form button[type=submit]').click();
    await expect(page.locator('.easy-form-message.success')).toBeVisible();
    processQueue();

    const [msg] = await waitForMessages(request, 'easyform-i18n-tpl@example.test');
    const html = await messageHtml(request, msg.ID);
    expect(html).toContain('Nom:');
    expect(html).toContain('Courriel:');
    expect(html).toContain('twig-fr@example.com');
    expect(html).not.toContain('Name');
  });

  test('every field type is translated in the notification email', async ({ page, request }) => {
    // e2eI18nAll has every field type with a French label override. The default
    // email lists every field's label, so a French submission must show them all
    // — including the hidden field, which has no front-end label.
    await clearMailpit(request);
    await page.goto('/fr/dev/form?f=e2eI18nAll');
    await page.fill('input[name="fields[emailField]"]', 'all-fr@example.com');
    await page.locator('form.easy-form button[type=submit]').click();
    await expect(page.locator('.easy-form-message.success')).toBeVisible();
    processQueue();

    const [msg] = await waitForMessages(request, 'easyform-i18n-all@example.test');
    const html = await messageHtml(request, msg.ID);

    const frenchLabels = [
      'Texte', 'Zone de texte', 'Courriel', 'Téléphone', 'Site web', 'Nombre',
      'Date FR', 'Choix', 'Cases à cocher', 'Fichier',
      'accepte les conditions', // agree (apostrophe is HTML-escaped in the email)
      'Suivi caché', // hidden field — only visible in the email
    ];
    for (const label of frenchLabels) {
      expect(html, `expected French label "${label}" in the email`).toContain(label);
    }
    // English base labels must not appear.
    for (const en of ['Textarea', 'Website', 'Agreement', 'Tracking']) {
      expect(html).not.toContain(en);
    }
  });

  // Lives in this serial block because it shares the (global) Mailpit mailbox:
  // running it here guarantees it never clears mail another spec is reading.
  test('a notification delivers to its CC and BCC recipients', async ({ request }) => {
    await clearMailpit(request);

    const res = await apiSubmit(request, {
      formId: formId('e2eNotifyCc'),
      fields: { name: 'CcBcc Tester', email: 'submitter@example.test' },
    });
    expect(res.success).toBe(true);
    processQueue();

    const msgs = await waitForMessages(request, 'notify-to@example.test');
    const msg = msgs.find((m) => m.Subject === 'New CC/BCC submission');
    expect(msg, 'expected the CC/BCC notification message').toBeTruthy();

    const addrs = (list?: { Address: string }[]) => (list || []).map((a) => a.Address.toLowerCase());
    expect(addrs(msg!.To)).toContain('notify-to@example.test');
    expect(addrs(msg!.Cc)).toContain('notify-cc@example.test');
    expect(addrs(msg!.Bcc)).toContain('notify-bcc@example.test');
  });

  // e2eCondNotify only sends when topic = "sales".
  test('a conditional notification sends only when its rules match', async ({ request }) => {
    await clearMailpit(request);
    const RECIPIENT = 'cond-notify@example.test';

    // Non-matching topic → must NOT send.
    const skip = await apiSubmit(request, {
      formId: formId('e2eCondNotify'),
      fields: { name: 'Skip', email: 'skip@example.test', topic: 'support' },
    });
    expect(skip.success).toBe(true);
    processQueue();

    // Matching topic → must send.
    const send = await apiSubmit(request, {
      formId: formId('e2eCondNotify'),
      fields: { name: 'Send', email: 'send@example.test', topic: 'sales' },
    });
    expect(send.success).toBe(true);
    processQueue();

    // Exactly one message reached the recipient — the matching one.
    const msgs = await waitForMessages(request, RECIPIENT);
    expect(msgs).toHaveLength(1);
    const html = await messageHtml(request, msgs[0].ID);
    expect(html).toContain('send@example.test');
    expect(html).not.toContain('skip@example.test');
  });

  // e2eAttach attaches uploaded files; the tester caps attachments at 1 MB.
  test('an uploaded file is attached to the notification when it fits', async ({ request }) => {
    await clearMailpit(request);

    const res = await apiSubmitWithFile(request, {
      formId: formId('e2eAttach'),
      fields: { name: 'Atty', email: 'sub@example.test' },
      file: { handle: 'document', name: 'notes.txt', mimeType: 'text/plain', buffer: Buffer.from('hello attachment world') },
    });
    expect(res.success).toBe(true);
    processQueue();

    const [msg] = await waitForMessages(request, 'attach@example.test');
    const attachments = await messageAttachments(request, msg.ID);
    expect(attachments.map((a) => a.FileName)).toContain('notes.txt');
  });

  test('an uploaded file over the attachment-size limit is skipped (but the email still sends)', async ({ request }) => {
    await clearMailpit(request);

    // 2 MB — under the field's 5 MB upload cap, over the 1 MB attachment cap.
    const res = await apiSubmitWithFile(request, {
      formId: formId('e2eAttach'),
      fields: { name: 'Big', email: 'big@example.test' },
      file: { handle: 'document', name: 'big.txt', mimeType: 'text/plain', buffer: Buffer.alloc(2 * 1024 * 1024, 0x61) },
    });
    expect(res.success).toBe(true);
    processQueue();

    const [msg] = await waitForMessages(request, 'attach@example.test');
    const attachments = await messageAttachments(request, msg.ID);
    expect(attachments).toHaveLength(0);
  });

  // e2eNotifyContent uses a custom-content notification whose body is:
  //   "First line\nSecond line {label[name]|b}: {field[name]}"
  // The rendered email must keep the author's newline as <br> (no Markdown
  // collapse) and bold the |b token via <strong>, with the value inserted.
  test('custom content keeps newlines and bolds a |b token', async ({ request }) => {
    await clearMailpit(request);

    const res = await apiSubmit(request, {
      formId: formId('e2eNotifyContent'),
      fields: { name: 'Alice', email: 'alice@example.com' },
    });
    expect(res.success).toBe(true);
    processQueue();

    const [msg] = await waitForMessages(request, 'notify-content@example.test');
    const html = await messageHtml(request, msg.ID);

    expect(html).toMatch(/First line<br\s*\/?>/);   // newline preserved
    expect(html).toContain('<strong>Name</strong>'); // |b label bolded
    expect(html).toContain('Alice');                 // value inserted
  });

  // e2eNotifyMarkdown uses contentFormat: markdown.
  test('markdown content renders headings/bold, keeps newlines, strips images', async ({ request }) => {
    await clearMailpit(request);

    const res = await apiSubmit(request, {
      formId: formId('e2eNotifyMarkdown'),
      fields: { name: 'Alice', email: 'alice@example.com' },
    });
    expect(res.success).toBe(true);
    processQueue();

    const [msg] = await waitForMessages(request, 'notify-md@example.test');
    const html = await messageHtml(request, msg.ID);

    expect(html).toMatch(/<h1>Hello Alice<\/h1>/); // heading + token value
    expect(html).toContain('<strong>line</strong>'); // **bold**
    expect(html).not.toContain('<img');              // images disallowed
  });

  // e2eNotifySiteToggle has siteEnabled { default: true, french: false }.
  test('per-site toggle: sends for an enabled site, skips a disabled one', async ({ request }) => {
    await clearMailpit(request);

    // French submission (siteId 2) — the notification is OFF for that site.
    const fr = await apiSubmit(request, {
      formId: formId('e2eNotifySiteToggle'),
      siteId: '2',
      fields: { name: 'Jean', email: 'jean@example.com' },
    });
    expect(fr.success).toBe(true);
    // Default-site submission (siteId 1) — ON, so this one must send.
    const en = await apiSubmit(request, {
      formId: formId('e2eNotifySiteToggle'),
      siteId: '1',
      fields: { name: 'Jane', email: 'jane@example.com' },
    });
    expect(en.success).toBe(true);
    processQueue();

    // Exactly one email — the default-site one. (waitForMessages also proves the
    // French submission produced no message to this recipient.)
    const msgs = await waitForMessages(request, 'notify-toggle@example.test');
    expect(msgs).toHaveLength(1);
    const html = await messageHtml(request, msgs[0].ID);
    expect(html).toContain('Jane');
    expect(html).not.toContain('Jean');
  });

  // e2eNotifyTable uses content "{table[name,message]}".
  test('{table[…]} renders a Label | Value table of the selected fields', async ({ request }) => {
    await clearMailpit(request);

    const res = await apiSubmit(request, {
      formId: formId('e2eNotifyTable'),
      fields: { name: 'Alice', email: 'alice@example.com', message: 'Hello there' },
    });
    expect(res.success).toBe(true);
    processQueue();

    const [msg] = await waitForMessages(request, 'notify-table@example.test');
    const html = await messageHtml(request, msg.ID);

    expect(html).toContain('<table');          // a real table was rendered
    expect(html).toContain('Name');            // selected field label
    expect(html).toContain('Alice');           // its value
    expect(html).toContain('Message');         // second selected field
    expect(html).toContain('Hello there');
    // email was NOT in the token list, so it stays out of the table.
    expect(html).not.toContain('alice@example.com');
  });

  // Regression: a file field's value is a nested array. A {table}/{combo} token
  // resolving it used to throw "Array to string conversion" and drop the email.
  test('a file field in {table}/{combo} content renders its filename, not a crash', async ({ request }) => {
    await clearMailpit(request);

    const res = await apiSubmitWithFile(request, {
      formId: formId('e2eNotifyFileTable'),
      fields: { name: 'Filer', email: 'filer@example.test' },
      file: { handle: 'document', name: 'report.txt', mimeType: 'text/plain', buffer: Buffer.from('file body') },
    });
    expect(res.success).toBe(true);
    processQueue();

    const [msg] = await waitForMessages(request, 'notify-filetable@example.test');
    const html = await messageHtml(request, msg.ID);

    expect(html).toContain('<table');       // table rendered (didn't crash)
    expect(html).toContain('report.txt');   // file value flattened to its filename
    expect(html).toContain('Filer');        // other fields still render
  });
});
