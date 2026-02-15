/**
 * @file
 * Sitemap — verifies XML sitemap exists and contains URLs.
 *
 * Auto-detected when simple_sitemap or xmlsitemap module is installed.
 */

import { test, expect } from '@playwright/test';
import { isSuiteEnabled } from '../src/config-reader';

const enabled = isSuiteEnabled('sitemap');

test.describe('Sitemap', () => {
  test.skip(!enabled, 'Sitemap suite not enabled (no sitemap module detected).');

  test('sitemap.xml returns valid XML', async ({ page }) => {
    const response = await page.goto('/sitemap.xml');
    expect(response?.status(), '/sitemap.xml should return 200').toBe(200);

    const contentType = response?.headers()['content-type'] ?? '';
    expect(
      contentType.includes('xml') || contentType.includes('text'),
      `Content-Type should be XML, got: ${contentType}`,
    ).toBeTruthy();
  });

  test('sitemap.xml contains at least one URL', async ({ page }) => {
    await page.goto('/sitemap.xml');

    const body = await page.locator('body').textContent() ?? '';
    const hasUrls =
      body.includes('<loc>') ||
      body.includes('<url>') ||
      body.includes('<sitemap>') ||
      body.includes('sitemapindex');

    expect(hasUrls, 'Sitemap should contain at least one <loc> or <sitemap> entry').toBeTruthy();
  });

  test('sitemap.xml has no PHP errors', async ({ page }) => {
    await page.goto('/sitemap.xml');
    const body = await page.locator('body').textContent() ?? '';
    expect(body).not.toContain('Fatal error');
    expect(body).not.toContain('The website encountered an unexpected error');
  });
});
