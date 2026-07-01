import { test, expect } from '@playwright/test';

// The plugin Settings screen (settings/index) is a tabbed, full-page form. These
// tests cover the three things that can break independently of any one setting:
// the custom tab bar, the client-side conditional fields, and the save → persist
// round-trip through SettingsController::actionSave.
//
// NB: the test instance pins several settings via config/easy-form.php (CAPTCHA
// keys, IP mode, blocked keywords, …). Those are config-locked, so the persist
// test deliberately uses `defaultSuccessMessage` — editable, and only consulted
// as a default for *newly created* forms, so round-tripping it can't disturb
// other specs running in parallel. It is restored at the end either way.

test('CP: the settings tab bar switches panes', async ({ page }) => {
  await page.goto('/cp/easy-form/settings');

  // General is active on load.
  await expect(page.locator('#ef-general')).toBeVisible();
  await expect(page.locator('#ef-email')).toBeHidden();

  // Clicking a tab reveals its pane and hides the others.
  await page.locator('.ef-tabnav-item[data-pane="ef-email"]').click();
  await expect(page.locator('#ef-email')).toBeVisible();
  await expect(page.locator('#ef-general')).toBeHidden();

  await page.locator('.ef-tabnav-item[data-pane="ef-privacy"]').click();
  await expect(page.locator('#ef-privacy')).toBeVisible();
  await expect(page.locator('#ef-email')).toBeHidden();
});

test('CP: upload-mode selection toggles the asset / filesystem fields', async ({ page }) => {
  await page.goto('/cp/easy-form/settings');
  await page.locator('.ef-tabnav-item[data-pane="ef-uploads"]').click();

  const assetBlock = page.locator('.ef-upload-asset');
  const fsBlock = page.locator('.ef-upload-filesystem');
  const select = page.locator('select[name="uploadMode"]');

  // The instance's persisted mode is whatever the env runs in, so assert the
  // toggle relationally rather than against a fixed default: each mode shows its
  // own block and hides the other (pure client JS — nothing is saved).
  await select.selectOption('asset');
  await expect(assetBlock).toBeVisible();
  await expect(fsBlock).toBeHidden();

  await select.selectOption('filesystem');
  await expect(fsBlock).toBeVisible();
  await expect(assetBlock).toBeHidden();

  await select.selectOption('asset');
  await expect(assetBlock).toBeVisible();
  await expect(fsBlock).toBeHidden();
});

// Save the full settings form (Ctrl+S → SettingsController::actionSave) with one
// edited, side-effect-free value and confirm it round-trips through storage.
async function saveSettings(page: import('@playwright/test').Page) {
  await Promise.all([
    // The save POSTs to the action and 302s back; wait for that response so the
    // project-config write is finished before we reload (else we race it).
    page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('easy-form/settings')),
    page.keyboard.press('Control+s'),
  ]);
  await page.waitForLoadState('networkidle');
}

test('CP: saving the settings form persists an edited value', async ({ page }) => {
  await page.goto('/cp/easy-form/settings');

  const field = page.locator('input[name="defaultSuccessMessage"]');
  const original = await field.inputValue();
  const sentinel = 'Persisted ' + Date.now();

  try {
    await field.fill(sentinel);
    await saveSettings(page);

    // Reload from scratch: the saved value must come back from storage.
    await page.goto('/cp/easy-form/settings');
    await expect(page.locator('input[name="defaultSuccessMessage"]')).toHaveValue(sentinel);
  } finally {
    // Always restore the original so the global default (a new-form default,
    // shared by every parallel spec) is left exactly as we found it.
    await page.goto('/cp/easy-form/settings');
    await page.locator('input[name="defaultSuccessMessage"]').fill(original);
    await saveSettings(page);
  }
});
