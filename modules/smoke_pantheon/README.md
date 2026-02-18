# Smoke Pantheon

Auto-detect and test Pantheon dev/test/live environments with a single command.

## Installation

```bash
ddev drush en smoke_pantheon
```

## Usage

### Show detected environments

```bash
ddev drush smoke:pantheon
```

Output:
```
  Smoke — Pantheon
  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

  ✓ Site detected: mnair-shop
  ✓ Terminus: authenticated as you@example.com

  Environments

     dev    https://dev-mnair-shop.pantheonsite.io
     test   https://test-mnair-shop.pantheonsite.io
     live   https://live-mnair-shop.pantheonsite.io

  Commands

     drush smoke:pantheon dev     Test dev environment
     drush smoke:pantheon test    Test test environment
     drush smoke:pantheon live    Test live environment
     drush smoke:pantheon all     Test all environments
```

### Test specific environment

```bash
# Test dev
ddev drush smoke:pantheon dev

# Test test (staging)
ddev drush smoke:pantheon test

# Test live (production)
ddev drush smoke:pantheon live

# Test all environments sequentially
ddev drush smoke:pantheon all
```

### Options

```bash
# Quick mode (one test per suite)
ddev drush smoke:pantheon dev --quick

# Run only one suite
ddev drush smoke:pantheon test --suite=core_pages
```

## Terminus Integration

The module detects if you have [Terminus](https://pantheon.io/docs/terminus) installed and authenticated. This enables:

- Site validation (verify the site exists and you have access)
- Environment status checking
- Listing all your Pantheon sites

### Install Terminus

```bash
# macOS
brew install pantheon-systems/external/terminus

# Or via Composer
composer global require pantheon-systems/terminus
```

### Authenticate

```bash
terminus auth:login
```

### Validate configuration

```bash
# Check Terminus auth and validate site access
ddev drush smoke:pantheon:check
```

Output:
```
  Smoke — Pantheon Validation
  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

  ✓ Terminus installed (v3.2.1)
  ✓ Authenticated as: you@example.com

  Validating site: mnair-shop

  ✓ Site found: mnair-shop
     Label: Minnesota Air Shop
     Framework: drupal10
     Plan: Basic

  Environment Status

     ✓ dev   https://dev-mnair-shop.pantheonsite.io
     ✓ test  https://test-mnair-shop.pantheonsite.io
     ✓ live  https://live-mnair-shop.pantheonsite.io

  Validation complete.
```

### List your sites

```bash
ddev drush smoke:pantheon:sites
```

## Site Detection

The module auto-detects the Pantheon site name from:

1. **Module configuration** (highest priority)
2. **PANTHEON_SITE** environment variable (when running on Pantheon)
3. **Git remote** named "pantheon"
4. **pantheon.yml** presence + directory name

### Manual configuration

If auto-detection doesn't work correctly:

```bash
# Set the site name manually
ddev drush smoke:pantheon:set your-site-name

# Or via config
ddev drush config:set smoke_pantheon.settings site_name your-site-name
```

## What tests run on remote?

| Suite | Behavior |
|-------|----------|
| Core Pages | ✓ Runs (homepage, 404, robots.txt) |
| Commerce | ✓ Runs |
| Search | ✓ Runs |
| Content | ✓ Runs |
| Webform | ✓ Runs if form exists |
| Auth | ✗ Skips (needs local smoke_bot) |
| Health | ✗ Skips (admin-only) |

## Commands Reference

| Command | Alias | Description |
|---------|-------|-------------|
| `drush smoke:pantheon` | `sp` | Show site info or run tests |
| `drush smoke:pantheon:set` | `sps` | Set site name |
| `drush smoke:pantheon:check` | `spc` | Validate with Terminus |
| `drush smoke:pantheon:sites` | — | List accessible sites |
