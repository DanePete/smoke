/**
 * @file
 * Core Pages — verifies critical pages return 200 with no PHP errors or JS errors.
 *
 * Inspired by Lullabot's playwright-drupal patterns:
 * - Console error capture on every tested page
 * - Broken image detection
 * - Mixed content warnings on HTTPS sites
 */

import { test, expect } from '@playwright/test';
import { loadConfig, isSuiteEnabled } from '../src/config-reader';
import { assertHealthyPage } from '../src/helpers';

const config = loadConfig();
const suiteConfig = config.suites.core_pages;
const enabled = isSuiteEnabled('core_pages');

test.describe('Core Pages', () => {
  test.skip(!enabled, 'Core Pages suite is disabled.');

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

  test('no JavaScript console errors on key pages', async ({ page }) => {
    const allErrors: { path: string; errors: string[] }[] = [];

    for (const { path } of pages) {
      const pageErrors: string[] = [];
      const handler = (error: Error) => pageErrors.push(error.message);

      page.on('pageerror', handler);
      await page.goto(path);
      await page.waitForLoadState('domcontentloaded');

      if (pageErrors.length > 0) {
        allErrors.push({ path, errors: pageErrors });
      }

      page.removeListener('pageerror', handler);
    }

    expect(
      allErrors,
      `JS errors found:\n${allErrors.map((e) => `  ${e.path}: ${e.errors.join(', ')}`).join('\n')}`,
    ).toHaveLength(0);
  });

  test('no broken images on homepage', async ({ page }) => {
    await page.goto('/');
    await page.waitForLoadState('domcontentloaded');
    await page.waitForTimeout(1000);

    const brokenImages = await page.evaluate(() => {
      return Array.from(document.querySelectorAll('img'))
        .filter((img) => {
          if (img.width <= 1 && img.height <= 1) return false;
          if (!img.src) return false;
          return img.complete && img.naturalWidth === 0;
        })
        .map((img) => img.src);
    });

    expect(
      brokenImages,
      `Broken images: ${brokenImages.join(', ')}`,
    ).toHaveLength(0);
  });

  test('no mixed content on homepage', async ({ page }) => {
    const mixedContent: string[] = [];

    page.on('response', (response) => {
      const pageUrl = page.url();
      const resourceUrl = response.url();
      if (
        pageUrl.startsWith('https://') &&
        resourceUrl.startsWith('http://') &&
        !resourceUrl.startsWith('http://localhost')
      ) {
        mixedContent.push(resourceUrl);
      }
    });

    await page.goto('/');
    await page.waitForLoadState('domcontentloaded');

    expect(
      mixedContent,
      `Mixed content resources: ${mixedContent.join(', ')}`,
    ).toHaveLength(0);
  });

  test('404 page returns proper error (not WSOD)', async ({ page }) => {
    const response = await page.goto('/smoke-test-nonexistent-page-xyz-404');
    expect(response?.status()).toBe(404);

    const body = await page.locator('body').textContent() ?? '';
    expect(body).not.toContain('Fatal error');
    expect(body).not.toContain('The website encountered an unexpected error');
    expect(body.length, 'Page should have content (not blank)').toBeGreaterThan(50);
  });

  test('403 page returns access denied (not WSOD)', async ({ page }) => {
    const response = await page.goto('/admin');
    const status = response?.status() ?? 0;
    expect([403, 302, 301]).toContain(status);

    if (status === 403) {
      const body = await page.locator('body').textContent() ?? '';
      expect(body).not.toContain('Fatal error');
      expect(body).not.toContain('The website encountered an unexpected error');
    }
  });

  if (config.customUrls && config.customUrls.length > 0) {
    for (const url of config.customUrls) {
      test(`Custom URL ${url} returns 200`, async ({ page }) => {
        await assertHealthyPage(page, url);
      });
    }
  }
});
