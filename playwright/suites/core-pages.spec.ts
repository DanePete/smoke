/**
 * @file
 * Core Pages — verifies critical pages return 200 with no PHP errors or JS errors.
 */

import { test, expect } from '@playwright/test';
import { loadConfig, isSuiteEnabled } from '../src/config-reader';
import { assertHealthyPage } from '../src/helpers';

const config = loadConfig();
const suiteConfig = config.suites.core_pages;
const enabled = isSuiteEnabled('core_pages');

test.describe('Core Pages', () => {
  test.skip(!enabled, 'Core Pages suite is disabled.');

  // Test static pages from config.
  const pages = (suiteConfig?.pages as Array<{ path: string; label: string }>) ?? [
    { path: '/', label: 'Homepage' },
    { path: '/user/login', label: 'Login page' },
  ];

  for (const { path, label } of pages) {
    test(`${label} (${path}) returns 200 with no PHP errors`, async ({ page }) => {
      await assertHealthyPage(page, path);
    });
  }

  test('homepage has correct site title', async ({ page }) => {
    await page.goto('/');
    if (config.siteTitle) {
      await expect(page).toHaveTitle(new RegExp(config.siteTitle));
    }
  });

  test('homepage has no JavaScript errors', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto('/');
    await page.waitForLoadState('networkidle');
    expect(errors, 'No JS errors on homepage').toEqual([]);
  });

  // Test custom URLs from settings.
  if (config.customUrls && config.customUrls.length > 0) {
    for (const url of config.customUrls) {
      test(`Custom URL ${url} returns 200`, async ({ page }) => {
        await assertHealthyPage(page, url);
      });
    }
  }
});
