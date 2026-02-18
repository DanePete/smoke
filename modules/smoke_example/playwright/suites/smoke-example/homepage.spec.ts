/**
 * @file
 * Example suite — homepage and basic page checks.
 *
 * This file demonstrates how to write a custom Smoke suite that plugs into
 * the Smoke module. When Smoke runs this suite, it copies these spec files
 * into its own playwright/suites/ directory, so imports always resolve from
 * Smoke's tree:
 *
 *   ../../src/config-reader  →  smoke/playwright/src/config-reader
 *   ../../src/helpers         →  smoke/playwright/src/helpers
 */
import { test, expect } from '@playwright/test';
import { isSuiteEnabled } from '../../src/config-reader';
import { assertHealthyPage } from '../../src/helpers';

const enabled = isSuiteEnabled('smoke_example');

test.describe('Example', () => {
  test.skip(!enabled, 'Example suite is disabled.');

  test('homepage returns 200 with no PHP errors', async ({ page }) => {
    await assertHealthyPage(page, '/');
  });

  test('user login page is accessible', async ({ page }) => {
    await assertHealthyPage(page, '/user/login');
  });

  test('homepage has a page title', async ({ page }) => {
    await page.goto('/');
    const title = await page.title();
    expect(title.length).toBeGreaterThan(0);
  });
});
