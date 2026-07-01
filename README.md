# Easy Form

A Craft CMS plugin for building forms and managing submissions, designed for
**flexible frontend modeling** and **high submission volumes**.

Forms and submissions are stored in custom database tables (`easyform_forms`,
`easyform_submissions`) — **not** as Craft elements — to avoid element/content
table overhead at scale. Form definitions and submission payloads are stored as
JSON, with a deliberate contract around the payload so flexible data stays
operationally safe.

The control panel — submissions list/detail, forms index, the form builder, and
settings (tabbed by type) — uses a consistent, refined card-based UI.

## Requirements

- Craft CMS 5.8.0 or later
- PHP 8.2 or later

## Installation

```bash
composer require yannkost/craft-easy-form
./craft plugin/install easy-form
```

Fresh installs create the final schema directly. Existing installs are migrated
automatically (legacy `formbuilder_*` tables are renamed and upgraded).

## Rendering a form

Use the `easyForm()` Twig function with a form handle, id, or `Form` model:

```twig
{{ easyForm('contact') }}

{# with options #}
{{ easyForm('contact', { submitButtonText: 'Send', class: 'easy-form my-form' }) }}
```

Options: `class`, `submitButtonText`, `submitButtonClass`, `prevButtonText`,
`nextButtonText`, `disableFrontendValidation`, and `includeStyles`.

### Default styles

A bundled stylesheet (`form-render.css`) is loaded by default. Turn it off
globally in **Settings → General → Include Default Form Styles** to style forms
with your own CSS, or per render: `{{ easyForm('contact', { includeStyles: false }) }}`.
The JavaScript (AJAX submit + events) always loads.

#### Styling hooks (validation messages & required marks)

Validation messages and required indicators carry stable classes so you can
restyle — or hide — them with your own CSS. There is no setting to suppress
message text: hide it with a style rule instead.

| Element | Class(es) |
| --- | --- |
| Any validation message (both of the two below) | `.easy-form-error-message` |
| Live (client-side) field message | `.field-error` |
| Server/AJAX field message | `.easy-form-field-error` |
| Invalid field (the input itself) | `.error` |
| Form-level banner (after submit) | `.easy-form-message` (`.error` / `.success`) |
| Required indicator (the `*`) | `.required` |

Hide all field-level validation text but keep the error styling (e.g. the red
outline on `.error`):

```css
.easy-form-error-message { display: none; }
```

Hide the required asterisks:

```css
.easy-form-field .required { display: none; }
```

### Form behavior (per-form toggles)

Each form's **Behavior** tab has:

- **Redirect URL** / **Hide Form on Success** — what happens after a submit.
- **Pre-fill from URL** — when on, fields are pre-filled from matching query
  params on page load, e.g. `…/contact?email=jane@acme.com&name=Jane` fills the
  `email` and `name` fields (matched by handle). Off by default; only forms you
  opt in are auto-filled. The form is tagged `data-url-prefill="true"` and the
  JS fills text inputs, textareas, selects, radios and checkboxes.
- **Show Step Indicator** — on multi-page forms, renders a numbered
  `.easy-form-steps` indicator above the form; the current step gets
  `.is-active` and completed steps `.is-complete`.

### Presentational fields

Alongside input fields, the builder offers **render-only** fields that carry no
value and are never validated or stored:

- **Heading** — an `<h2>`/`<h3>`/`<h4>` (text taken from the field label, so it's
  translatable on the Labels tab).
- **Divider** — a horizontal rule.
- **Callout** — an info / warning / success box with an optional hex accent
  color.

### Building your own markup

To render a form yourself, get the layout instead of the HTML with
`easyFormLayout()` — it returns the `Form` model, so you can loop it:

```twig
{% set form = easyFormLayout('contact') %}
{% for page in form.pages %}
  {% for row in page.rows %}
    {% for field in row.fields %}
      {{ field.label }} ({{ field.type }}) → name="fields[{{ field.handle }}]"
    {% endfor %}
  {% endfor %}
{% endfor %}
```

Forms post to `easy-form/submissions/submit` with field values namespaced under
`fields[...]`:

```html
<input type="email" name="fields[email]">
```

## Selecting a form in an entry (the Form field)

Easy Form registers a **Form** custom field. Add it to any element's field
layout — entries, categories, users, assets, Matrix/CKEditor blocks — and
authors get a dropdown to pick one of your forms.

