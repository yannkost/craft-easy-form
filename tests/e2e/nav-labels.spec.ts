import { test, expect } from '@playwright/test';

// Fixture: the `e2eMultipage` form (2 pages) is configured with —
//   page 1: nextLabel "Continue" / siteNextLabels.french "Continuer"
//   page 2: prevLabel "Go back"  / sitePrevLabels.french "Retour"
//   submitButtonLabel "Send it"  / siteSubmitButtonLabels.french "Envoyer"
// (set via the Forms service; the CP builder round-trip is covered separately
//  in cp-nav-labels.spec.ts).

test('per-page nav labels + submit label render and swap (default site)', async ({ page }) => {
  await page.goto('/dev/form?f=e2eMultipage');

  const next = page.locator('.easy-form-next');
  const prev = page.locator('.easy-form-prev');
  const submit = page.locator('button.btn.submit');

  // Page 1: next shows page-0 label, prev hidden
  await expect(next).toHaveText('Continue');
  await expect(prev).toBeHidden();

  // Navigate to page 2 (fill page-1 required field first): prev shows page-1
  // label, submit shows the form label.
  await page.fill('input[name="fields[name]"]', 'Jane');
  await next.click();
  await expect(prev).toBeVisible();
  await expect(prev).toHaveText('Go back');
  await expect(submit).toHaveText('Send it');
});

test('per-page nav labels resolve per-site (French)', async ({ page }) => {
  await page.goto('/fr/dev/form?f=e2eMultipage');
  await expect(page.locator('.easy-form-next')).toHaveText('Continuer');
  await page.fill('input[name="fields[name]"]', 'Jeanne');
  await page.locator('.easy-form-next').click();
  await expect(page.locator('.easy-form-prev')).toHaveText('Retour');
  await expect(page.locator('button.btn.submit')).toHaveText('Envoyer');
});
