/**
 * @file
 * Search — tests search page loads and has a functioning search form.
 */

import { test, expect } from '@playwright/test';
import { isSuiteEnabled, getSuiteConfig } from '../src/config-reader';
import { assertHealthyPage } from '../src/helpers';

const enabled = isSuiteEnabled('search');
const searchConfig = getSuiteConfig('search');
const searchPath = (searchConfig as any)?.searchPath ?? '/search';

test.describe('Search', () => {
  test.skip(!enabled, 'Search suite is disabled — module not detected.');

  test('search page loads', async ({ page }) => {
    await assertHealthyPage(page, searchPath);
  });

  test('search page has a search input', async ({ page }) => {
    await page.goto(searchPath);

    const searchInput = page.locator(
      'input[type="search"], input[type="text"][name*="search"], input[name*="keys"], input[name*="query"], form input[type="text"]'
    );
    await expect(searchInput.first()).toBeVisible({ timeout: 10_000 });
  });
});
