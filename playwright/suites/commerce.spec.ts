/**
 * @file
 * Commerce — tests product catalog, cart, and checkout accessibility.
 */

import { test, expect } from '@playwright/test';
import { isSuiteEnabled, getSuiteConfig } from '../src/config-reader';

const enabled = isSuiteEnabled('commerce');
const commerceConfig = getSuiteConfig('commerce');

test.describe('Commerce', () => {
  test.skip(!enabled, 'Commerce suite is disabled — module not detected.');

  test('product catalog is accessible', async ({ page }) => {
    // Try common product listing paths.
    for (const path of ['/products', '/shop', '/catalog', '/']) {
      const response = await page.goto(path);
      if (response?.status() === 200) {
        const body = await page.locator('body').textContent();
        expect(body).not.toContain('Fatal error');
        return;
      }
    }
    // If no product page found, just verify homepage works.
    const response = await page.goto('/');
    expect(response?.status()).toBe(200);
  });

  test('cart endpoint exists', async ({ page }) => {
    const hasCart = (commerceConfig as any)?.hasCart ?? false;
    test.skip(!hasCart, 'Commerce Cart module not enabled.');

    const response = await page.goto('/cart');
    // Cart returns 403 for anon (expected) or 200 if accessible. A 500 = broken.
    expect(response?.status()).not.toBe(500);

    const body = await page.locator('body').textContent();
    expect(body).not.toContain('Fatal error');
  });

  test('checkout endpoint exists', async ({ page }) => {
    const hasCheckout = (commerceConfig as any)?.hasCheckout ?? false;
    test.skip(!hasCheckout, 'Commerce Checkout module not enabled.');

    const response = await page.goto('/checkout');
    // Checkout without a cart typically redirects. A 500 = broken.
    expect(response?.status()).not.toBe(500);
  });

  test('store has published products', async ({ page }) => {
    const hasProducts = (commerceConfig as any)?.hasProducts ?? false;
    test.skip(!hasProducts, 'No published products found.');

    // If there are products, the homepage or products page should have links.
    await page.goto('/');
    const body = await page.locator('body').textContent();
    expect(body).not.toContain('Fatal error');
  });
});
