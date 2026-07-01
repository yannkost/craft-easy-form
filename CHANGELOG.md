# Release Notes for Easy Form

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
