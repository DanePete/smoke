/**
 * @file
 * Reads the Drupal-generated .smoke-config.json bridge file.
 *
 * This is the single source of truth for what Drupal detected:
 * which modules are installed, which webforms exist, etc.
 */

import { readFileSync, existsSync } from 'fs';
import { resolve } from 'path';

export interface SmokeField {
  key: string;
  type: string;
  title: string;
  required: boolean;
}

export interface SmokeWebform {
  id: string;
  title: string;
  path: string;
  fields: SmokeField[];
}

export interface SmokeSuiteConfig {
  enabled: boolean;
  detected: boolean;
  label: string;
  description: string;
  [key: string]: unknown;
}

export interface SmokeConfig {
  baseUrl: string;
  remote: boolean;
  remoteAuth: boolean;
  siteTitle: string;
  timeout: number;
  customUrls: string[];
  suites: Record<string, SmokeSuiteConfig & Record<string, unknown>>;
}

const CONFIG_PATH = resolve(__dirname, '..', '.smoke-config.json');

let _config: SmokeConfig | null = null;

/**
 * Loads and caches the smoke config.
 */
export function loadConfig(): SmokeConfig {
  if (_config) return _config;

  if (!existsSync(CONFIG_PATH)) {
    throw new Error(
      `Smoke config not found at ${CONFIG_PATH}. Run: drush smoke:setup`
    );
  }

  const raw = readFileSync(CONFIG_PATH, 'utf-8');
  _config = JSON.parse(raw) as SmokeConfig;
  return _config;
}

/**
 * Returns whether a specific suite is enabled.
 */
export function isSuiteEnabled(suiteId: string): boolean {
  const config = loadConfig();
  const suite = config.suites[suiteId];
  return !!suite?.enabled;
}

/**
 * Returns the suite config for a given suite ID.
 */
export function getSuiteConfig(suiteId: string): SmokeSuiteConfig | null {
  const config = loadConfig();
  return config.suites[suiteId] ?? null;
}

/**
 * Returns true when testing a remote URL (not the local DDEV site).
 */
export function isRemote(): boolean {
  const config = loadConfig();
  return !!config.remote;
}

/**
 * Returns true when remote auth credentials are available (via Terminus).
 *
 * When true, auth-dependent tests should run even on remote targets
 * because smoke_bot was set up on the remote via terminus.
 */
export function hasRemoteAuth(): boolean {
  const config = loadConfig();
  return !!config.remoteAuth;
}

/**
 * Returns true when auth-dependent tests should be skipped.
 *
 * Skips on remote targets UNLESS Terminus provided remote credentials.
 */
export function shouldSkipAuth(): boolean {
  return isRemote() && !hasRemoteAuth();
}
