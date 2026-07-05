# Development

Maintainer notes for working on Easy Form — running the test suites and seeding
dev data. Easy Form is a commercial, proprietary plugin; this file documents the
internal development workflow rather than inviting external contributions.

## Unit tests

Unit tests cover the core logic (schema migration/normalization, condition
evaluation, and the submission data contract). They are self-contained — no
Craft bootstrap or Composer install required — and run with the PHPUnit phar:

```bash
curl -sSL -o phpunit.phar https://phar.phpunit.de/phpunit-11.phar
php phpunit.phar
```

CI (`.github/workflows/tests.yml`) lints `src/` and runs the suite on PHP
8.2–8.4 for every push and pull request.

## End-to-end (Playwright)

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

### Internationalisation (i18n) prerequisites

The label/email translation specs render the same form on a second (French) site
and compare labels:

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

### CAPTCHA prerequisites

`captcha.spec.ts` covers Cloudflare Turnstile, reCAPTCHA v2 and v3 using the
providers' **official test keys** (deterministic, no account). Configure them in
the host project's `config/easy-form.php` (see that file's comments) and ensure
the app has outbound network — verification POSTs to the real Cloudflare/Google
`siteverify` endpoints. The suite tests the rejection path for all three
providers (block the widget script → no token → server rejects) and the success
path for Turnstile and v2. reCAPTCHA v3 has no public always-pass key, so only
its rejection path and score gate are covered (the score gate is unit-tested in
`CaptchaProviderTest`).

### Server-side / security specs

`server-validation.spec.ts`, `security.spec.ts` and `file-upload.spec.ts` submit
directly to the action endpoint (via `submit.ts`, which fetches a CSRF token then
POSTs) so they test the server's enforcement independently of the browser JS.
Guarantees that are only observable in stored data — honeypot drops, allow-list
stripping, conditional-field anti-tamper — are checked by reading the test DB
(`db.ts`, via `ddev mysql`; override the project dir with `EASY_FORM_DDEV_DIR`).
`cp-rate-limit.spec.ts` runs authenticated to exercise the per-user submission
cap.

### Privacy / ops specs

`gdpr.spec.ts` checks IP anonymization (the test instance sets
`ipStorageMode = 'anonymized'`) and the per-email export/erasure console commands
(`easy-form/privacy/export` / `forget`, run via `ddev`); `prune.spec.ts`
back-dates a submission and verifies retention pruning (and that `--dry-run`
deletes nothing); `cp-export.spec.ts` downloads the authenticated submissions CSV
and asserts the header and a submitted row.

### CP workflow & data specs

`cp-submissions-workflow.spec.ts` drives the authenticated submission actions
(single status update, bulk set-status / delete, and the search + status
filters); `cp-import-export.spec.ts` round-trips a form through the JSON export
and import actions; `conditional-page.spec.ts` checks page-level conditions (a
field on a hidden page is discarded even when posted); and `stored-values.spec.ts`
asserts stored value shapes (select/date → string, multiple checkboxes → array).
Authenticated POSTs go through `submit.ts`'s `actionJson` / `actionPost` (the
latter doesn't follow the success redirect).

## Dev seeders

`Dev` console commands (devMode-gated) populate test data:

```bash
php craft easy-form/dev/seed-forms                # import tests/fixtures/forms/*.json (stable handles)
php craft easy-form/dev/seed-forms --random=20    # + 20 randomized forms
php craft easy-form/dev/seed-submissions --form=e2eContact --count=2000 --days=90
```
