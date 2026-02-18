import { defineConfig, devices } from '@playwright/test';
import { readFileSync, existsSync } from 'fs';
import { resolve } from 'path';

// Read the Drupal-generated config.
const configPath = resolve(__dirname, '.smoke-config.json');
let smokeConfig: Record<string, any> = {};
if (existsSync(configPath)) {
  smokeConfig = JSON.parse(readFileSync(configPath, 'utf-8'));
}

const baseURL = smokeConfig.baseUrl || process.env.DDEV_PRIMARY_URL || 'https://localhost';
const timeout = smokeConfig.timeout || 10_000;

export default defineConfig({
  testDir: './suites',
  timeout,
  expect: { timeout: 5_000 },
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: 0,
  workers: 1,

  reporter: [
    ['json', { outputFile: 'results.json' }],
  ],

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
