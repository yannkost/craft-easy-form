# Security Policy

## Supported versions

Security fixes are provided for the latest released `1.x` version of Easy Form.
Older versions are not maintained — please upgrade to the latest release before
reporting an issue.

| Version | Supported |
| ------- | --------- |
| 1.x     | ✅        |
| < 1.0   | ❌        |

## Reporting a vulnerability

Please **do not** open a public GitHub issue for security vulnerabilities.

Instead, report it privately using one of the following:

- **GitHub Security Advisories** — open a private report at
  <https://github.com/yannkost/craft-easy-form/security/advisories/new>
  (preferred).
- **Email** — yann.kost@ik.me, with `[Easy Form security]` in the subject.

Please include:

- A description of the vulnerability and its impact.
- Steps to reproduce (a proof of concept, affected form configuration, or
  request payload is ideal).
- The Easy Form version, Craft CMS version, and PHP version.

## What to expect

- **Acknowledgement** within 5 business days.
- An assessment and, where applicable, a fix timeline communicated after triage.
- Coordinated disclosure: please give a reasonable window for a fix to be
  released before any public disclosure. Credit will be given to reporters who
  wish to be named.

## Scope

In scope: the Easy Form plugin code in this repository — form submission
handling, file uploads, exports, webhooks, notifications, and Control Panel
access control.

Out of scope: vulnerabilities in Craft CMS itself (report those to
[Pixel & Tonic](https://github.com/craftcms/cms/security/policy)), third-party
dependencies (report upstream), and issues that require an already-compromised
admin account or server.
