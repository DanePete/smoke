/**
 * @file
 * Webform — submits the smoke_test form and confirms it works.
 *
 * The Drupal module checks if webform is enabled, creates the smoke_test
 * form if it doesn't exist, and passes the config here. If the suite
 * shows up in config, the form is ready to test.
 */

import { test, expect } from '@playwright/test';
import { isSuiteEnabled, getSuiteConfig } from '../src/config-reader';
import { fillField } from '../src/helpers';

const enabled = isSuiteEnabled('webform');
const config = getSuiteConfig('webform') as any;
const form = config?.form;

test.describe('Webform', () => {
  test.skip(!enabled || !form, 'Webform module not enabled or smoke_test form missing.');

  test('submit smoke_test form', async ({ page }) => {
    const response = await page.goto(form.path);
    expect(response?.status(), `${form.path} should return 200`).toBe(200);

    // Fill every field.
    for (const field of form.fields) {
      await fillField(page, field.title, field.type);
    }

    // Submit.
    await page.getByRole('button', { name: 'Submit' }).click();
    await page.waitForLoadState('domcontentloaded');

    // Confirmation: message text or URL changed.
    const body = (await page.locator('body').textContent()) ?? '';
    const ok =
      body.toLowerCase().includes('submission') ||
      body.toLowerCase().includes('received') ||
      body.toLowerCase().includes('thank') ||
      !page.url().includes(form.path);

    expect(ok, 'Should see confirmation after submit').toBeTruthy();
  });
});
