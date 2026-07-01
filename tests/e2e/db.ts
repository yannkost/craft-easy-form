import { execFileSync } from 'node:child_process';

// Some server-side guarantees (honeypot drop, allowlist stripping, anti-tamper)
// are only observable in what gets *stored*, not in the HTTP response. These
// helpers read the test instance's DB directly via DDEV. Override the project
// dir with EASY_FORM_DDEV_DIR.

const CWD = process.env.EASY_FORM_DDEV_DIR || '/home/yann/craft/plugins-tester';

function mysql(sql: string): string {
  // execFileSync (no shell) avoids any quoting/escaping of the SQL.
  return execFileSync('ddev', ['mysql', '-N', '-e', sql], { cwd: CWD, encoding: 'utf8' }).trim();
}

/** Run a Craft console command in the test container and return its stdout. */
export function craft(args: string[]): string {
  return execFileSync('ddev', ['exec', 'php', 'craft', ...args], { cwd: CWD, encoding: 'utf8' });
}

/** Count stored upload files whose name contains `token` (filesystem mode). */
export function uploadFileCount(token: string): number {
  const out = execFileSync(
    'ddev',
    ['exec', 'bash', '-lc', `find web/form-uploads -type f -name '*${token}*' 2>/dev/null | wc -l`],
    { cwd: CWD, encoding: 'utf8' },
  );
  return parseInt(out.trim(), 10) || 0;
}

/** Push a submission's dateCreated into the past (for retention tests). */
export function backdateByEmail(handle: string, email: string, days: number): void {
  mysql(
    `UPDATE easyform_submissions SET dateCreated = DATE_SUB(UTC_TIMESTAMP(), INTERVAL ${days} DAY) WHERE formHandle='${handle}' AND primaryEmail='${email}'`,
  );
}

/** Status of a submission by id. */
export function submissionStatus(id: number | string): string {
  return mysql(`SELECT status FROM easyform_submissions WHERE id=${id}`);
}

/** Status of the newest submission with the given promoted primaryEmail. */
export function statusByEmail(handle: string, email: string): string {
  return mysql(
    `SELECT status FROM easyform_submissions WHERE formHandle='${handle}' AND primaryEmail='${email}' ORDER BY id DESC LIMIT 1`,
  );
}

/** Whether a submission row exists and is not soft-deleted. */
export function submissionActive(id: number | string): boolean {
  return mysql(`SELECT COUNT(*) FROM easyform_submissions WHERE id=${id} AND dateDeleted IS NULL`) === '1';
}

/** Newest form id with the given name (tab-free), or '' if none. */
export function newestFormIdByName(name: string): string {
  return mysql(`SELECT id FROM easyform_forms WHERE name='${name}' ORDER BY id DESC LIMIT 1`);
}

/** Handle of a form by id. */
export function formHandleById(id: number | string): string {
  return mysql(`SELECT handle FROM easyform_forms WHERE id=${id}`);
}

/** Raw fieldLayout JSON text for a form handle. */
export function formLayoutText(handle: string): string {
  return mysql(`SELECT fieldLayout FROM easyform_forms WHERE handle='${handle}' ORDER BY id DESC LIMIT 1`);
}

/** Delete a form and its submissions (test cleanup). */
export function deleteForm(handle: string): void {
  mysql(`DELETE FROM easyform_submissions WHERE formHandle='${handle}'`);
  mysql(`DELETE FROM easyform_forms WHERE handle='${handle}'`);
}

/** The stored IP of the latest submission with the given primaryEmail. */
export function ipByEmail(handle: string, email: string): string {
  return mysql(
    `SELECT IFNULL(ipAddress, 'NULL') FROM easyform_submissions WHERE formHandle='${handle}' AND primaryEmail='${email}' ORDER BY id DESC LIMIT 1`,
  );
}

export function formId(handle: string): string {
  return mysql(`SELECT id FROM easyform_forms WHERE handle='${handle}'`);
}

// A non-admin CP user used by cp-permissions.spec.ts: it has `accessCp` (so it
// can log in and reach the CP) but none of the four easy-form:* permissions, so
// every plugin route must deny it.
export const LIMITED_USER = {
  username: 'e2elimited',
  email: 'e2elimited@example.test',
  password: 'e2eLimitedPass1!',
};

/**
 * Create the limited CP user and grant it only `accessCp`. Idempotent.
 * Returns false when the user can't exist — notably on Craft **Solo**, whose
 * 1-user cap makes `users/create` fail; cp-permissions.spec.ts skips in that case.
 */
