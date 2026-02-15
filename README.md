# Smoke

Automated smoke testing for Drupal. One module, one command, full coverage.

Smoke auto-detects what's installed on your site — webform, commerce, search API — and runs Playwright browser tests against it. Results show up in a clean admin dashboard or beautiful Drush CLI output.

## Requirements

- Drupal 10 or 11
- DDEV local development environment
- [Lullabot/ddev-playwright](https://github.com/Lullabot/ddev-playwright) addon (installed automatically by `drush smoke:setup`)

## Install

Add the repository to your project's `composer.json`:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/DanePete/smoke"
    }
  ]
}
```

Then install:

```bash
ddev composer require drupal/smoke:dev-main
ddev drush en smoke -y
ddev drush smoke:setup
```

The setup command will walk you through installing the DDEV Playwright addon if it's not already present.

## Usage

### Drush (CLI)

```bash
# Run all smoke tests
ddev drush smoke

# List detected suites and their status
ddev drush smoke:list

# Run a single suite
ddev drush smoke:suite webform
ddev drush smoke:suite commerce
ddev drush smoke:suite auth
```

### Admin Dashboard

Visit `/admin/reports/smoke` to see test results, run tests, and configure suites.

Settings at `/admin/config/development/smoke` to enable/disable suites and add custom URLs.

## What It Tests

| Suite | Module | What's Checked |
|-------|--------|----------------|
| **Core Pages** | always | Homepage, login page return 200, no PHP errors, no JS errors |
| **Authentication** | always | Login form works, invalid creds show errors, smoke_bot can log in |
| **Webform** | `webform` | Auto-creates a test form, verifies render, submit, and validation |
| **Commerce** | `commerce` | Product catalog, cart endpoint, checkout endpoint |
| **Search** | `search_api` | Search page loads, search form is present |

Suites are auto-detected. If a module isn't installed, its suite is skipped.

## How It Works

1. **ModuleDetector** scans your site for installed modules and testable features
2. **ConfigGenerator** writes a JSON bridge file with everything Playwright needs
3. **Playwright** reads the config and runs browser tests in Chromium
4. **TestRunner** parses the JSON results back into Drupal
5. Results are stored in Drupal state and displayed in the dashboard or CLI

## Custom URLs

Add extra pages to test via the settings form or `smoke.settings` config:

```yaml
# In config/sync or via the admin UI
custom_urls:
  - /about
  - /pricing
  - /contact
```

Each URL is checked for HTTP 200 and no PHP fatal errors.

## Uninstall

```bash
ddev drush pmu smoke -y
ddev composer remove drupal/smoke
```

This removes the test user, role, and all stored results.

## License

GPL-2.0-or-later
