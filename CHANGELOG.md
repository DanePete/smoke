# Changelog

All notable changes to the Smoke module are documented in this file.

## [Unreleased]

## [1.4.4] - 2026-02-17

### Changed

- `drush smoke:setup` and `drush smoke:init` now run `npm install --save-dev @playwright/test` at project root so the VS Code/Cursor Playwright extension can discover and run tests without manual install.

## [1.4.3] - 2026-02-17

### Changed

- Require Node.js 20+: `drush smoke:setup` and test runs now check Node version and fail with a clear message (e.g. "nvm use 20") if Node is missing or older than 20. Playwright config throws the same error when loaded with old Node (IDE/direct npx).

## [1.4.2] - 2026-02-17

### Bug fixes

- Fixed smoke_pantheon: added `drush.services.yml` so Drush discovers `smoke:pantheon`, `smoke:pantheon:check`, `smoke:pantheon:set`, `smoke:pantheon:sites` (fixes "Command smoke:pantheon is not defined").

## [1.1.5] - 2026-02-17

### Changed

- Accessibility suite disabled for now: removed from detected suites and default config; can be re-enabled by uncommenting in ModuleDetector and config.

## [1.1.4] - 2026-02-17

### Bug fixes

- Fixed Drush command instantiation: added missing constructor arguments in `drush.services.yml` so all smoke commands receive correct dependency injection (fixes ArgumentCountError when running `drush smoke`).

## [1.1.3] - 2026-02-17

### Bug fixes

- Fixed broken duration display in dashboard status messages (`@times` placeholder mismatch).
- Fixed timeout default inconsistency: config install now defaults to 30000ms (was 10000ms vs. 30000ms in form).

### Improvements

- Replaced static `\Drupal::` service calls with proper dependency injection in DashboardController and SettingsForm.
- Added suite ID validation in `runSuite()` — rejects unknown suite IDs instead of attempting to run nonexistent spec files.
- Uninstall now cleans up `smoke_test` webform and `.smoke-config.json`.
- Added CHANGELOG.md.
- Added PHPUnit tests for install hooks, config schema, and suite labels.
- Code quality: PHPStan, PHPCS, CSpell, Stylelint, and kernel test fixes for pipeline.

## [1.1.0-beta3] - 2026-02-14

### New features

- Live progress bar on `drush smoke --run` — suites execute sequentially with real-time progress display.
- `drush smoke:fix` command — analyzes last test results and auto-fixes common issues.
  - `--sitemap` regenerates XML sitemap (simple_sitemap / xmlsitemap).
  - `--all` fixes all detected issues.
- Regenerate sitemap button on the dashboard Sitemap card.
- Admin URLs (Dashboard, Settings, Status report, Recent log) shown in CLI output after test runs.

### Bug fixes

- Fixed sitemap spec: was checking rendered `textContent()` which strips XML tags — now reads raw response body.
- Fixed `simple_sitemap` API call (`generate()` not `generateSitemap()`).

### Improvements

- Comprehensive README rewrite: install locations table, full uninstall/cleanup guide, `smoke:fix` documentation.

## [1.1.0-beta2] - 2026-02-14

### Improvements

- Self-sufficient setup: module no longer requires the third-party `codingsasi/ddev-playwright` DDEV addon.
- Setup commands install only Chromium (~180 MiB) instead of all 5 browser types (~470 MiB).
- `SmokeSetupCommand` checks for existing browser installation to avoid redundant downloads.
- `host-setup.sh` rewritten to handle all Playwright setup internally.

## [1.1.0-beta1] - 2026-02-14

### Improvements

- Promoted to beta: updated `minimum-stability` from `dev` to `beta` in `composer.json`.
- Added `PROJECT_PAGE.md` placeholder for Drupal.org.

## [1.0.0] - 2026-02-14

Initial stable release.

### Features

- 9 auto-detected test suites: Core Pages, Authentication, Webform, Commerce, Search, Health, Sitemap, Content, Accessibility.
- Admin dashboard under Reports > Smoke Tests with per-suite results, action links, and technical details.
- Settings form for enabling/disabling suites, custom URLs, and timeout configuration.
- Drush commands: `smoke:run`, `smoke:suite`, `smoke:list`, `smoke:setup`.
- `smoke_bot` user and role created on install for authenticated tests.
- Playwright-based test engine with JSON reporter bridge.
- Remote testing support via `--target` flag.
- Host setup script (`host-setup.sh`) for one-command install.

[Unreleased]: https://git.drupalcode.org/project/smoke/-/compare/1.4.4...1.0.x
[1.4.4]: https://git.drupalcode.org/project/smoke/-/compare/1.4.3...1.4.4
[1.4.3]: https://git.drupalcode.org/project/smoke/-/compare/1.4.2...1.4.3
[1.4.2]: https://git.drupalcode.org/project/smoke/-/compare/1.4.1...1.4.2
[1.1.5]: https://git.drupalcode.org/project/smoke/-/compare/1.1.4...1.1.5
[1.1.4]: https://git.drupalcode.org/project/smoke/-/compare/1.1.3...1.1.4
[1.1.3]: https://git.drupalcode.org/project/smoke/-/compare/1.1.2...1.1.3
[1.1.0-beta3]: https://git.drupalcode.org/project/smoke/-/compare/1.1.0-beta2...1.1.0-beta3
[1.1.0-beta2]: https://git.drupalcode.org/project/smoke/-/compare/1.1.0-beta1...1.1.0-beta2
[1.1.0-beta1]: https://git.drupalcode.org/project/smoke/-/compare/1.0.0...1.1.0-beta1
[1.0.0]: https://git.drupalcode.org/project/smoke/-/tags/1.0.0
