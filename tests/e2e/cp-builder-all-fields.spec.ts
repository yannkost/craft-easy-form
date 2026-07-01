import { test, expect, type Locator } from '@playwright/test';
import {
  newForm, addRow, addField, setBasics, setRequired, setSiteOptions,
  addCondition, openField, fieldTab, closeField, save, deleteForm,
} from './builder';

/**
 * End-to-end coverage for the form builder: log into the CP, build one form
 * containing every field type across two pages (with validation rules, options,
 * a conditional field, and a hidden field with a default), save it, confirm it
 * round-trips in the editor, then drive the rendered form on the front end
 * (required validation, multi-page stepper, conditional visibility, presentational
 * fields, and a successful submit).
 */

const form = (page: import('@playwright/test').Page) => page.locator('form.easy-form');

// Fill a single input on a given settings tab of a field.
async function setOnTab(field: Locator, tab: string, selector: string, value: string) {
  await openField(field);
  await fieldTab(field, tab);
  await field.locator(selector).fill(value);
}

test('CP: build a form with every field type, then verify it end to end', async ({ page }) => {
  test.setTimeout(120_000);
  const handle = 'e2eAll' + Date.now().toString().slice(-6);

  await newForm(page, 'E2E All Fields', handle);

  // ---- Page 1: input field types -----------------------------------------
  const p1 = await addRow(page, 0);

  const fullName = await addField(p1, 'text');
  await setBasics(fullName, 'Full Name', 'fullName');
  await setRequired(fullName);
  await closeField(fullName);

  const email = await addField(p1, 'email');
  await setBasics(email, 'Email', 'email');
  await setRequired(email);
  await closeField(email);

  const age = await addField(p1, 'number');
  await setBasics(age, 'Age', 'age');
  await setOnTab(age, 'validation', 'input[name$="[min]"]', '1');
  await setOnTab(age, 'validation', 'input[name$="[max]"]', '120');
  await closeField(age);

  const phone = await addField(p1, 'tel');
  await setBasics(phone, 'Phone', 'phone');
  await closeField(phone);

  const website = await addField(p1, 'url');
  await setBasics(website, 'Website', 'website');
  await closeField(website);

  const startDate = await addField(p1, 'date');
  await setBasics(startDate, 'Start Date', 'startDate');
  await closeField(startDate);

  const message = await addField(p1, 'textarea');
  await setBasics(message, 'Message', 'message');
  await setRequired(message);
  await closeField(message);

  // ---- Page 2: choices, conditional, presentational, hidden --------------
  await page.locator('#add-page-btn').click();
  await page.locator('#page-tabs .page-tab').nth(1).click();
  const p2 = await addRow(page, 1);

  const heading = await addField(p2, 'heading');
  await setBasics(heading, 'Your preferences', 'prefsHeading');
  await closeField(heading);

  const paragraph = await addField(p2, 'paragraph');
  await setOnTab(paragraph, 'content', 'textarea[name$="[content]"]', 'Tell us a little more.');
  await closeField(paragraph);

  const plan = await addField(p2, 'select');
  await setBasics(plan, 'Plan', 'plan');
  await setRequired(plan);
  await setSiteOptions(plan, 'default', 'free:Free\npro:Pro');
  await closeField(plan);

  const interests = await addField(p2, 'checkboxes');
  await setBasics(interests, 'Interests', 'interests');
  await setSiteOptions(interests, 'default', 'a:Alpha\nb:Beta');
  await closeField(interests);

  // Conditional: only shown when Plan == pro.
  const company = await addField(p2, 'text');
  await setBasics(company, 'Company', 'company');
  await addCondition(company, { action: 'show', fieldHandle: 'plan', operator: 'equals', value: 'pro' });
  await closeField(company);

  const attachment = await addField(p2, 'file');
  await setBasics(attachment, 'Attachment', 'attachment');
  await closeField(attachment);

  const terms = await addField(p2, 'agree');
  await setBasics(terms, 'Terms', 'terms');
  await setRequired(terms);
  await setOnTab(terms, 'link', 'input[name$="[agreeText]"]', 'I agree to the terms');
  await closeField(terms);

  const callout = await addField(p2, 'callout');
  await setBasics(callout, 'Heads up', 'calloutNote');
  await closeField(callout);

  // Divider has no settings — its popover doesn't open, so don't close it.
  await addField(p2, 'divider');

  const source = await addField(p2, 'hidden');
  await setBasics(source, 'Source', 'source');
  await setOnTab(source, 'general', 'input[name$="[defaultValue]"]', 'e2e');
  await closeField(source);

  await save(page);

  // ---- Round-trip: reload the editor and confirm structure persisted -----
  await page.reload();
  await expect(page.locator('#page-tabs .page-tab')).toHaveCount(2);
  await expect(page.locator('.field-label-text', { hasText: 'Full Name' })).toBeVisible();
  await expect(page.locator('.field-label-text', { hasText: 'Email' })).toBeVisible();

  // The number field's validation rule round-tripped. Match "Age" exactly —
  // Playwright's hasText is a case-insensitive substring (it also hits "Message").
  await page.locator('#page-tabs .page-tab').nth(0).click();
  const savedAge = page.locator('.field-in-row')
    .filter({ has: page.getByText('Age', { exact: true }) }).first();
  await openField(savedAge);
  await fieldTab(savedAge, 'validation');
  await expect(savedAge.locator('input[name$="[max]"]')).toHaveValue('120');
  await closeField(savedAge);

  // Page 2 fields persisted.
  await page.locator('#page-tabs .page-tab').nth(1).click();
  await expect(page.locator('.field-label-text', { hasText: 'Plan' })).toBeVisible();
  await expect(page.locator('.field-label-text', { hasText: 'Company' })).toBeVisible();

  // ---- Front end: render, validate, step, submit -------------------------
  await page.goto(`/dev/form?f=${handle}`);
  await expect(form(page)).toBeVisible();

  // Every page-1 input rendered.
  for (const h of ['fullName', 'email', 'age', 'phone', 'website', 'startDate', 'message']) {
    await expect(page.locator(`.easy-form-field[data-field-handle="${h}"]`)).toBeVisible();
  }
  // Page 2 is hidden until we advance.
  await expect(page.locator('.easy-form-page').nth(1)).toBeHidden();

  // Required validation blocks advancing on an empty page 1.
  await page.locator('.easy-form-next').click();
  await expect(page.locator('.field-error').first()).toBeVisible();
  await expect(page.locator('.easy-form-page').nth(1)).toBeHidden();

  // Fill page 1 and advance.
  await page.fill('input[name="fields[fullName]"]', 'Playwright Tester');
  await page.fill('input[name="fields[email]"]', 'pw@example.com');
  await page.fill('input[name="fields[age]"]', '30');
  await page.fill('input[name="fields[phone]"]', '079-000-0000');
  await page.fill('input[name="fields[website]"]', 'https://example.com');
  await page.fill('input[name="fields[startDate]"]', '2026-01-15');
  await page.fill('textarea[name="fields[message]"]', 'Hello from the e2e suite.');
  await page.locator('.easy-form-next').click();

  // Page 2 visible; presentational + conditional behavior.
  await expect(page.locator('.easy-form-page').nth(1)).toBeVisible();
  await expect(form(page).getByText('Your preferences')).toBeVisible();
  await expect(form(page).locator('hr')).toHaveCount(1); // the divider

  // Company is conditional: hidden until Plan = pro.
  const company2 = page.locator('.easy-form-field[data-field-handle="company"]');
  await expect(company2).toBeHidden();
  await page.selectOption('select[name="fields[plan]"]', 'pro');
  await expect(company2).toBeVisible();

  // Complete required page-2 fields and submit.
  await page.fill('input[name="fields[company]"]', 'Acme Inc');
  await page.locator('.easy-form-field[data-field-handle="interests"] input[type="checkbox"]').first().check();
  await page.locator('.easy-form-field[data-field-handle="terms"] input[type="checkbox"]').first().check();
  await form(page).locator('button[type=submit]').click();

  await expect(page.locator('.easy-form-message.success')).toBeVisible();

  await deleteForm(page, handle);
});