In templates the field returns the selected `Form` model, which you can hand
straight to `easyForm()` (no need to dig out the handle):

```twig
{{ easyForm(entry.myFormField) }}
```

When no form is selected — or the chosen form was later deleted — the field is
`null` and `easyForm()` renders nothing, so it's safe to call unconditionally.
Add a guard only if surrounding markup should depend on it:

```twig
{% if entry.myFormField %}
  <section class="contact">
    {{ easyForm(entry.myFormField) }}
  </section>
{% endif %}
```

The selected form's name shows in element index columns and cards, and is
included in element search keywords.

## Conditional visibility

Pages, **rows**, and fields can each carry a `conditions` block that shows/hides
them based on another field's value:

```json
{ "action": "show", "logic": "all",
  "rules": [ { "field": "type", "operator": "equals", "value": "business" } ] }
```

Operators: `equals`, `notEquals`, `contains`, `notContains`, `isEmpty`,
`isNotEmpty`. `logic` is `all` (AND) or `any` (OR) — use multiple rules with
`any` for OR. Rules compare against the **raw submitted value for the current
site**, so authors keep option values aligned (or add per-site rules).

Visibility is hierarchical: a hidden page hides its rows and fields; a hidden
row hides its fields. Hidden fields are:

- excluded from frontend validation and from the submitted payload (their DOM
  value is kept, so re-showing restores it);
- re-evaluated and discarded server-side as defense in depth.

Configure them in the builder: a field's **Conditions** tab, or a row's
**Conditions** tab.

## JavaScript events & hooks

`form-submit.js` dispatches `easyform:*` `CustomEvent`s on the `<form>` element
at each step. They **bubble**, so you can listen on the form or on `document`:

```js
document.addEventListener('easyform:success', (e) => {
    // e.target is the form; e.detail.response is the server JSON
    gtag('event', 'form_submit', { form: e.detail.formHandle });
});
```

| Event | When | `e.detail` | Cancelable |
|------|------|-----------|:---:|
| `easyform:init` | each form initialized | — | — |
| `easyform:beforevalidate` | before client validation | — | — |
| `easyform:invalid` | client validation failed | `errors[]` (`{field, message}`) | — |
| `easyform:beforesubmit` | validation passed, before POST | — | ✅ `preventDefault()` stops it |
| `easyform:submit` | request about to be sent | `formData` | — |
| `easyform:success` | server accepted | `response` (parsed JSON) | — |
| `easyform:error` | server rejected / network error | `response` / `error` | — |
| `easyform:pagechange` | multi-page step changed | `from`, `to`, `total` | — |

Every `detail` also carries `form` and `formHandle`. The native `reset` event
fires after a successful (non-hidden) submit, when the form is cleared.

A callback registry is also available for backward compatibility
(`window.registerFormCallback(handle, 'init'|'beforeSubmit', fn)`; `beforeSubmit`
gets a validation helper and must `return true` to proceed), but the events above
are the recommended API.

## Frontend fields (the allowlist)

Beyond the fields created in the form builder, a form can declare **frontend
fields** — handles that frontend templates are allowed to submit (e.g. a
dynamically-rendered list of entry checkboxes, tracking/UTM data). This keeps
frontend freedom while rejecting arbitrary/tampered keys.

Each form layout carries an `extraFieldPolicy`:

| Policy        | Behavior                                                        |
|---------------|-----------------------------------------------------------------|
| `strict`      | Only form-builder fields are accepted                           |
| `allowListed` | Form-builder fields + declared `frontendFields` (default)       |
| `open`        | All submitted fields accepted, subject to global safety limits  |

Declared frontend fields are coerced to one of a few broad primitive types —
`string`, `number`, `boolean`, `array` — and may set `maxItems` / `maxLength`.
For structured data, `JSON.stringify()` it client-side into a `string` (a
`string` field also auto-encodes any non-scalar value to JSON, so nothing is
lost). They intentionally do **not** model element relations
(entry/asset IDs): the plugin can't guarantee those elements exist, so values
are stored as plain scalars/arrays and left for the consumer to interpret.
Each frontend field also has a label (with optional per-site overrides) used in
the CP, exports and email notifications.

## Stored submission shape

Submissions are stored canonically:

