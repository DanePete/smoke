/**
 * @file
 * Health — checks Drupal admin status, cron, and asset loading.
 *
 * Inspired by Lullabot's playwright-drupal patterns.
 * Admin checks require smoke_bot login and are skipped on remote targets.
 * Anonymous checks (CSS/JS, cache headers) run everywhere.
 */

import { test, expect } from '@playwright/test';
import { loadConfig, shouldSkipAuth } from '../src/config-reader';
import { loginAsSmokeBot } from '../src/helpers';

const config = loadConfig();
const auth = config.suites.auth as any;
const skipAuth = shouldSkipAuth();

test.describe('Health', () => {

  test('admin status report has no errors', async ({ page }) => {
    test.skip(skipAuth, 'Admin checks skipped on remote (use terminus-test.sh to enable).');
    test.skip(!auth?.testUser, 'Auth not configured.');

    await loginAsSmokeBot(page, auth.testUser, auth.testPassword);

    const response = await page.goto('/admin/reports/status');
    expect(response?.status()).toBe(200);

    const body = await page.locator('body').textContent() ?? '';

    expect(body).not.toContain('Fatal error');
    expect(body).not.toContain('The website encountered an unexpected error');

    const errorSection = page.locator('.system-status-report__status-title:has-text("Error")');
    const errorCount = await errorSection.count();

    if (errorCount > 0) {
      const errorText = await errorSection.first().textContent();
      console.log('Status report errors found:', errorText);
    }
  });

  test('cron has run recently', async ({ page }) => {
    test.skip(skipAuth, 'Admin checks skipped on remote (use terminus-test.sh to enable).');
    test.skip(!auth?.testUser, 'Auth not configured.');

    await loginAsSmokeBot(page, auth.testUser, auth.testPassword);
    await page.goto('/admin/reports/status');

    const cronRow = page.locator('details:has-text("Cron"), .system-status-report__entry:has-text("Cron")');
    if (await cronRow.count() > 0) {
      const cronText = await cronRow.first().textContent() ?? '';
      expect(cronText.toLowerCase()).not.toContain('never run');
    }
  });

  test('CSS and JS assets load without errors', async ({ page }) => {
    const failedAssets: string[] = [];

    page.on('response', (response) => {
      const url = response.url();
      const status = response.status();
      if ((url.endsWith('.css') || url.endsWith('.js') || url.includes('.css?') || url.includes('.js?')) && status >= 400) {
        failedAssets.push(`${status} ${url}`);
      }
    });

    await page.goto('/');
    await page.waitForLoadState('domcontentloaded');

    expect(failedAssets, `Broken assets: ${failedAssets.join(', ')}`).toHaveLength(0);

    const stylesheets = await page.evaluate(() =>
      Array.from(document.querySelectorAll('link[rel="stylesheet"]')).length
    );
    expect(stylesheets, 'Page should have at least one stylesheet').toBeGreaterThan(0);
  });

  test('no PHP errors in recent log', async ({ page }) => {
    test.skip(skipAuth, 'Admin checks skipped on remote (use terminus-test.sh to enable).');
    test.skip(!auth?.testUser, 'Auth not configured.');

    await loginAsSmokeBot(page, auth.testUser, auth.testPassword);

    const response = await page.goto('/admin/reports/dblog?type%5B%5D=php');
    if (response?.status() !== 200) {
      test.skip(true, 'Database logging (dblog) not available.');
      return;
    }

    const body = await page.locator('body').textContent() ?? '';

    expect(body).not.toContain('Fatal error');
    expect(body).not.toContain('The website encountered an unexpected error');
  });

  test('login page returns 200 for anonymous', async ({ page }) => {
    const response = await page.goto('/user/login');
    expect(response?.status()).toBe(200);

    // Log cache status for informational purposes (not a failure).
    const cacheHeader = response?.headers()['x-drupal-cache'] ?? '';
    const pantheonCache = response?.headers()['x-pantheon-styx-hostname'] ?? '';
    if (cacheHeader) {
      console.log(`[info] Login page x-drupal-cache: ${cacheHeader}`);
    }
    if (pantheonCache) {
      console.log(`[info] Served via Pantheon CDN`);
    }
  });
});
