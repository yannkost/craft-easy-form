# Release Notes for Easy Form

## 1.1.0 - 2026-07-02

### Added

- reCAPTCHA v3: a **Branding** setting that hides Google's floating badge and
  shows the required inline disclosure notice near the submit button (default,
  always visible and Terms-compliant), or keeps Google's floating badge.
- reCAPTCHA v3: a per-form **score threshold** override that falls back to the
  global default when left blank.
- reCAPTCHA v3: the verification score is now logged and stored on each
  submission, shown as **CAPTCHA Score** in the control panel.
- A per-form **Reject submission on CAPTCHA failure** toggle.
- Submissions now record and display a **Spam Reason** (honeypot, blocked email
  domain, blocked keyword, or CAPTCHA), shown on the submission detail and
  available as an optional column in submission exports.
- Translations for all new control-panel strings across German, Dutch, French,
  Spanish, and Italian.

### Changed

- A failed CAPTCHA is now filed **silently as spam** by default — consistent with
  honeypot and blocked-keyword handling — instead of hard-rejecting with an
  error. Enable the per-form *Reject submission on CAPTCHA failure* toggle for the
  previous behavior.
- Every CAPTCHA outcome is now written to `easy-form.log`; previously only
  score-based blocks were logged, so missing/invalid-token failures were silent.

## 1.0.0 - 2026-06-25

> First public release.

### Added

- Frontend form rendering with the `easyForm('handle')` Twig function, including
  per-form behavior toggles (submit/prev/next button text & classes, redirect
  URL, success-message persistence, and the option to disable frontend
  validation).
- Visual Control Panel form builder with fields, rows, and multi-page forms.
- Structural per-site localization: fields, rows, and pages, plus per-site
  labels, option values, and default values.
- Conditional field visibility (show/hide logic with `equals`, `isEmpty`,
  `isNotEmpty`, and related operators).
- Presentational fields (headings and info/warning messages) that render but are
  trimmed from stored submissions and exports.
- Custom markup support via `easyFormLayout('handle')` for fully hand-rolled
  templates.
- A frontend field allowlist with both server-side and client-side validation
  and translated validation messages.
- File uploads to an asset volume or the filesystem, with extension allow/block
  lists, size limits, and sanitized on-disk filenames.
- Spam protection: honeypot, pluggable CAPTCHA providers, and per-form rate
  limiting.
- Email notifications with an interactive content editor, per-site content,
  field tokens, markdown, and per-site enable toggles.
- Outgoing webhooks with SSRF protection (private/reserved/loopback addresses
  blocked).
- CSV submission exports that mirror the Control Panel view and neutralize
  formula injection.
- Submission storage with privacy & retention controls and automatic cleanup of
  uploaded files on submission delete, plugin delete, and uninstall.
- Server-side events for transforming, injecting, inspecting, or cancelling
  submissions, plus form save/delete events for cache invalidation.
- Frontend JavaScript events & hooks.
- Debug logging routed to a dedicated `easy-form.log` file.
- Console commands, including `forms/resave` and dev seeders.
