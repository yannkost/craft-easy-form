import { type Page, type Locator, expect } from '@playwright/test';

/** Open the new-form page and set its name + handle. */
export async function newForm(page: Page, name: string, handle: string) {
  await page.goto('/cp/easy-form/forms/new');
  await page.fill('#name', name);
  await page.fill('#handle', handle);
}

/** Add a row to a page and return its locator. */
export async function addRow(page: Page, pageIndex = 0): Promise<Locator> {
  await page.locator(`.add-row-btn[data-page-index="${pageIndex}"]`).first().click();
  return page.locator(`.page-pane[data-page-pane="${pageIndex}"] .layout-row`).last();
}

/**
 * Add a field of the given type to a row and return its locator.
 *
 * The palette is drag-and-drop (which headless browsers can't perform), so we
 * use the palette item's "+" button, which adds to the active row. Clicking the
 * row first makes it the active target, so the field lands in `row`.
 */
export async function addField(row: Locator, type: string): Promise<Locator> {
  const page = row.page();
  await row.locator('.layout-row-label').first().click();
  await page
    .locator(`.ef-field-palette .ef-palette-item[data-field-type="${type}"] .ef-palette-item-add`)
    .click();
  return row.locator('.field-in-row').last();
}

/** Open a field's settings popover. */
export async function openField(field: Locator) {
  const backdrop = field.locator('.field-popover-backdrop');
  if (!(await backdrop.isVisible())) {
    await field.locator('.toggle-field-settings').click();
  }
  await expect(backdrop).toBeVisible();
}

/** Switch to a tab inside a field's settings popover. */
export async function fieldTab(field: Locator, tab: string) {
  await field.locator(`.field-in-row-settings > .ef-tabs > .ef-tab[data-tab="${tab}"]`).click();
}

/** Set a field's label + handle (General tab). */
export async function setBasics(field: Locator, label: string, handle: string) {
  await openField(field);
  await field.locator('.field-label-input').fill(label);
  await field.locator('.field-handle-input').fill(handle);
}

/** Toggle the Required lightswitch on. */
export async function setRequired(field: Locator) {
  await openField(field);
  await fieldTab(field, 'required');
  const sw = field.locator('.field-settings-pane[data-pane="required"] .lightswitch').first();
  await sw.click();
  await expect(sw).toHaveClass(/(^|\s)on(\s|$)/);
}

/** Expand a localized field's "Translations" block within a settings pane. */
async function expandTranslations(field: Locator, pane: string) {
  const toggle = field.locator(`.field-settings-pane[data-pane="${pane}"] .ef-localized-toggle`).first();
  if ((await toggle.getAttribute('aria-expanded')) !== 'true') {
    await toggle.click();
  }
}

/** Fill a per-site options textarea (Values tab). Primary site is the main input. */
export async function setSiteOptions(field: Locator, siteHandle: string, options: string) {
  await openField(field);
  await fieldTab(field, 'values');
  const input = field.locator(`textarea[name$="[siteOptions][${siteHandle}]"]`);
  if (!(await input.isVisible())) {
    await expandTranslations(field, 'values');
  }
  await input.fill(options);
}

/** Set a per-site label override (in the Label field's Translations, General tab). */
export async function setSiteLabel(field: Locator, siteHandle: string, label: string) {
  await openField(field);
  await expandTranslations(field, 'general');
  await field.locator(`input[name$="[siteLabels][${siteHandle}]"]`).fill(label);
}

/** Set the base help text + optional per-site overrides (Help tab). */
export async function setHelpText(
  field: Locator,
  help: string,
  siteOverrides: Record<string, string> = {},
) {
  await openField(field);
  await fieldTab(field, 'help');
  await field.locator('textarea[name$="[helpText]"]').first().fill(help);
  if (Object.keys(siteOverrides).length) {
    await expandTranslations(field, 'help');
    for (const [site, text] of Object.entries(siteOverrides)) {
      await field.locator(`textarea[name$="[siteHelpTexts][${site}]"]`).fill(text);
    }
  }
}

/** Add a visibility condition rule (Conditions tab). */
export async function addCondition(
  field: Locator,
  opts: { action?: 'show' | 'hide'; fieldHandle: string; operator?: string; value?: string },
) {
  await openField(field);
  await fieldTab(field, 'conditions');
  const pane = field.locator('.field-settings-pane[data-pane="conditions"]');
  if (opts.action) {
    await pane.locator('select[name$="[conditions][action]"]').selectOption(opts.action);
  }
  await pane.locator('.add-condition-rule').click();
  const rule = pane.locator('.condition-rule').last();
  await rule.locator('input[name$="[field]"]').fill(opts.fieldHandle);
  if (opts.operator) {
    await rule.locator('select[name$="[operator]"]').selectOption(opts.operator);
  }
  if (opts.value !== undefined) {
    await rule.locator('input[name$="[value]"]').fill(opts.value);
  }
}

/** Commit the field's settings ("Save field") and wait for the dialog to close. */
export async function closeField(field: Locator) {
  await field.locator('.field-dialog-save').click();
  await expect(field.locator('.field-popover-backdrop')).toBeHidden();
}

/** Save the form (Ctrl+S — locale-agnostic) and assert the redirect. */
export async function save(page: Page) {
  await page.keyboard.press('Control+s');
  await expect(page).toHaveURL(/\/cp\/easy-form\/forms\/\d+/);
}

/** Delete a form from the index by handle. */
export async function deleteForm(page: Page, handle: string) {
  page.on('dialog', (d) => d.accept());
  // Search by handle so the form is on the first page regardless of pagination.
  await page.goto('/cp/easy-form/forms?search=' + encodeURIComponent(handle));
  const row = page.locator('.ef-table tbody tr').filter({ hasText: handle });
  await row.locator('.ef-row-actions .ef-delete').click();
  await expect(row).toHaveCount(0);
}
