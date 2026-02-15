/**
 * @file
 * Webform — tests all detected webforms: load, render, submit, validate.
 *
 * The Drupal module auto-creates a 'smoke_test' webform if webform is installed.
 * This suite iterates over every open webform found on the site.
 */

import { test, expect } from '@playwright/test';
import { isSuiteEnabled, getSuiteConfig, SmokeWebform } from '../src/config-reader';
import { fillField } from '../src/helpers';

const enabled = isSuiteEnabled('webform');
const webformConfig = getSuiteConfig('webform');
const forms: SmokeWebform[] = (webformConfig as any)?.forms ?? [];

test.describe('Webform', () => {
  test.skip(!enabled, 'Webform suite is disabled — module not detected.');

  if (forms.length === 0) {
    test('no webforms detected', async () => {
      test.skip(true, 'No open webforms found on this site.');
    });
  }

  for (const form of forms) {
    test.describe(form.title, () => {
      test(`${form.title} page loads`, async ({ page }) => {
        const response = await page.goto(form.path);
        expect(response?.status(), `${form.path} should return 200`).toBe(200);
      });

      test(`${form.title} renders all required fields`, async ({ page }) => {
        await page.goto(form.path);

        for (const field of form.fields) {
          if (field.required) {
            await expect(
              page.getByLabel(field.title),
              `Field "${field.title}" should be visible`
            ).toBeVisible();
          }
        }
      });

      test(`${form.title} submits successfully`, async ({ page }) => {
        await page.goto(form.path);

        // Fill all fields.
        for (const field of form.fields) {
          await fillField(page, field.title, field.type);
        }

        // Submit.
        await page.getByRole('button', { name: 'Submit' }).click();

        // Should see confirmation or redirect away from the form.
        await page.waitForLoadState('networkidle');
        const url = page.url();
        const body = await page.locator('body').textContent();

        // Success if we see a confirmation message OR we're no longer on the form.
        const hasConfirmation =
          body?.includes('submission') ||
          body?.includes('received') ||
          body?.includes('thank') ||
          body?.includes('Thank') ||
          !url.includes(form.path);

        expect(hasConfirmation, 'Should show confirmation after submit').toBeTruthy();
      });

      test(`${form.title} validates required fields`, async ({ page }) => {
        await page.goto(form.path);

        // Submit without filling anything.
        await page.getByRole('button', { name: 'Submit' }).click();

        // Should still be on the form (not redirected to confirmation).
        await expect(page).toHaveURL(new RegExp(form.path.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
      });
    });
  }
});
