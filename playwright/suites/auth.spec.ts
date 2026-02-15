/**
 * @file
 * Authentication — tests login page, valid/invalid login, and password reset.
 */

import { test, expect } from '@playwright/test';
import { isSuiteEnabled, getSuiteConfig, isRemote } from '../src/config-reader';
import { loginAsSmokeBot } from '../src/helpers';

const enabled = isSuiteEnabled('auth');
const authConfig = getSuiteConfig('auth');
const remote = isRemote();

test.describe('Authentication', () => {
  test.skip(!enabled, 'Authentication suite is disabled.');

  test('login page loads', async ({ page }) => {
    const response = await page.goto('/user/login');
    expect(response?.status()).toBe(200);
  });

  test('login page has username and password fields', async ({ page }) => {
    await page.goto('/user/login');
    await expect(page.getByLabel('Username')).toBeVisible();
    await expect(page.getByLabel('Password')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Log in' })).toBeVisible();
  });

  test('invalid login shows error message', async ({ page }) => {
    // Skip on remote — we don't want to trigger flood protection.
    test.skip(remote, 'Skipped on remote targets.');

    await page.goto('/user/login');
    await page.getByLabel('Username').fill('nonexistent_smoke_user_xyz');
    await page.getByLabel('Password').fill('definitely_wrong_password');
    await page.getByRole('button', { name: 'Log in' }).click();

    await expect(
      page.locator('.messages--error, .messages.error').first()
    ).toBeVisible({ timeout: 10_000 });
  });

  test('smoke_bot can log in', async ({ page }) => {
    // smoke_bot only exists on the local DDEV site.
    test.skip(remote, 'smoke_bot does not exist on remote targets.');

    const user = (authConfig as any)?.testUser;
    const pass = (authConfig as any)?.testPassword;

    if (!user || !pass) {
      test.skip(true, 'smoke_bot credentials not available.');
      return;
    }

    await loginAsSmokeBot(page, user, pass);
    await expect(page).toHaveURL(/\/user\/\d+/);
  });

  test('password reset page loads', async ({ page }) => {
    const response = await page.goto('/user/password');
    expect(response?.status()).toBe(200);
    await expect(page.getByLabel('Username or email address')).toBeVisible();
  });
});
