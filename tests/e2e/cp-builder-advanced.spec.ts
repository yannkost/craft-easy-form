import { test, expect } from '@playwright/test';
import {
  newForm, addRow, addField, setBasics, setRequired, setSiteOptions, setSiteLabel,
  setHelpText, addCondition, closeField, save, deleteForm,
} from './builder';

test('CP: required toggle propagates to frontend validation', async ({ page }) => {
  const handle = 'e2eReq' + Date.now().toString().slice(-6);

  await newForm(page, 'E2E Required', handle);
  const row = await addRow(page, 0);
  const field = await addField(row, 'text');
  await setBasics(field, 'Your Name', 'yourName');
  await setRequired(field);
  await closeField(field);
  await save(page);

  // On the front end, submitting empty must trigger the required error.
  await page.goto(`/dev/form?f=${handle}`);
  await page.locator('form.easy-form button[type=submit]').click();
  await expect(page.locator('.field-error').first()).toBeVisible();
  await expect(page.locator('input[name="fields[yourName]"]')).toHaveAttribute('aria-invalid', 'true');

  await deleteForm(page, handle);
});

test('CP: select field options render on the front end', async ({ page }) => {
  const handle = 'e2eSel' + Date.now().toString().slice(-6);

  await newForm(page, 'E2E Select', handle);
  const row = await addRow(page, 0);
  const field = await addField(row, 'select');
  await setBasics(field, 'Favourite Colour', 'colour');
  // Primary site = "default". "value:label" or just "value".
  await setSiteOptions(field, 'default', 'red:Red\nblue:Blue\ngreen:Green');
  await closeField(field);
  await save(page);

  await page.goto(`/dev/form?f=${handle}`);
  const select = page.locator('select[name="fields[colour]"]');
  await expect(select).toBeVisible();
  await expect(select.locator('option', { hasText: 'Red' })).toHaveAttribute('value', 'red');
  await expect(select.locator('option', { hasText: 'Blue' })).toHaveAttribute('value', 'blue');
  await expect(select.locator('option', { hasText: 'Green' })).toHaveAttribute('value', 'green');

  await deleteForm(page, handle);
});

test('CP: per-site default value persists and renders', async ({ page }) => {
  const handle = 'e2eDef' + Date.now().toString().slice(-6);

  await newForm(page, 'E2E Default', handle);
  const row = await addRow(page, 0);
  const field = await addField(row, 'text');
  await setBasics(field, 'Country', 'country');

  // Base default (main input) + a per-site override in its Translations block.
  await field.locator('input[name$="[defaultValue]"]').fill('Base Default');
  const defBlock = field.locator('.ef-localized').filter({
    has: page.locator('input[name$="[defaultValue]"]'),
  });
  await defBlock.locator('.ef-localized-toggle').click();
  await field.locator('input[name$="[siteDefaultValues][french]"]').fill('Valeur FR');
  await closeField(field);
  await save(page);

  // Both values round-tripped in the builder.
  await page.reload();
  const saved = page.locator('.field-in-row').filter({ hasText: 'Country' }).first();
  await saved.locator('.toggle-field-settings').click();
  await saved.locator('.ef-localized').filter({ has: page.locator('input[name$="[defaultValue]"]') })
    .locator('.ef-localized-toggle').click();
  await expect(saved.locator('input[name$="[defaultValue]"]')).toHaveValue('Base Default');
  await expect(saved.locator('input[name$="[siteDefaultValues][french]"]')).toHaveValue('Valeur FR');

  // Primary site renders the base default.
  await page.goto(`/dev/form?f=${handle}`);
  await expect(page.locator('input[name="fields[country]"]')).toHaveValue('Base Default');

  await deleteForm(page, handle);
});

test('CP: per-site label override persists through the builder', async ({ page }) => {
  const handle = 'e2eLbl' + Date.now().toString().slice(-6);

  await newForm(page, 'E2E Labels', handle);
  const row = await addRow(page, 0);
  const field = await addField(row, 'text');
  await setBasics(field, 'First Name', 'firstName');
  await setSiteLabel(field, 'french', 'Prénom'); // non-primary site
  await closeField(field);
  await save(page);

  // Reload the saved form and confirm the override round-tripped.
  await page.reload();
  const saved = page.locator('.field-in-row').filter({ hasText: 'First Name' }).first();
  await saved.locator('.toggle-field-settings').click();
  // The per-site label now lives in the Label field's Translations (General tab).
  await saved.locator('.field-settings-pane[data-pane="general"] .ef-localized-toggle').first().click();
  await expect(saved.locator('input[name$="[siteLabels][french]"]')).toHaveValue('Prénom');

  await deleteForm(page, handle);
});

test('CP: field help text renders on the front end, translated per site', async ({ page }) => {
  const handle = 'e2eHelp' + Date.now().toString().slice(-6);

  await newForm(page, 'E2E Help', handle);
  const row = await addRow(page, 0);
  const field = await addField(row, 'text');
  await setBasics(field, 'Email', 'email');
  await setHelpText(field, "We'll never share it.", { french: 'Nous ne le partagerons jamais.' });
  await closeField(field);
  await save(page);

  // Default site → base help, linked via aria-describedby.
  await page.goto(`/dev/form?f=${handle}`);
  const help = page.locator('.easy-form-field[data-field-handle="email"] .easy-form-field-instructions');
  await expect(help).toHaveText("We'll never share it.");
  const helpId = await help.getAttribute('id');
  await expect(page.locator('input[name="fields[email]"]')).toHaveAttribute('aria-describedby', new RegExp(helpId!));

  // French site → the override.
  await page.goto(`/fr/dev/form?f=${handle}`);
  await expect(page.locator('.easy-form-field[data-field-handle="email"] .easy-form-field-instructions'))
    .toHaveText('Nous ne le partagerons jamais.');

  await deleteForm(page, handle);
});

test('CP: conditional field built in the UI hides until its trigger matches', async ({ page }) => {
  const handle = 'e2eCond' + Date.now().toString().slice(-6);

  await newForm(page, 'E2E Conditional', handle);
  const row = await addRow(page, 0);

  const trigger = await addField(row, 'text');
  await setBasics(trigger, 'Enquiry Type', 'enquiryType');
  await closeField(trigger);

  const dependent = await addField(row, 'text');
  await setBasics(dependent, 'Company Name', 'companyName');
  await addCondition(dependent, {
    action: 'show',
    fieldHandle: 'enquiryType',
    operator: 'equals',
    value: 'business',
  });
  await closeField(dependent);
  await save(page);

  // Front end: dependent field hidden until the trigger value matches.
  await page.goto(`/dev/form?f=${handle}`);
  const company = page.locator('[data-field-handle="companyName"], input[name="fields[companyName]"]').first();
  await expect(company).toBeHidden();
  await page.locator('input[name="fields[enquiryType]"]').fill('business');
  await expect(company).toBeVisible();

  await deleteForm(page, handle);
});
