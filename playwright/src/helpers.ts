/**
 * @file
 * Shared test helpers for Smoke test suites.
 *
 * These helpers can be imported by both built-in and custom test suites.
 *
 * Usage from custom suites at project root:
 * ```typescript
 * import { readConfig, login, assertHealthyPage } from './web/modules/contrib/smoke/playwright/src/helpers';
 * ```
 *
 * Usage from custom module suites:
 * ```typescript
 * import { readConfig, login } from '../../../../contrib/smoke/playwright/src/helpers';
 * ```
 */

import { Page, expect } from '@playwright/test';
import { existsSync, readFileSync } from 'fs';
import { resolve, dirname } from 'path';

/**
 * Smoke configuration interface.
 */
export interface SmokeConfig {
  baseUrl: string;
  timeout: number;
  username?: string;
  password?: string;
  suites?: Record<string, any>;
  [key: string]: any;
}

/**
 * Reads the Drupal-generated .smoke-config.json file.
 *
 * Searches for the config file in several locations to support different
 * directory structures.
 */
export function readConfig(startDir?: string): SmokeConfig {
  const searchDirs = [
    startDir,
    resolve(__dirname, '..'),
    resolve(__dirname, '../..'),
    process.cwd(),
  ].filter(Boolean);

  for (const dir of searchDirs) {
    const configPath = resolve(dir as string, '.smoke-config.json');
    if (existsSync(configPath)) {
      try {
        return JSON.parse(readFileSync(configPath, 'utf-8'));
      } catch {
        continue;
      }
    }
  }

  // Return defaults if no config found.
  return {
    baseUrl: process.env.DDEV_PRIMARY_URL || 'https://localhost',
    timeout: 30_000,
    username: process.env.SMOKE_USERNAME || 'smoke_bot',
    password: process.env.SMOKE_PASSWORD || '',
  };
}

/**
 * Logs in using the configured smoke credentials.
 *
 * Can use either smoke_bot (for local/DDEV) or remote credentials
 * provided via the config.
 */
export async function login(page: Page, config?: SmokeConfig): Promise<void> {
  const cfg = config || readConfig();
  const username = cfg.username || process.env.SMOKE_USERNAME || 'smoke_bot';
  const password = cfg.password || process.env.SMOKE_PASSWORD || '';

  if (!password) {
    throw new Error('No password configured. Run ddev drush smoke:setup or set SMOKE_PASSWORD env var.');
  }

  await loginAsSmokeBot(page, username, password);
}

/**
 * Asserts that a page returned HTTP 200 and contains no PHP fatal errors.
 */
export async function assertHealthyPage(page: Page, path: string): Promise<void> {
  const response = await page.goto(path);
  expect(response?.status(), `${path} should return HTTP 200`).toBe(200);

  const body = await page.locator('body').textContent();
  expect(body).not.toContain('Fatal error');
  expect(body).not.toContain('The website encountered an unexpected error');
  expect(body).not.toContain('PDOException');
  expect(body).not.toContain('DatabaseExceptionWrapper');
}

/**
 * Asserts the page has no JavaScript console errors.
 */
export async function assertNoJsErrors(page: Page, path: string): Promise<string[]> {
  const errors: string[] = [];
  page.on('pageerror', (error) => {
    errors.push(error.message);
  });

  await page.goto(path);
  await page.waitForLoadState('domcontentloaded');

  return errors;
}

/**
 * Logs in as a specific user.
 */
export async function loginAsSmokeBot(
  page: Page,
  username: string,
  password: string,
): Promise<void> {
  await page.goto('/user/login');
  await page.getByLabel('Username').fill(username);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: 'Log in' }).click();

  // Wait for redirect after login.
  await page.waitForURL(/\/user\/\d+/);
}

/**
 * Fills a webform field based on its type.
 */
export async function fillField(
  page: Page,
  title: string,
  type: string,
): Promise<void> {
  const field = page.getByLabel(title);

  switch (type) {
    case 'email':
      await field.fill('smoke_test@example.com');
      break;
    case 'tel':
      await field.fill('612-555-0199');
      break;
    case 'number':
      await field.fill('42');
      break;
    case 'textarea':
      await field.fill('Automated smoke test entry.');
      break;
    case 'textfield':
    default:
      await field.fill('Smoke Test');
      break;
  }
}
