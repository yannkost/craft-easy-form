import { test, expect, type Page } from '@playwright/test';

// The e2eI18n fixture has French (`french` site) overrides for its field labels:
//   name → Nom, email → Courriel, message → "Message (fr)"
// The default (English) site keeps the base labels.
//
// The French site is served from the /fr subdirectory (FRENCH_URL in the test
// project's .env), so the same form handle renders per-site labels.

const labelTexts = (page: Page) =>
  page.locator('.easy-form-field > label').allInnerTexts();

// All visible field labels, including the agree field's inline text.
const allLabelText = async (page: Page) => {
  const texts = await page.locator('.easy-form-field label, .easy-form-field .checkbox-text').allInnerTexts();
  return texts.map((t) => t.replace('*', '').trim()).join('\n');
};

// Every field type's French label override in the e2eI18nAll fixture.
const FRENCH_FIELD_LABELS = [
  'Texte',           // text
  'Zone de texte',   // textarea
  'Courriel',        // email
  'Téléphone',       // tel
  'Site web',        // url
  'Nombre',          // number
  'Date FR',         // date
  'Choix',           // select
  'Cases à cocher',  // checkboxes
  'Fichier',         // file
  "J'accepte les conditions", // agree
];

test.describe('Per-site field labels on the front end', () => {
  test('English site shows base labels', async ({ page }) => {
    await page.goto('/dev/form?f=e2eI18n');
    const labels = (await labelTexts(page)).map((t) => t.replace('*', '').trim());
    expect(labels).toContain('Name');
    expect(labels).toContain('Email');
    expect(labels).toContain('Message');
    // Hidden marker: primary site keeps base labels.
    await expect(page.locator('input[name="siteId"]')).toHaveValue('1');
  });

  test('French site shows the translated labels', async ({ page }) => {
    await page.goto('/fr/dev/form?f=e2eI18n');
    const labels = (await labelTexts(page)).map((t) => t.replace('*', '').trim());
    expect(labels).toContain('Nom');
    expect(labels).toContain('Courriel');
    expect(labels).toContain('Message (fr)');
    // ...and not the English ones.
    expect(labels).not.toContain('Name');
    await expect(page.locator('input[name="siteId"]')).toHaveValue('2');
  });

  test('every field type shows its French label on the French site', async ({ page }) => {
    await page.goto('/fr/dev/form?f=e2eI18nAll');
    const blob = await allLabelText(page);
    for (const label of FRENCH_FIELD_LABELS) {
      expect(blob, `expected French label "${label}" to render`).toContain(label);
    }
    // hidden fields render no visible label (covered by the email test instead).
    await expect(page.locator('input[name="fields[hiddenField]"]')).toHaveValue('src-fr');
  });

  test('every field type keeps its English label on the default site', async ({ page }) => {
    await page.goto('/dev/form?f=e2eI18nAll');
    const blob = await allLabelText(page);
    for (const label of ['Text', 'Email', 'Phone', 'Website', 'Number', 'Choice', 'File']) {
      expect(blob).toContain(label);
    }
    // No French overrides leak onto the English site.
    for (const fr of FRENCH_FIELD_LABELS) {
      expect(blob).not.toContain(fr);
    }
  });
});
