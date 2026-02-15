/**
 * @file
 * Shared test helpers for Smoke test suites.
 */

import { Page, expect } from '@playwright/test';

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
 * Logs in as the smoke_bot test user.
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
