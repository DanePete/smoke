/**
 * @file
 * Accessibility — runs axe-core WCAG 2.1 AA scan on key pages.
 *
 * Inspired by Lullabot's playwright-drupal accessible screenshot pattern.
 * Checks for critical accessibility violations on the homepage and login page.
 */

import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { isSuiteEnabled, loadConfig } from '../src/config-reader';

const enabled = isSuiteEnabled('accessibility');
const config = loadConfig();

test.describe('Accessibility', () => {
  test.skip(!enabled, 'Accessibility suite is disabled.');

  test('homepage has no critical WCAG 2.1 AA violations', async ({ page }) => {
    await page.goto('/');
    await page.waitForLoadState('domcontentloaded');

    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();

    // Only fail on critical and serious violations.
    const critical = results.violations.filter(
      (v) => v.impact === 'critical' || v.impact === 'serious',
    );

    if (critical.length > 0) {
      const summary = critical.map(
        (v) => `  [${v.impact}] ${v.id}: ${v.description} (${v.nodes.length} instances)`,
      ).join('\n');
      expect(critical, `WCAG violations on homepage:\n${summary}`).toHaveLength(0);
    }
  });

  test('login page has no critical WCAG 2.1 AA violations', async ({ page }) => {
    await page.goto('/user/login');
    await page.waitForLoadState('domcontentloaded');

    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();

    const critical = results.violations.filter(
      (v) => v.impact === 'critical' || v.impact === 'serious',
    );

    if (critical.length > 0) {
      const summary = critical.map(
        (v) => `  [${v.impact}] ${v.id}: ${v.description} (${v.nodes.length} instances)`,
      ).join('\n');
      expect(critical, `WCAG violations on login page:\n${summary}`).toHaveLength(0);
    }
  });

  test('homepage axe best-practice scan', async ({ page }) => {
    await page.goto('/');
    await page.waitForLoadState('domcontentloaded');

    const results = await new AxeBuilder({ page })
      .withTags(['best-practice'])
      .analyze();

    // Best-practice violations are informational — log them, don't fail.
    if (results.violations.length > 0) {
      console.log(
        `[a11y best-practice] ${results.violations.length} issues:`,
        results.violations.map((v) => `${v.id}: ${v.description}`).join('; '),
      );
    }

    // This test always passes — it's for reporting only.
    expect(true).toBeTruthy();
  });
});
