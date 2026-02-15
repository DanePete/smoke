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
const timeout = smokeConfig.timeout || 30_000;

export default defineConfig({
  testDir: './suites',
  timeout,
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 2 : 1,

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
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
