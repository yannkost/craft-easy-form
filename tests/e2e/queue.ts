import { execSync } from 'node:child_process';

// Front-end submissions are AJAX (JSON) requests, so Craft's
// RUN_QUEUE_AUTOMATICALLY hook — which only fires on full HTML page responses —
// never triggers the queue runner. Notification emails are queued, so the e2e
// suite runs the queue explicitly after a submission.
//
// Defaults target the local DDEV test project; override with env vars to point
// at another environment (or set EASY_FORM_QUEUE_CMD to a full command).
export function processQueue(timeoutMs = 30_000): void {
  const cmd = process.env.EASY_FORM_QUEUE_CMD || 'ddev exec php craft queue/run';
  const cwd = process.env.EASY_FORM_DDEV_DIR || '/home/yann/craft/plugins-tester';
  try {
    execSync(cmd, { cwd, stdio: 'ignore', timeout: timeoutMs });
  } catch {
    // Best-effort: if the runner isn't available, fall back to whatever the
    // environment does on its own (the caller still polls Mailpit with a timeout).
  }
}
