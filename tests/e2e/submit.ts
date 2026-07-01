import { type APIRequestContext } from '@playwright/test';

// Direct (non-browser) submission helpers. These POST to the submit action the
// way a script/bot would — bypassing the client-side JS — so they exercise the
// server-side guarantees (validation, honeypot, allowlist, file checks).

const JSON_HEADERS = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };

async function csrfToken(request: APIRequestContext): Promise<string> {
  const res = await request.get('/actions/easy-form/forms/get-csrf-token', { headers: JSON_HEADERS });
  return (await res.json()).csrfToken;
}

export type Fields = Record<string, string | string[]>;

function flatten(fields: Fields): Record<string, string> {
  const out: Record<string, string> = {};
  for (const [k, v] of Object.entries(fields)) {
    if (Array.isArray(v)) v.forEach((vv, i) => (out[`fields[${k}][${i}]`] = vv));
    else out[`fields[${k}]`] = v;
  }
  return out;
}

/** POST a urlencoded submission. `extra` carries non-field params (honeypot, unknown keys). */
export async function apiSubmit(
  request: APIRequestContext,
  opts: { formId: string; siteId?: string; fields?: Fields; extra?: Record<string, string> },
): Promise<any> {
  const form = {
    action: 'easy-form/submissions/submit',
    CRAFT_CSRF_TOKEN: await csrfToken(request),
    formId: opts.formId,
    siteId: opts.siteId ?? '1',
    ...flatten(opts.fields ?? {}),
    ...(opts.extra ?? {}),
  };
  const res = await request.post('/', { headers: JSON_HEADERS, form });
  return res.json();
}

/**
 * POST to an arbitrary plugin action (e.g. submissions/update-status, bulk).
 * Arrays are sent as name[i]=value. Returns the parsed JSON response.
 */
export async function actionJson(
  request: APIRequestContext,
  action: string,
  body: Record<string, string | number | Array<string | number>>,
): Promise<any> {
  const form: Record<string, string> = {
    action,
    CRAFT_CSRF_TOKEN: await csrfToken(request),
  };
  for (const [k, v] of Object.entries(body)) {
    if (Array.isArray(v)) v.forEach((vv, i) => (form[`${k}[${i}]`] = String(vv)));
    else form[k] = String(v);
  }
  const res = await request.post('/', { headers: JSON_HEADERS, form });
  return res.json();
}

/** POST to an action that redirects (e.g. forms/import); returns the final status. */
export async function actionPost(
  request: APIRequestContext,
  action: string,
  body: Record<string, string>,
): Promise<number> {
  // Use the dedicated /actions/ route. Don't follow the redirect these actions
  // issue on success (a 302 to a CP page) — following it can land on a 404 for
  // the action URL; the 302 itself signals the action ran.
  const form = { CRAFT_CSRF_TOKEN: await csrfToken(request), ...body };
  const res = await request.post(`/actions/${action}`, { form, maxRedirects: 0 });
  return res.status();
}

/** POST a multipart submission carrying a single uploaded file. */
export async function apiSubmitWithFile(
  request: APIRequestContext,
  opts: {
    formId: string;
    siteId?: string;
    fields?: Fields;
    file: { handle: string; name: string; mimeType: string; buffer: Buffer };
  },
): Promise<any> {
  const multipart: Record<string, any> = {
    action: 'easy-form/submissions/submit',
    CRAFT_CSRF_TOKEN: await csrfToken(request),
    formId: opts.formId,
    siteId: opts.siteId ?? '1',
    ...flatten(opts.fields ?? {}),
  };
  multipart[`fields[${opts.file.handle}]`] = {
    name: opts.file.name,
    mimeType: opts.file.mimeType,
    buffer: opts.file.buffer,
  };
  const res = await request.post('/', { headers: JSON_HEADERS, multipart });
  return res.json();
}

/** POST a multipart submission carrying multiple files under one field handle. */
export async function apiSubmitWithFiles(
  request: APIRequestContext,
  opts: {
    formId: string;
    siteId?: string;
    fields?: Fields;
    handle: string;
    files: { name: string; mimeType: string; buffer: Buffer }[];
  },
): Promise<any> {
  const multipart: Record<string, any> = {
    action: 'easy-form/submissions/submit',
    CRAFT_CSRF_TOKEN: await csrfToken(request),
    formId: opts.formId,
    siteId: opts.siteId ?? '1',
    ...flatten(opts.fields ?? {}),
  };
  // fields[handle][i] → Yii's getInstancesByName('fields[handle]') collects all.
  opts.files.forEach((f, i) => {
    multipart[`fields[${opts.handle}][${i}]`] = { name: f.name, mimeType: f.mimeType, buffer: f.buffer };
  });
  const res = await request.post('/', { headers: JSON_HEADERS, multipart });
  return res.json();
}
