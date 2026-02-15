/**
 * @file
 * Webform — submits the smoke_test form and confirms it works.
 *
 * The Drupal module auto-creates the smoke_test form locally. On remote
 * targets we still try to load it — if it exists (config was deployed),
 * we test it; if it 404s, we skip gracefully.
 */

import { test, expect } from '@playwright/test';
import { isSuiteEnabled, getSuiteConfig, isRemote } from '../src/config-reader';
import { fillField } from '../src/helpers';

const enabled = isSuiteEnabled('webform');
const config = getSuiteConfig('webform') as any;
const form = config?.form;
const remote = isRemote();

test.describe('Webform', () => {
  test.skip(!enabled || !form, 'Webform module not enabled or smoke_test form missing.');

  test('smoke_test form page loads', async ({ page }) => {
    const response = await page.goto(form.path);
    const status = response?.status() ?? 0;

    if (remote && status === 404) {
      test.skip(true, `smoke_test form not found on remote (${form.path} returned 404). Deploy the webform config or run drush smoke:setup on the remote.`);
      return;
    }

    expect(status, `${form.path} should return 200`).toBe(200);
  });

  test('submit smoke_test form', async ({ page }) => {
    const response = await page.goto(form.path);
    const status = response?.status() ?? 0;

    if (remote && status === 404) {
      test.skip(true, 'smoke_test form not found on remote — skipping submission test.');
      return;
    }

    expect(status, `${form.path} should return 200`).toBe(200);

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
