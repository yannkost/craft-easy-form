import { test, expect } from '@playwright/test';
import { newForm, addRow, addField, setBasics, setSiteOptions, fieldTab, closeField, save } from './builder';
import { apiSubmit } from './submit';
import { formId, deleteForm } from './db';

// Checkboxes render as multi-select checkboxes or single-select radios depending
// on the "Allow Multiple" toggle; option values support an escaped colon (\:).
test.describe('choice fields: multiple/single + colon escape', () => {
  test.describe.configure({ mode: 'serial' });

  test.afterAll(() => {
    deleteForm('choiceMulti');
    deleteForm('choiceSingle');
  });

  test('allowMultiple on → checkboxes, and \\: is a literal colon in values', async ({ page, request }) => {
    const handle = 'choiceMulti';
    await newForm(page, 'Choice Multi', handle);
    const row = await addRow(page);
    const field = await addField(row, 'checkboxes');
    await setBasics(field, 'Prefs', 'prefs');
    // value "ratio:16", label "Ratio 16:9" — both via the \: escape.
    await setSiteOptions(field, 'default', 'apple:Apple\nratio\\:16:Ratio 16\\:9');
    await closeField(field);
    await save(page);

    await page.goto(`/dev/form?f=${handle}`);
    const inputs = page.locator('.checkbox-group input[type="checkbox"]');
    await expect(inputs).toHaveCount(2);
    // The escaped colon survives into the option value and label.
    await expect(page.locator('.checkbox-group input[value="ratio:16"]')).toHaveCount(1);
    await expect(page.locator('.checkbox-group')).toContainText('Ratio 16:9');

    // The server accepts the escaped value (it validates against the parsed options).
    const ok = await apiSubmit(request, { formId: formId(handle), fields: { prefs: ['ratio:16'] } });
    expect(ok.success).toBe(true);
    // A value not in the options is still rejected.
    const bad = await apiSubmit(request, { formId: formId(handle), fields: { prefs: ['ratio'] } });
    expect(bad.success).toBe(false);
  });

  test('allowMultiple off → single-select radios', async ({ page }) => {
    const handle = 'choiceSingle';
    await newForm(page, 'Choice Single', handle);
    const row = await addRow(page);
    const field = await addField(row, 'checkboxes');
    await setBasics(field, 'Choice', 'choice');
    await setSiteOptions(field, 'default', 'a:A\nb:B');
    // Turn off "Allow Multiple Selection" (Values tab).
    await fieldTab(field, 'values');
    const sw = field.locator('.field-settings-pane[data-pane="values"] .lightswitch').first();
    await sw.click();
    await expect(sw).not.toHaveClass(/(^|\s)on(\s|$)/);
    await closeField(field);
    await save(page);

    await page.goto(`/dev/form?f=${handle}`);
    await expect(page.locator('.checkbox-group input[type="radio"]')).toHaveCount(2);
    await expect(page.locator('.checkbox-group input[type="checkbox"]')).toHaveCount(0);
  });
});
