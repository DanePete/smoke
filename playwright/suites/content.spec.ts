/**
 * @file
 * Content — creates a test node, verifies it loads, deletes it.
 *
 * Proves the full content pipeline (entity API, database, routing,
 * rendering) works after module updates. Always available since
 * smoke_bot has content creation permissions.
 */

import { test, expect } from '@playwright/test';
import { loadConfig, isSuiteEnabled, isRemote } from '../src/config-reader';
import { loginAsSmokeBot } from '../src/helpers';

const config = loadConfig();
const auth = config.suites.auth as any;
const enabled = isSuiteEnabled('content');
const remote = isRemote();

test.describe('Content', () => {
  test.skip(!enabled, 'Content suite is disabled.');
  test.skip(remote, 'Content creation skipped on remote targets.');
  test.skip(!auth?.testUser, 'Auth not configured — cannot create content.');

  test('create, view, and delete a test page', async ({ page }) => {
    await loginAsSmokeBot(page, auth.testUser, auth.testPassword);

    // Navigate to node/add/page.
    const addResponse = await page.goto('/node/add/page');
    if (addResponse?.status() === 403 || addResponse?.status() === 404) {
      test.skip(true, 'smoke_bot cannot access node/add/page — missing permission or content type.');
      return;
    }
    expect(addResponse?.status()).toBe(200);

    // Fill title.
    const title = `Smoke Test ${Date.now()}`;
    await page.getByLabel('Title').fill(title);

    // Submit the form.
    await page.getByRole('button', { name: 'Save' }).click();
    await page.waitForLoadState('domcontentloaded');

    // Verify the page loaded successfully.
    const body = await page.locator('body').textContent() ?? '';
    expect(body).toContain(title);
    expect(body).not.toContain('Fatal error');

    // Capture the node URL for cleanup.
    const nodeUrl = page.url();

    // Delete the test node.
    const deleteUrl = nodeUrl.replace(/\/?$/, '/delete');
    await page.goto(deleteUrl);

    // Click the delete confirmation button.
    const deleteBtn = page.getByRole('button', { name: 'Delete' });
    if (await deleteBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
      await deleteBtn.click();
      await page.waitForLoadState('domcontentloaded');
    }
  });
});
