import { defineConfig, devices } from '@playwright/test';

const baseURL = process.env.EASY_FORM_BASE_URL || 'https://plugins-tester.ddev.site';

export default defineConfig({
  testDir: './tests/e2e',
  timeout: 30_000,
  expect: { timeout: 7_000 },
  fullyParallel: true,
  reporter: [['list']],
  use: {
    baseURL,
    ignoreHTTPSErrors: true,
    headless: true,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
  },
  projects: [
    // Logs into the CP and saves storage state for CP specs.
    // Use the same device/UA as the cp project so Craft accepts the session.
    { name: 'setup', testMatch: /auth\.setup\.ts/, use: { ...devices['Desktop Chrome'] } },

    // Public frontend specs (no auth). The i18n pattern matches both
    // i18n.spec.ts and email-i18n.spec.ts.
    {
      name: 'frontend',
      testMatch: [
        /forms\.spec\.ts/, /i18n\.spec\.ts/, /captcha\.spec\.ts/,
        /server-validation\.spec\.ts/, /security\.spec\.ts/, /file-upload\.spec\.ts/,
        /gdpr\.spec\.ts/, /prune\.spec\.ts/,
        /stored-values\.spec\.ts/, /conditional-page\.spec\.ts/, /events\.spec\.ts/,
        /behavior\.spec\.ts/, /webhooks\.spec\.ts/, /\/presentational\.spec\.ts/,
        /agree\.spec\.ts/, /agree-values\.spec\.ts/, /rate-limit-ip\.spec\.ts/, /submission-hooks\.spec\.ts/,
        /\/row-conditions\.spec\.ts/, /condition-operators\.spec\.ts/, /policy\.spec\.ts/,
        /spam\.spec\.ts/, /render-options\.spec\.ts/, /\/nav-labels\.spec\.ts/,
        /conditions-per-site\.spec\.ts/,
      ],
      use: { ...devices['Desktop Chrome'] },
    },

    // Control-panel specs (authenticated).
    {
      name: 'cp',
      testMatch: /cp-.*\.spec\.ts/,
      use: { ...devices['Desktop Chrome'], storageState: 'tests/e2e/.auth/admin.json' },
      dependencies: ['setup'],
    },
  ],
});