```json
{
  "schemaVersion": 1,
  "values":   { "email": "jane@example.com", "message": "Hi" },
  "frontend": { "selectedProducts": [12, 18, 44] },
  "meta": {
    "formSchemaVersion": 3,
    "knownFieldHandles": ["email", "message"],
    "frontendFieldHandles": ["selectedProducts"],
    "visibleFieldHandles": ["email", "message"],
    "unknownFieldPolicy": "allowListed"
  }
}
```

Common metadata is also promoted to real columns (`primaryEmail`, `primaryName`,
`source`) via the form's `promotedFields` map, plus a `fieldSnapshot` so old
submissions remain readable after a form changes or is deleted.

## Exports

The **Exports** screen (its own CP nav item) lists every form. Pick a form and a
modal lets you filter by **site**, **status**, and **date range**, then either
**download** the matching submissions as CSV or **delete** them — delete shows
the matching count and an "are you sure" confirmation, and is permanent. The
Submissions screen also has an inline **Export CSV** scoped to the current
filter.

Exports are **always queued** — triggering one enqueues a background job and
lands you on a "preparing → ready" status page that polls and auto-downloads when
the file is built. Endpoints (driven by the UI; all require
`easy-form:exportSubmissions`):

```
POST /admin/easy-form/submissions/export                       # enqueue → { token, statusUrl }
GET  /admin/easy-form/submissions/export-check?key=<token>     # { ready, downloadUrl }
GET  /admin/easy-form/submissions/export-download?key=<token>  # streams the CSV
```

The job reads rows in batches without hydrating models, so large datasets do not
exhaust memory. Header order follows the form schema, with a trailing `Extra`
column for any non-schema handles. The finished file is written to
`storage/runtime/easy-form-exports/` (not web-accessible), keyed by a one-time
token and garbage-collected after 24h.

## Spam protection

- **Honeypot** — every form includes a hidden honeypot; a filled value is
  silently treated as spam.
- **Blocked keywords** — set newline/comma-separated keywords in **Settings →
  Email Validation → Blocked Keywords**. A submission whose field values contain
  any of them (case-insensitive) is silently rejected as spam (global).
- **Blocked email domains** and per-form **CAPTCHA** (Turnstile / reCAPTCHA
  v2 / v3) are also available. CAPTCHA transport failures **fail open** by
  default; set **`captchaFailOpen` → false** to reject instead during outages.
- **Rate limit** — each form's *Behavior* tab has a per-IP **Rate Limit** /
  **Window** (0 = off): at most N submissions from one IP per window
  (HTTP 429 when exceeded). This is the anonymous complement to the logged-in
  *Max Submissions Per User*.

Spam submissions are discarded unless the form opts to **save spam submissions**.

## Notifications

Per notification you can set recipients, subject, sender, **Reply-To**, and
**CC** / **BCC**. CC and BCC accept comma-separated addresses and the same
`{fieldHandle}` placeholder as recipients (resolved to a submitted email).

