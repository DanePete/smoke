/**
 * Smoke Tests - Shared Configuration
 *
 * This module exports helper functions and base configuration that can be
 * used by the main Smoke playwright config and by project-level configs
 * for custom agency tests.
 *
 * For VS Code/Cursor Playwright extension integration, create a
 * playwright.config.ts at your project root that imports from this module.
 */

import { readFileSync, existsSync, readdirSync, statSync } from 'fs';
import { resolve, join, dirname } from 'path';
import type { PlaywrightTestConfig } from '@playwright/test';

/**
 * Smoke configuration from Drupal.
 */
export interface SmokeConfig {
  baseUrl: string;
  timeout: number;
  suites?: Record<string, any>;
  customPaths?: string[];
  [key: string]: any;
}

/**
 * Reads the Drupal-generated .smoke-config.json file.
 */
export function readSmokeConfig(configDir: string = __dirname): SmokeConfig {
  const configPath = resolve(configDir, '.smoke-config.json');
  if (existsSync(configPath)) {
    try {
      return JSON.parse(readFileSync(configPath, 'utf-8'));
    } catch {
      // Fall through to defaults.
    }
  }

  return {
    baseUrl: process.env.DDEV_PRIMARY_URL || 'https://localhost',
    timeout: 30_000,
  };
}

/**
 * Discovers all test directories (built-in + custom).
 *
 * Looks for spec files in:
 * - Built-in: web/modules/contrib/smoke/playwright/suites/
 * - Custom project: playwright-smoke/suites/ (at project root)
 * - Custom modules: web/modules/custom/*/playwright/suites/
 *
 * @param projectRoot - The project root directory (where web/ lives)
 * @param smokeModulePath - Path to the smoke module's playwright dir
 */
export function discoverTestDirs(
  projectRoot: string,
  smokeModulePath: string = resolve(__dirname)
): string[] {
  const dirs: string[] = [];

  // Always include built-in suites.
  const builtInDir = resolve(smokeModulePath, 'suites');
  if (existsSync(builtInDir)) {
    dirs.push(builtInDir);
  }

  // Project-level custom suites (playwright-smoke/suites/).
  const customProjectDir = resolve(projectRoot, 'playwright-smoke', 'suites');
  if (existsSync(customProjectDir)) {
    dirs.push(customProjectDir);
  }

  // Custom module suites (web/modules/custom/*/playwright/suites/).
  const customModulesDir = resolve(projectRoot, 'web', 'modules', 'custom');
  if (existsSync(customModulesDir)) {
    try {
      const modules = readdirSync(customModulesDir);
      for (const mod of modules) {
        const modSuitesDir = resolve(customModulesDir, mod, 'playwright', 'suites');
        if (existsSync(modSuitesDir) && statSync(modSuitesDir).isDirectory()) {
          dirs.push(modSuitesDir);
        }
      }
    } catch {
      // Ignore read errors.
    }
  }

  // Contrib module suites (web/modules/contrib/*/playwright/suites/) - excluding smoke itself.
  const contribModulesDir = resolve(projectRoot, 'web', 'modules', 'contrib');
  if (existsSync(contribModulesDir)) {
    try {
      const modules = readdirSync(contribModulesDir);
      for (const mod of modules) {
        if (mod === 'smoke') continue; // Skip smoke itself, handled above.
        const modSuitesDir = resolve(contribModulesDir, mod, 'playwright', 'suites');
        if (existsSync(modSuitesDir) && statSync(modSuitesDir).isDirectory()) {
          dirs.push(modSuitesDir);
        }
      }
    } catch {
      // Ignore read errors.
    }
  }

  return dirs;
}

/**
 * Gets all spec file patterns for Playwright testMatch.
 */
export function getTestMatch(testDirs: string[]): string[] {
  return testDirs.map(dir => join(dir, '**/*.spec.ts'));
}

/**
 * Determines if running in CI environment.
 */
export function isCI(): boolean {
  return !!process.env.CI;
}

/**
 * Gets the base URL for tests.
 */
export function getBaseURL(smokeConfig: SmokeConfig): string {
  return smokeConfig.baseUrl || process.env.DDEV_PRIMARY_URL || 'https://localhost';
}

/**
 * Default Chromium launch options for DDEV/Docker environments.
 */
export const defaultLaunchOptions = {
  headless: true,
  args: [
    '--no-sandbox',
    '--disable-setuid-sandbox',
    '--disable-dev-shm-usage',
    '--disable-gpu',
  ],
};

/**
 * Creates a base Playwright configuration optimized for Drupal smoke testing.
 *
 * Use this in your project's playwright.config.ts:
 *
 * ```typescript
 * import { createSmokeConfig } from './web/modules/contrib/smoke/playwright/smoke-config';
 *
 * export default createSmokeConfig({
 *   projectRoot: __dirname,
 *   // Override any settings here.
 * });
 * ```
 */
export function createSmokeConfig(options: {
  projectRoot: string;
  smokeModulePath?: string;
  additionalTestDirs?: string[];
  overrides?: Partial<PlaywrightTestConfig>;
}): PlaywrightTestConfig {
  const {
    projectRoot,
    smokeModulePath = resolve(projectRoot, 'web/modules/contrib/smoke/playwright'),
    additionalTestDirs = [],
    overrides = {},
  } = options;

  const smokeConfig = readSmokeConfig(smokeModulePath);
  const baseURL = getBaseURL(smokeConfig);
  const timeout = smokeConfig.timeout || 30_000;
  const ci = isCI();

  // Discover all test directories.
  const testDirs = [
    ...discoverTestDirs(projectRoot, smokeModulePath),
    ...additionalTestDirs,
  ];

  // Feature flags from environment.
  const parallelMode = !!process.env.SMOKE_PARALLEL;
  const verboseMode = !!process.env.SMOKE_VERBOSE;
  const htmlReportPath = process.env.SMOKE_HTML_PATH || '';

  // Build reporters.
  const reporters: any[] = [
    ['json', { outputFile: resolve(smokeModulePath, 'results.json') }],
  ];
  if (verboseMode) {
    reporters.push(['list']);
  }
  if (htmlReportPath) {
    reporters.push(['html', { outputFolder: htmlReportPath, open: 'never' }]);
  }

  return {
    // Use testMatch instead of testDir for multiple directories.
    testMatch: getTestMatch(testDirs),

    timeout,
    expect: { timeout: 5_000 },
    fullyParallel: true,
    forbidOnly: ci,
    retries: ci ? 2 : 0,
    workers: parallelMode ? '50%' : 1,

    reporter: reporters,

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
          browserName: 'chromium',
          launchOptions: defaultLaunchOptions,
        },
      },
    ],

    ...overrides,
  };
}
