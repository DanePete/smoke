/**
 * Smoke Tests - Playwright Configuration
 *
 * This config is used when running tests directly from the smoke module.
 * For VS Code/Cursor Playwright extension integration, use the project-level
 * config by running: ddev drush smoke:init
 *
 * @see ./smoke-config.ts for shared configuration utilities.
 */

import { defineConfig, devices } from '@playwright/test';
import { readFileSync, existsSync, readdirSync, statSync } from 'fs';
import { resolve } from 'path';

// Read the Drupal-generated config.
const configPath = resolve(__dirname, '.smoke-config.json');
let smokeConfig: Record<string, any> = {};
if (existsSync(configPath)) {
  smokeConfig = JSON.parse(readFileSync(configPath, 'utf-8'));
}

const baseURL = smokeConfig.baseUrl || process.env.DDEV_PRIMARY_URL || 'https://localhost';
const timeout = smokeConfig.timeout || 30_000;

// CI detection: enable retries when running in CI environment.
const isCI = !!process.env.CI;

// Feature flags from drush command options.
const parallelMode = !!process.env.SMOKE_PARALLEL;
const verboseMode = !!process.env.SMOKE_VERBOSE;
const htmlReportPath = process.env.SMOKE_HTML_PATH || '';

// Discover test glob patterns relative to __dirname.
function discoverTestPatterns(): string[] {
  const patterns: string[] = [];

  // Built-in suites (always present).
  patterns.push('suites/**/*.spec.ts');

  // Project-level custom suites (../../../playwright-smoke/suites).
  const projectRoot = resolve(__dirname, '../../..');
  const customProjectDir = resolve(projectRoot, 'playwright-smoke', 'suites');
  if (existsSync(customProjectDir)) {
    patterns.push('../../../playwright-smoke/suites/**/*.spec.ts');
  }

  // Custom module suites.
  const customModulesDir = resolve(projectRoot, 'web', 'modules', 'custom');
  if (existsSync(customModulesDir)) {
    try {
      for (const mod of readdirSync(customModulesDir)) {
        const modSuitesDir = resolve(customModulesDir, mod, 'playwright', 'suites');
        if (existsSync(modSuitesDir) && statSync(modSuitesDir).isDirectory()) {
          patterns.push(`../../../web/modules/custom/${mod}/playwright/suites/**/*.spec.ts`);
        }
      }
    } catch { /* ignore */ }
  }

  // Contrib module suites (excluding smoke itself).
  const contribModulesDir = resolve(projectRoot, 'web', 'modules', 'contrib');
  if (existsSync(contribModulesDir)) {
    try {
      for (const mod of readdirSync(contribModulesDir)) {
        if (mod === 'smoke') continue;
        const modSuitesDir = resolve(contribModulesDir, mod, 'playwright', 'suites');
        if (existsSync(modSuitesDir) && statSync(modSuitesDir).isDirectory()) {
          patterns.push(`../../../web/modules/contrib/${mod}/playwright/suites/**/*.spec.ts`);
        }
      }
    } catch { /* ignore */ }
  }

  return patterns;
}

const testPatterns = discoverTestPatterns();

// Build reporters array dynamically.
const reporters: any[] = [
  ['json', { outputFile: 'results.json' }],
];
if (verboseMode) {
  reporters.push(['list']);
}
if (htmlReportPath) {
  reporters.push(['html', { outputFolder: htmlReportPath, open: 'never' }]);
}

export default defineConfig({
  // Relative to this config file.
  testDir: __dirname,

  // Match test patterns (relative glob patterns).
  testMatch: testPatterns,

  timeout,
  expect: { timeout: 5_000 },
  fullyParallel: true,
  forbidOnly: isCI,
  retries: isCI ? 2 : 0,
  workers: parallelMode ? '50%' : 1,

  reporter: reporters,

  // Output directory for traces, screenshots, etc.
  outputDir: resolve(__dirname, 'test-results'),

  use: {
    baseURL,
    ignoreHTTPSErrors: true,
    screenshot: 'only-on-failure',
    trace: 'on-first-retry',
  },

  projects: [
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        launchOptions: {
          headless: true,
          args: [
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--disable-dev-shm-usage',
            '--disable-gpu',
          ],
        },
      },
    },
  ],
});