export function ensureLimitedUser(): boolean {
  // Create the non-admin user. A second run just errors "username taken" — fine.
  try {
    execFileSync(
      'ddev',
      [
        'exec', 'php', 'craft', 'users/create',
        `--email=${LIMITED_USER.email}`,
        `--username=${LIMITED_USER.username}`,
        `--password=${LIMITED_USER.password}`,
        '--admin=0',
      ],
      { cwd: CWD, encoding: 'utf8', stdio: 'pipe' },
    );
  } catch {
    // Already exists, or the edition's user cap was hit (Solo).
  }

  // On Solo the user was never created → signal "unavailable" so the spec skips.
  if (!mysql(`SELECT id FROM users WHERE username='${LIMITED_USER.username}'`)) {
    return false;
  }

  // Grant only accessCp, straight in the DB (idempotent inserts). User
  // permissions are read fresh per request, so no cache flush is needed.
  mysql(
    `INSERT INTO userpermissions (name,dateCreated,dateUpdated,uid)
     SELECT 'accesscp',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UUID() FROM DUAL
     WHERE NOT EXISTS (SELECT 1 FROM userpermissions WHERE name='accesscp')`,
  );
  mysql(
    `INSERT INTO userpermissions_users (permissionId,userId,dateCreated,dateUpdated,uid)
     SELECT p.id,u.id,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UUID()
     FROM userpermissions p JOIN users u ON u.username='${LIMITED_USER.username}'
     WHERE p.name='accesscp'
       AND NOT EXISTS (SELECT 1 FROM userpermissions_users x WHERE x.permissionId=p.id AND x.userId=u.id)`,
  );
  return true;
}

/** Craft `fields` table row (id + type class) for a live handle, or null. */
export function craftFieldRow(handle: string): { id: string; type: string } | null {
  // Craft soft-deletes fields, so exclude trashed rows.
  const out = mysql(`SELECT id, type FROM fields WHERE handle='${handle}' AND dateDeleted IS NULL ORDER BY id DESC LIMIT 1`);
  if (!out) return null;
  const [id, type] = out.split('\t');
  return { id, type };
}

export function submissionCount(handle: string): number {
  return parseInt(mysql(`SELECT COUNT(*) FROM easyform_submissions WHERE formHandle='${handle}'`) || '0', 10);
}

/**
 * Count live (not soft-deleted) submissions for a form, with an optional raw
 * WHERE fragment (test-only, so no injection concern). Used to compare an
 * export/list against the DB's own truth at volume.
 */
export function submissionCountWhere(handle: string, extraWhere = ''): number {
  const where = `formHandle='${handle}' AND dateDeleted IS NULL` + (extraWhere ? ` AND ${extraWhere}` : '');
  return parseInt(mysql(`SELECT COUNT(*) FROM easyform_submissions WHERE ${where}`) || '0', 10);
}

/** Bulk-seed N submissions for a form via the dev console command. */
export function seedSubmissions(handle: string, count: number, days = 180): void {
  execFileSync(
    'ddev',
    ['exec', 'php', 'craft', 'easy-form/dev/seed-submissions', `--form=${handle}`, `--count=${count}`, `--days=${days}`],
    { cwd: CWD, encoding: 'utf8', stdio: 'pipe', timeout: 300_000 },
  );
}

export function deleteSubmissions(handle: string): void {
  mysql(`DELETE FROM easyform_submissions WHERE formHandle='${handle}'`);
}

/** How many submissions of `handle` have the given promoted primaryEmail. */
export function countByEmail(handle: string, email: string): number {
  return parseInt(
    mysql(`SELECT COUNT(*) FROM easyform_submissions WHERE formHandle='${handle}' AND primaryEmail='${email}'`) || '0',
    10,
  );
}

/**
 * JSON_EXTRACT a path from the submission identified by its promoted
 * primaryEmail (a per-test unique marker, so this is robust against other
 * tests submitting to the same form in parallel). Returns the literal string
 * 'NULL' when the path is absent, or the JSON-encoded value (strings come back
 * quoted, e.g. "newsletter").
 */
export function dataValueByEmail(handle: string, path: string, email: string): string {
  return mysql(
    `SELECT JSON_EXTRACT(data, '${path}') FROM easyform_submissions WHERE formHandle='${handle}' AND primaryEmail='${email}' ORDER BY id DESC LIMIT 1`,
  );
}
