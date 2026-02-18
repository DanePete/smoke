# Smoke

**Automated smoke testing for Drupal.** One module. One command. Full coverage.

Smoke auto-detects what's installed on your Drupal site — Webform, Commerce, Search API — and runs [Playwright](https://playwright.dev) browser tests against it. Results show up in a clean admin dashboard or via Drush CLI. Built for support teams running Drupal on [DDEV](https://ddev.com).

For a full description, visit the [project page](https://www.drupal.org/project/smoke).

Submit bug reports and feature suggestions, or track changes in the [issue queue](https://www.drupal.org/project/issues/smoke).

---

## Table of Contents

- [Requirements](#requirements)
- [Install](#install)
- [Setup](#setup)
- [Running Tests](#running-tests)
- [Admin Dashboard](#admin-dashboard)
- [Drush Commands](#drush-commands)
- [What It Tests](#what-it-tests)
- [Configuration](#configuration)
- [Custom URLs](#custom-urls)
- [Adding Custom Tests](#adding-custom-tests)
- [After Module Updates](#after-module-updates)
- [Architecture](#architecture)
- [Troubleshooting](#troubleshooting)
- [Uninstall & Cleanup](#uninstall--cleanup)
- [Maintainers](#maintainers)
- [License](#license)

---

## Requirements

- **Drupal** 10 or 11
- **DDEV** local development environment
- **Node.js** 18+ (included in DDEV containers)
- **Composer** (managed via DDEV)

No third-party DDEV addons are required. Smoke handles Playwright and Chromium installation directly during setup.

---

## Install

```bash
ddev composer require drupal/smoke
ddev drush en smoke -y
```

---

## Setup

After enabling the module, run the host setup script from your **project root** (where `.ddev/` lives):

```bash
bash web/modules/contrib/smoke/scripts/host-setup.sh
```

Or run setup entirely inside the container:

```bash
ddev drush smoke:setup
```

### What setup does

1. Verifies DDEV is running and Node.js is available
2. Installs npm dependencies for the Playwright test suites
3. Downloads the **Chromium** browser (~180 MiB one-time download)
4. Tries to install Chromium’s system dependencies (e.g. `libnss3`, `libatk1.0-0`) via `npx playwright install-deps chromium` in non-interactive mode. If that step fails (e.g. no sudo, or your image doesn’t allow it), the script continues and prints the manual command to run.
5. **When the Webform module is enabled:** prompts for the **webform machine name** to use for smoke tests (e.g. `contact_us`, `smoke_test`). If that webform doesn’t exist, it is created with standard Name, Email, and Message fields. The legacy `smoke_test` webform is removed when you choose a different ID so the test uses your custom form.
6. Generates the test configuration — scans your site for installed modules, webforms, commerce stores, search pages, etc.
7. Creates a `smoke_bot` test user and role for authentication tests
8. Verifies all test suites are wired up
9. Installs a DDEV post-start hook (`config.smoke.yaml`) that auto-regenerates config on `ddev start`

If the script appears to hang, it has been updated so the system-deps step no longer waits for input. If you see a warning that system deps could not be installed, run inside the container: `ddev exec "sudo npx playwright install-deps chromium"`. When running with `--silent` (e.g. from the DDEV post-start hook), the webform prompt is skipped and the existing setting is kept.

### What gets installed where

| What | Where | Size |
|------|-------|------|
| npm packages | `web/modules/contrib/smoke/playwright/node_modules/` | ~50 MiB |
| Chromium browser | `~/.cache/ms-playwright/chromium-*` (inside container) | ~180 MiB |
| System libraries | Container OS packages (libnspr4, libnss3, etc.) | ~20 MiB |
| Test config | `web/modules/contrib/smoke/playwright/.smoke-config.json` | <1 KB |
| DDEV hook | `.ddev/config.smoke.yaml` | <1 KB |

The Chromium browser is cached per-user inside the DDEV container. If multiple projects use Smoke, they share the same browser cache. The cache persists across `ddev restart` but is removed on `ddev delete`.

### Re-running setup

If you install new modules (e.g. add Webform or Commerce) and want Smoke to detect them:

```bash
ddev drush smoke:setup
```

This regenerates the test config. Browsers are only downloaded on first run.

---

## Running Tests

### Show status and detected suites

```bash
ddev drush smoke
```

### Run all tests

```bash
ddev drush smoke --run
```

Tests run sequentially with a live progress bar showing which suite is executing:

```
  Smoke Tests — My Site
  https://my-site.ddev.site
  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

  Core Pages        ✓ 8 passed  3.7s
  Authentication    ✓ 5 passed  2.6s
  ▸ Webform           ━━━━━━━▸────────────  3/8 suites  17s
```

### Run a specific suite

```bash
ddev drush smoke:suite core_pages
ddev drush smoke:suite auth
ddev drush smoke:suite webform
ddev drush smoke:suite commerce
ddev drush smoke:suite search
ddev drush smoke:suite health
ddev drush smoke:suite sitemap
ddev drush smoke:suite content
ddev drush smoke:suite accessibility
```

### Auto-fix common failures

```bash
ddev drush smoke:fix              # Analyze last results and fix what it can
ddev drush smoke:fix --sitemap    # Regenerate the XML sitemap
ddev drush smoke:fix --all        # Fix all known issues
```

### Test a remote site (post-deploy)

After deploying to Pantheon (or any remote host), run the same tests against the live URL:

```bash
ddev drush smoke --run --target=https://test-mysite.pantheonsite.io
ddev drush smoke --run --target=https://live-mysite.pantheonsite.io
```

Or test a single suite:

```bash
ddev drush smoke:suite core_pages --target=https://test-mysite.pantheonsite.io
```

When using `--target`, tests run from your local DDEV Playwright install against the remote URL.

#### What runs on remote vs. what skips

| Behaviour | Suites / Tests | Why |
|-----------|---------------|-----|
| **Runs normally** | Core Pages (all 8), Commerce, Search, Accessibility, Health (CSS/JS assets, login page check) | These are anonymous — no login required |
| **Auto-skips** | Auth (invalid login, smoke_bot login), Health (admin status, cron, dblog), Content (create/delete node) | These need `smoke_bot` which only exists locally |
| **Tries first, skips on 404** | Webform (configured form, default smoke_test) | The default form is auto-created locally; deploy the webform config to run on remote too |
| **Skips if module missing** | Sitemap | Only runs when `simple_sitemap` or `xmlsitemap` is installed |

#### Making webform tests work on remote

The configured webform (default `smoke_test`) is auto-created locally when set to `smoke_test`; it won’t exist on Pantheon unless you deploy it. The test tries to load the form and skips gracefully on 404.

To make webform tests run on Pantheon too:

1. Export config locally: `ddev drush config:export -y`
2. Commit the exported webform config (e.g. `webform.webform.smoke_test.yml` or your configured `webform.webform.{id}.yml`) in `config/sync`
3. Deploy to Pantheon and import config: `drush config:import -y`

Once that webform exists on the remote, webform tests will start passing there.

### List detected suites

```bash
ddev drush smoke:list
```

Shows which suites were detected, whether they're enabled, and their last status.

---

## Admin Dashboard

### Results

Visit **Reports > Smoke Tests** (`/admin/reports/smoke`) to:

- See a summary of the last test run (passed / failed / skipped)
- View per-suite results with individual test names and durations
- Click **Run All Tests** or run individual suites from the UI
- See failure details and error messages inline
- **Regenerate sitemap** directly from the Sitemap suite card

### Settings

Visit **Configuration > Development > Smoke** (`/admin/config/development/smoke`) to:

- Enable or disable individual test suites
- Set the **test webform ID** (default `smoke_test`) so the Webform suite tests your agency or company form — see [Configuration](#configuration)
- Add custom URLs to test (see [Custom URLs](#custom-urls))
- Adjust the per-test timeout

Access requires the `administer smoke tests` permission.

---

## Drush Commands

| Command | Alias | Description |
|---------|-------|-------------|
| `drush smoke:run` | `drush smoke` | Show status, or run all tests with `--run` |
| `drush smoke:run --run` | `drush smoke --run` | Run all enabled test suites with progress bar |
| `drush smoke:run --run --target=URL` | — | Run tests against a remote site |
| `drush smoke:suite {id}` | — | Run a single suite (e.g. `webform`, `auth`, `core_pages`) |
| `drush smoke:suite {id} --target=URL` | — | Run one suite against a remote site |
| `drush smoke:list` | — | Show detected suites, enabled status, and last results |
| `drush smoke:setup` | — | Install dependencies, browsers, generate config, verify test user |
| `drush smoke:fix` | `sfix` | Analyze last results and auto-fix common issues |
| `drush smoke:fix --sitemap` | — | Regenerate the XML sitemap |
| `drush smoke:fix --all` | — | Fix all detected issues |

---

## What It Tests

| Suite | Triggers When | What's Checked |
|-------|---------------|----------------|
| **Core Pages** | Always | Homepage returns 200, login page returns 200, no PHP fatal errors, no JS console errors, no broken images, no mixed content, 404 page renders (not WSOD), 403 page renders (not WSOD) |
| **Authentication** | Always | Login form renders, invalid credentials show error, `smoke_bot` can log in and reach the profile page, password reset page exists |
| **Webform** | `webform` module enabled | Submits the configured webform (default `smoke_test`, or set in settings), fills all fields, submits, and confirms success |
| **Commerce** | `commerce` module enabled | Product catalog pages load, cart endpoint responds, checkout endpoint responds, store exists |
| **Search** | `search_api` or `search` module enabled | Search page loads, search form is present on the page |
| **Health** | Always | Admin status report has no errors, cron has run recently, CSS/JS assets load without 404s, no PHP errors in dblog, login page cache headers correct |
| **Sitemap** | `simple_sitemap` or `xmlsitemap` module | `/sitemap.xml` returns 200, contains valid XML with URLs, no PHP errors |
| **Content** | `page` content type exists | Creates a test Basic Page, verifies it renders, deletes it — full content pipeline check |
| **Accessibility** | Always | axe-core WCAG 2.1 AA scan on homepage and login page, fails on critical/serious violations, best-practice scan (informational) |

### Auto-detection

Suites are **automatically detected** based on installed modules. If Commerce isn't installed, the Commerce suite is skipped entirely — no errors, no configuration needed.

### Webform suite and configurable form

When the Webform module is detected, Smoke runs tests against the webform set in **Test webform ID** (Configuration > Development > Smoke). Default is `smoke_test`: if that form doesn’t exist, Smoke creates it (Name, Email, Message). You can set any other webform machine name (e.g. `contact_us`) to test your own form; that form must already exist and be open.

---

## Configuration

### Config file

Smoke stores its settings in `smoke.settings`:

```yaml
suites:
  core_pages: true
  auth: true
  webform: true
  commerce: true
  search: true
  health: true
  sitemap: true
  content: true
  accessibility: true
webform_id: smoke_test
custom_urls: []
timeout: 10000
```

Edit via the admin UI at `/admin/config/development/smoke` or export/import with Drupal's config system.

### Test webform ID

The **Webform** suite submits a single webform and checks for success. By default it uses the `smoke_test` webform (auto-created if missing). You can point it at any existing webform so the test is tailored to your agency or company:

- **`smoke_test`** (default) — Smoke creates this form if it doesn’t exist (Name, Email, Message).
- **Any other machine name** (e.g. `contact_us`, `quote_request`) — Smoke uses that webform if it exists and is open; no auto-creation.

Set **Test webform ID** in Configuration > Development > Smoke, or in config as `webform_id: contact_us`.

### Test timeout

The `timeout` value (in milliseconds) controls how long each individual test waits before failing. Default is `10000` (10 seconds). For slow environments, increase to `20000` or `30000`.

### Generated test config

When you run `drush smoke:setup` or `drush smoke`, Smoke generates a `.smoke-config.json` file inside its `playwright/` directory. This JSON file contains:

- The site's base URL (from `DDEV_PRIMARY_URL`)
- Site title
- All detected suites and their metadata (webform fields, commerce flags, search paths, etc.)
- Auth credentials for the `smoke_bot` test user
- Timeout settings

Playwright reads this file at runtime. **You don't edit this file directly** — it's regenerated from Drupal's state on each setup or test run.

---

## Custom URLs

Add extra pages to test via the admin settings form or directly in config:

```yaml
# In config/sync or via /admin/config/development/smoke
custom_urls:
  - /about
  - /pricing
  - /contact
  - /products
```

Each custom URL is checked for:
- HTTP 200 response
- No PHP fatal errors in the response body

---

## Adding Custom Tests

Smoke ships with built-in suites, but you can easily add your own. Tests are standard [Playwright spec files](https://playwright.dev/docs/writing-tests) in TypeScript.

### Quick start: add a spec file

Create a new `.spec.ts` file in `playwright/suites/` inside the module:

```bash
# Find where the module lives
ddev drush eval "echo DRUPAL_ROOT . '/' . \Drupal::service('extension.list.module')->getPath('smoke');"

# Create your test file
vim web/modules/contrib/smoke/playwright/suites/my-pages.spec.ts
```

### Example: test specific pages

```typescript
import { test, expect } from '@playwright/test';
import { assertHealthyPage } from '../src/helpers';

test.describe('My Pages', () => {

  test('/about returns 200', async ({ page }) => {
    await assertHealthyPage(page, '/about');
  });

  test('/pricing returns 200', async ({ page }) => {
    await assertHealthyPage(page, '/pricing');
  });

  test('/contact has a form', async ({ page }) => {
    await page.goto('/contact');
    await expect(page.locator('form')).toBeVisible();
  });

});
```

### Example: test an authenticated page

```typescript
import { test, expect } from '@playwright/test';
import { loadConfig } from '../src/config-reader';
import { loginAsSmokeBot } from '../src/helpers';

const config = loadConfig();
const auth = config.suites.auth;

test.describe('Member Pages', () => {

  test('dashboard requires login', async ({ page }) => {
    const response = await page.goto('/dashboard');
    // Should redirect to login or return 403
    expect([200, 403]).toContain(response?.status());
  });

  test('smoke_bot can access dashboard', async ({ page }) => {
    await loginAsSmokeBot(
      page,
      (auth as any).testUser,
      (auth as any).testPassword,
    );
    const response = await page.goto('/dashboard');
    expect(response?.status()).toBe(200);
  });

});
```

### Example: conditional test (only if a module is enabled)

```typescript
import { test, expect } from '@playwright/test';
import { isSuiteEnabled } from '../src/config-reader';

// Re-use an existing suite flag, or check your own way
const hasWebform = isSuiteEnabled('webform');

test.describe('Contact Flow', () => {
  test.skip(!hasWebform, 'Webform not installed.');

  test('contact page has phone field', async ({ page }) => {
    await page.goto('/webform/contact_us');
    await expect(page.getByLabel('Phone Number')).toBeVisible();
  });
});
```

### Available helpers

These are imported from `../src/helpers`:

| Helper | What it does |
|--------|-------------|
| `assertHealthyPage(page, path)` | Navigates to the path, asserts HTTP 200, checks for PHP fatal errors |
| `assertNoJsErrors(page, path)` | Navigates and captures any JavaScript console errors |
| `loginAsSmokeBot(page, user, pass)` | Logs into Drupal with the smoke_bot test user |
| `fillField(page, label, type)` | Fills a form field by label, using smart defaults based on type (email, tel, textarea, etc.) |

### Available config readers

These are imported from `../src/config-reader`:

| Function | What it does |
|----------|-------------|
| `loadConfig()` | Returns the full config object (base URL, suites, timeout, etc.) |
| `isSuiteEnabled(suiteId)` | Returns `true` if a suite is detected and enabled |
| `getSuiteConfig(suiteId)` | Returns the config object for a specific suite |

### Naming conventions

- File: `playwright/suites/my-thing.spec.ts` (use dashes, not underscores)
- Describe block: `test.describe('My Thing', () => { ... })`
- Tests show up automatically — no registration needed

### After adding tests

Reinstall npm dependencies and run:

```bash
ddev drush smoke:setup    # Reinstalls dependencies, regenerates config
ddev drush smoke --run    # Run all tests including your new ones
```

### Tips

- Keep tests **fast**. Each test should be under 5 seconds. This is smoke testing, not QA.
- Use `assertHealthyPage()` as your go-to — it checks HTTP 200 + no PHP fatals in one call.
- Use `test.skip()` to conditionally skip tests that depend on specific modules or config.
- Don't use `waitForLoadState('networkidle')` — it hangs on sites with analytics or long-polling.
- `loadConfig().baseUrl` gives you the full site URL if you need it.

---

## After Module Updates

This is the primary use case Smoke was built for. After running `composer update` on contrib modules, run smoke tests to verify nothing broke.

### Manual workflow

```bash
ddev composer update
ddev drush cr
ddev drush smoke --run
```

If you've added or removed modules (e.g. added `webform`), regenerate the config first:

```bash
ddev drush smoke:setup
ddev drush smoke --run
```

### Automatic: Composer scripts

Add Composer scripts to your **project root** `composer.json` so smoke tests run automatically after every `composer install` or `composer update`. If any test fails, the command exits non-zero — broken updates are caught immediately.

```json
{
  "scripts": {
    "post-update-cmd": [
      "./vendor/bin/drush cr",
      "./vendor/bin/drush smoke --run"
    ],
    "post-install-cmd": [
      "./vendor/bin/drush cr",
      "./vendor/bin/drush smoke --run"
    ]
  }
}
```

If your `composer.json` already has `post-update-cmd` or `post-install-cmd` entries, append the two lines to the existing arrays instead of replacing them.

For DDEV users running `ddev composer update`, the scripts execute inside the container where Drush is available at `./vendor/bin/drush`. Running `smoke:setup` will offer to add these scripts automatically.

### Enforce before push: git pre-push hook

To prevent pushing when smoke tests fail, add a git pre-push hook. Create `.git/hooks/pre-push` in your project root:

```bash
#!/usr/bin/env bash
# Smoke pre-push hook — tests must pass before pushing.
echo "Running smoke tests..."
ddev drush smoke --run
exit $?
```

Then make it executable:

```bash
chmod +x .git/hooks/pre-push
```

Now `git push` will run the full smoke suite first. If any test fails, the push is blocked.

> **Tip:** To share this hook with your team, store it in a tracked directory (e.g. `scripts/hooks/pre-push`) and have each developer symlink it:
> `ln -sf ../../scripts/hooks/pre-push .git/hooks/pre-push`

---

## Architecture

```
smoke/
├── src/
│   ├── Controller/
│   │   └── DashboardController.php   # Admin UI — results, run tests, sitemap regen
│   ├── Form/
│   │   └── SettingsForm.php           # Config form — suites, URLs, timeout
│   ├── Service/
│   │   ├── ModuleDetector.php         # Scans site for testable features
│   │   ├── ConfigGenerator.php        # Writes JSON bridge file for Playwright
│   │   └── TestRunner.php             # Spawns Playwright, parses results
│   └── Commands/
│       ├── SmokeRunCommand.php        # drush smoke:run (with progress bar)
│       ├── SmokeSuiteCommand.php      # drush smoke:suite {id}
│       ├── SmokeListCommand.php       # drush smoke:list
│       ├── SmokeSetupCommand.php      # drush smoke:setup (installs browsers)
│       └── SmokeFixCommand.php        # drush smoke:fix (auto-fix failures)
├── playwright/
│   ├── playwright.config.ts           # Playwright config — reads .smoke-config.json
│   ├── src/
│   │   ├── config-reader.ts           # Loads Drupal-generated JSON config
│   │   └── helpers.ts                 # Shared helpers (login, assertions)
│   └── suites/
│       ├── core-pages.spec.ts         # Homepage, login, critical pages
│       ├── auth.spec.ts               # Authentication flow
│       ├── webform.spec.ts            # Webform render, submit, validation
│       ├── commerce.spec.ts           # Commerce catalog, cart, checkout
│       ├── search.spec.ts             # Search page and form
│       ├── health.spec.ts             # Admin status, cron, assets, dblog
│       ├── sitemap.spec.ts            # XML sitemap validation
│       ├── content.spec.ts            # Content creation round-trip
│       └── accessibility.spec.ts      # axe-core WCAG 2.1 AA scan
├── scripts/
│   └── host-setup.sh                  # One-command host-side setup
├── templates/
│   └── smoke-dashboard.html.twig      # Admin dashboard template
├── config/
│   ├── install/smoke.settings.yml     # Default settings
│   └── schema/smoke.schema.yml        # Config schema
└── css/
    └── dashboard.css                  # Dashboard styles
```

### Data flow

```
Drupal (PHP)                          Node (TypeScript)
┌──────────────┐                      ┌──────────────────┐
│ ModuleDetector│──detects modules──>  │                  │
│              │                      │ .smoke-config.json│
│ConfigGenerator│──writes config───>  │                  │
└──────────────┘                      └────────┬─────────┘
                                               │
                                      ┌────────▼─────────┐
                                      │   Playwright      │
                                      │   (Chromium)      │
                                      │   runs .spec.ts   │
                                      └────────┬─────────┘
                                               │
┌──────────────┐                      ┌────────▼─────────┐
│  TestRunner   │<──reads results───  │   results.json    │
│              │                      │                  │
│  Dashboard /  │                      └──────────────────┘
│  Drush CLI    │
└──────────────┘
```

---

## Troubleshooting

### Tests seem to hang

Tests have a 10-second timeout per test. If many tests fail (e.g. wrong URLs), each one waits for the timeout before moving on. A full suite of 29 tests could take up to ~5 minutes if everything fails.

Run a single suite to diagnose: `ddev drush smoke:suite core_pages`

### "Playwright is not set up"

Run setup: `ddev drush smoke:setup`

If that fails, try the host script: `bash web/modules/contrib/smoke/scripts/host-setup.sh`

### browserType.launch errors / "Host system is missing dependencies"

Chromium needs system libraries (e.g. `libnss3`, `libatk1.0-0`) in the container. Install them with:

```bash
ddev exec "sudo npx playwright install-deps chromium"
```

If `sudo` prompts for a password or the host-setup script skipped this step, run the command above manually. On some Linux hosts you may need:

```bash
ddev exec "env DEBIAN_FRONTEND=noninteractive sudo npx playwright install-deps chromium"
```

Re-running `bash web/modules/contrib/smoke/scripts/host-setup.sh` or `ddev drush smoke:setup` will also attempt to install these deps (non-interactively); if that fails, use the manual command above.

### Sitemap tests fail with "contains at least one URL"

The sitemap may be empty or stale. Regenerate it:

```bash
ddev drush smoke:fix --sitemap
```

### Webform tests fail with 404

Make sure webforms are accessible. Smoke uses the `/webform/{id}` path (Drupal's canonical route). If your forms use custom URL aliases, ensure the canonical path still works.

### Config is stale after installing new modules

Regenerate: `ddev drush smoke:setup`

### smoke_bot can't log in

The setup creates a `smoke_bot` user with a random password stored in Drupal state. If the user was deleted, re-run: `ddev drush smoke:setup`

---

## Uninstall & Cleanup

### Quick uninstall (module only)

This removes the Drupal module, the `smoke_bot` user, the role, and all stored state:

```bash
ddev drush pmu smoke -y
ddev composer remove drupal/smoke
```

### Full cleanup (remove everything Smoke installed)

If you want to completely remove all traces of Smoke and Playwright from your system:

#### 1. Uninstall the Drupal module

```bash
ddev drush pmu smoke -y
ddev composer remove drupal/smoke
```

This automatically:
- Deletes the `smoke_bot` user account
- Deletes the `Smoke Test Bot` role
- Removes all Smoke state data (test results, bot password, timestamps)
- Removes the `smoke_test` webform (if it was auto-created)

#### 2. Remove the DDEV hook file

Smoke installs a post-start hook that auto-regenerates config:

```bash
rm -f .ddev/config.smoke.yaml
```

#### 3. Remove Playwright browser cache (inside DDEV container)

The Chromium browser binary is cached inside the container at `~/.cache/ms-playwright/`:

```bash
ddev exec "rm -rf ~/.cache/ms-playwright"
```

This frees ~180 MiB. Note: if other projects also use Playwright inside DDEV, they share this cache — removing it will require them to re-download.

#### 4. Remove the test/playwright stub (if created by host-setup.sh)

The host setup script may have created a stub directory for the DDEV Playwright addon:

```bash
rm -rf test/playwright
```

#### 5. Remove any third-party DDEV Playwright addon (if previously installed)

If you previously installed `codingsasi/ddev-playwright` or `Lullabot/ddev-playwright`, and no other project needs it:

```bash
# Check if installed
ls .ddev/config.playwright.yaml .ddev/config.playwright.yml 2>/dev/null

# Remove the addon
ddev add-on remove codingsasi/ddev-playwright   # or Lullabot/ddev-playwright
ddev restart
```

#### 6. Remove Playwright from the host system (if installed outside DDEV)

If you ever ran `npx playwright install` on your **host machine** (outside DDEV), Playwright stores browsers in your user home directory:

| OS | Browser cache location |
|----|----------------------|
| **macOS** | `~/Library/Caches/ms-playwright/` |
| **Linux** | `~/.cache/ms-playwright/` |
| **Windows** | `%USERPROFILE%\AppData\Local\ms-playwright\` |

To remove:

```bash
# macOS
rm -rf ~/Library/Caches/ms-playwright

# Linux
rm -rf ~/.cache/ms-playwright
```

This frees 200-500 MiB depending on how many browser types were installed.

#### 7. Verify cleanup

After all steps, confirm nothing remains:

```bash
# Module should be gone
ddev drush pm:list --filter=smoke
# Should return empty table

# No DDEV hook
ls .ddev/config.smoke.yaml 2>/dev/null
# Should say "No such file"

# No browser cache in container
ddev exec "ls ~/.cache/ms-playwright 2>/dev/null"
# Should say "No such file or directory"

# No test stub
ls test/playwright 2>/dev/null
# Should say "No such file"
```

### What Smoke does NOT touch

For reference, these are things Smoke **never** modifies:

- Your site's content, configuration, or database (except the `smoke_bot` user/role, which is removed on uninstall)
- Your `.ddev/config.yaml` or other DDEV config files (it only creates `.ddev/config.smoke.yaml`)
- Your `composer.json` beyond the `drupal/smoke` package entry
- Any files outside the module directory and `.ddev/config.smoke.yaml`
- Global npm packages or global Playwright installations

---

## Maintainers

- [thronedigital](https://www.drupal.org/u/thronedigital)

---

## License

GPL-2.0-or-later