- **Conditional sending** — a notification can be limited to submissions that
  match (or don't match) a set of field rules, reusing the same condition
  engine as conditional fields. "Always send" is the default.
- **File attachments** — toggle *Attach uploaded files* to attach the
  submission's uploads to the email. Files larger than **Settings → File
  Uploads → Max Attachment Size** are skipped (the submission keeps the file).

## Webhooks

Each form can POST every new (non-spam) submission to a **Webhook URL** as JSON,
out of the request cycle (queued, with retry). Two payload modes:

- **Full** — `{ handle, formId, submissionId, dateCreated, siteId, values,
  frontend, meta }`.
- **Data only** — a flat `{ handle: value }` map of field values.

Authentication is added in PHP, not the CP — listen for
`Webhooks::EVENT_BEFORE_SEND` to add headers, mutate the payload, or cancel:

```php
use yii\base\Event;
use yannkost\easyform\services\Webhooks;
use yannkost\easyform\events\WebhookEvent;

Event::on(Webhooks::class, Webhooks::EVENT_BEFORE_SEND, function (WebhookEvent $e) {
    $e->headers['Authorization'] = 'Bearer ' . App::env('MY_WEBHOOK_TOKEN');
    // $e->isValid = false;  // cancel this webhook
});
```

The URL is validated (http/https) on save, and an **SSRF guard** refuses to post
to private / reserved / loopback hosts. To allow an internal endpoint, set
**`allowPrivateWebhookHosts` → true** in settings.

## Server-side events (extending submissions)

Submissions fire PHP events you can hook from your own module/plugin with
`Event::on(...)`. There are three, with deliberately different contracts:

### `SubmissionsController::EVENT_BEFORE_VALIDATE` — transform / inject data

Runs **before** validation. Changes you make to `$event->submissionData` are
applied and then flow through condition evaluation, validation, and
canonicalization (allow-list, size caps, control-char stripping) — i.e. they're
treated exactly like submitted data. This is the safe place to normalize,
default, enrich, or **inject a server-computed value**.

To store a value that isn't a builder field, declare it as a **frontend
(allow-listed) field** on the form, then set it here:

```php
use yii\base\Event;
use yannkost\easyform\controllers\SubmissionsController;
use yannkost\easyform\events\SubmissionValidationEvent;

Event::on(
    SubmissionsController::class,
    SubmissionsController::EVENT_BEFORE_VALIDATE,
    function (SubmissionValidationEvent $e) {
        // `serverTier` is declared as a frontend field on the form
        $e->submissionData['serverTier'] = lookupTier($e->submissionData['email'] ?? '');

        // …or reject the whole submission:
        // $e->isValid = false; $e->message = 'Nope';
    }
);
```

### `SubmissionsController::EVENT_AFTER_VALIDATE` — inspect / cancel only

Runs **after** validation. Use it to inspect the validated data and optionally
cancel (`$e->isValid = false`). Its `submissionData` is **read-only** — mutating
it has no effect (that would bypass validation and desync conditional
visibility). To change data, use `beforeValidate`.

### `Submissions::EVENT_BEFORE_SAVE_SUBMISSION` — full control

Runs with the fully-built `Submission` model, just before the DB write — past
the allow-list/sanitization pipeline. Mutating `$event->submission` (e.g.
`setData()`, promoted columns) **persists**. This is the explicit "I own the
final shape" hook for metadata or values you don't want treated as form fields.
There's also `EVENT_AFTER_SAVE_SUBMISSION` for side effects.

## Privacy & retention

- IP address and user agent are only stored when **Store IP addresses** is
  enabled in settings.
- Configure **Submission retention days** and prune old submissions:

  ```bash
  php craft easy-form/submissions/prune            # uses configured retention
  php craft easy-form/submissions/prune --days=90  # override
  php craft easy-form/submissions/prune --dry-run  # preview
  ```

- **Deletion is permanent.** Deleting submissions (CP, exports-page delete,
  prune, GDPR erase) is a hard delete — there is no trash/restore.

### Operational notes

- **Exports are always queued** and built off the request cycle (batched reads,
  no model hydration), so they scale to large datasets by default. A queue runner
  must be active for the file to be produced.
- **GDPR email lookup** matches the promoted `primaryEmail` column plus a JSON
  `LIKE` over the payload — portable, but consider a normalized index for
  high-volume privacy workflows.

## Debug logging

Verbose logging (payloads, recipients, layouts) is **off by default**. Enable it
only when debugging:

```bash
EASY_FORM_DEBUG=true
```

Warnings and errors are always logged to `storage/logs/easy-form.log`.

## Tests

Unit tests cover the core logic (schema migration/normalization, condition
evaluation, and the submission data contract). They are self-contained — no
Craft bootstrap or Composer install required — and run with the PHPUnit phar:

```bash
curl -sSL -o phpunit.phar https://phar.phpunit.de/phpunit-11.phar
php phpunit.phar
```

CI (`.github/workflows/tests.yml`) lints `src/` and runs the suite on PHP
8.2–8.4 for every push and pull request.

### End-to-end (Playwright)

Browser tests in `tests/e2e/` run against a running instance, in two layers:

- **frontend** — render forms (validation, multi-page stepper, conditional
  fields, AJAX submit), per-site **field-label translation**, notification
  **emails** (captured via Mailpit), **CAPTCHA**, and **server-side guarantees**
  driven by direct POSTs that bypass the client JS (validation enforcement,
  file-upload size/type checks, honeypot, allow-list stripping, conditional-
  field anti-tamper). No auth.
- **cp** — log into the control panel and drive the builder UI: create a form,
  add pages/rows/fields, set labels, toggle Required, define select options,
  add per-site label overrides, and build visibility conditions — then assert
  the result on the front end (or that it round-trips in the builder), save and
  delete. `auth.setup.ts` logs in once and saves the session; the `setup` and
  `cp` projects share a user agent so Craft accepts the session.

```bash
npm install && npx playwright install chromium
EASY_FORM_BASE_URL=https://your-site.ddev.site \
EASY_FORM_CP_USER=admin EASY_FORM_CP_PASS='…' \
npx playwright test
```

Frontend specs render forms via a tiny devMode-only template in the host project
(`templates/dev/form.twig` → `/dev/form?f=<handle>`) and rely on the seeded
fixtures below.

**Internationalisation (i18n) prerequisites.** The label/email translation
specs render the same form on a second (French) site and compare labels:

- A non-primary site (handle `french`, language `fr`) served from a `/fr`
  subdirectory — set `FRENCH_URL=<base>/fr` in the host project's `.env`.
- Mailpit (bundled with DDEV) to capture notification emails. Its API is read at
  `<base>:8026` by default; override with `EASY_FORM_MAILPIT_URL`.
- Notification emails are queued, and front-end submissions are AJAX (so Craft's
  auto queue-runner doesn't fire). The email specs drain the queue with
  `ddev exec php craft queue/run`; override via `EASY_FORM_QUEUE_CMD` /
  `EASY_FORM_DDEV_DIR`.
- The Twig-email spec uses a dev-only template in the host project
  (`templates/dev/email-i18n.twig`) that resolves labels via
  `form.resolveFieldLabel(handle, siteHandle)`.

Per-site field labels are resolved in three places, all covered by the suite:
the front end (`_render.twig`), the default PHP email (`renderDefaultEmail`),
and custom Twig email templates (which now receive a `siteHandle` variable).

**CAPTCHA prerequisites.** `captcha.spec.ts` covers Cloudflare Turnstile,
reCAPTCHA v2 and v3 using the providers' **official test keys** (deterministic,
no account). Configure them in the host project's `config/easy-form.php` (see
that file's comments) and ensure the app has outbound network — verification
POSTs to the real Cloudflare/Google `siteverify` endpoints. The suite tests the
rejection path for all three providers (block the widget script → no token →
server rejects) and the success path for Turnstile and v2. reCAPTCHA v3 has no
public always-pass key, so only its rejection path and score gate are covered
(the score gate is unit-tested in `CaptchaProviderTest`).

**Server-side / security specs.** `server-validation.spec.ts`, `security.spec.ts`
and `file-upload.spec.ts` submit directly to the action endpoint (via `submit.ts`,
which fetches a CSRF token then POSTs) so they test the server's enforcement
independently of the browser JS. Guarantees that are only observable in stored
data — honeypot drops, allow-list stripping, conditional-field anti-tamper — are
checked by reading the test DB (`db.ts`, via `ddev mysql`; override the project
dir with `EASY_FORM_DDEV_DIR`). `cp-rate-limit.spec.ts` runs authenticated to
exercise the per-user submission cap.

**Privacy / ops specs.** `gdpr.spec.ts` checks IP anonymization (the test
instance sets `ipStorageMode = 'anonymized'`) and the per-email export/erasure
console commands (`easy-form/privacy/export` / `forget`, run via `ddev`);
`prune.spec.ts` back-dates a submission and verifies retention pruning (and that
`--dry-run` deletes nothing); `cp-export.spec.ts` downloads the authenticated
submissions CSV and asserts the header and a submitted row.

**CP workflow & data specs.** `cp-submissions-workflow.spec.ts` drives the
authenticated submission actions (single status update, bulk set-status / delete,
and the search + status filters); `cp-import-export.spec.ts` round-trips a form
through the JSON export and import actions; `conditional-page.spec.ts` checks
page-level conditions (a field on a hidden page is discarded even when posted);
and `stored-values.spec.ts` asserts stored value shapes (select/date → string,
multiple checkboxes → array). Authenticated POSTs go through `submit.ts`'s
`actionJson` / `actionPost` (the latter doesn't follow the success redirect).

### Dev seeders

`Dev` console commands (devMode-gated) populate test data:

```bash
php craft easy-form/dev/seed-forms                # import tests/fixtures/forms/*.json (stable handles)
php craft easy-form/dev/seed-forms --random=20    # + 20 randomized forms
php craft easy-form/dev/seed-submissions --form=e2eContact --count=2000 --days=90
```

