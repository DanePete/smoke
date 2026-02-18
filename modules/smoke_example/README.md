# Smoke Example

A working example suite for [Smoke](https://www.drupal.org/project/smoke).
Enable this module, then run:

```bash
drush smoke:suite smoke_example
```

Copy this module as a starting point for your own suites.

## Creating your own suite module

### 1. Create the module

```
my_module/
  my_module.info.yml
  smoke.suites.yml
  playwright/
    suites/
      my-module/
        pages.spec.ts
        admin.spec.ts
```

`my_module.info.yml`:

```yaml
name: My Smoke Suite
type: module
core_version_requirement: ^10 || ^11
dependencies:
  - smoke:smoke
```

### 2. Define the suite in smoke.suites.yml

```yaml
my_module:
  label: 'My Module'
  description: 'Tests for my custom module.'
  icon: puzzle
```

The suite ID (`my_module`) maps to the directory name with underscores
replaced by hyphens: `playwright/suites/my-module/`.

### 3. Write spec files

Place `.spec.ts` files in `playwright/suites/my-module/`.

**Imports** — use `../../src/config-reader` and `../../src/helpers`:

```typescript
import { test, expect } from '@playwright/test';
import { isSuiteEnabled } from '../../src/config-reader';
import { assertHealthyPage } from '../../src/helpers';

const enabled = isSuiteEnabled('my_module');

test.describe('My Module', () => {
  test.skip(!enabled, 'My Module suite is disabled.');

  test('homepage loads', async ({ page }) => {
    await assertHealthyPage(page, '/');
  });
});
```

These imports resolve correctly because Smoke's TestRunner **copies your
spec files into its own `playwright/suites/` directory** before running
Playwright, then cleans them up afterward. From that location:

- `../../src/config-reader` → `smoke/playwright/src/config-reader`
- `../../src/helpers` → `smoke/playwright/src/helpers`

This is the same pattern Smoke's own built-in suites use.

### 4. Enable and run

```bash
drush en my_module -y
drush cr
drush smoke:suite my_module
```

## Available helpers

From `../../src/helpers`:

| Function | Description |
|---|---|
| `assertHealthyPage(page, path)` | Asserts HTTP 200 and no PHP fatal errors |
| `assertNoJsErrors(page, path)` | Collects JS console errors |
| `login(page, config?)` | Logs in as smoke_bot |
| `loginAsSmokeBot(page, user, pass)` | Logs in with explicit credentials |
| `fillField(page, title, type)` | Fills a webform field by type |
| `readConfig(startDir?)` | Reads `.smoke-config.json` |

From `../../src/config-reader`:

| Function | Description |
|---|---|
| `loadConfig()` | Loads and caches the Smoke config |
| `isSuiteEnabled(suiteId)` | Whether a suite is enabled |
| `getSuiteConfig(suiteId)` | Full config for a suite |
| `isRemote()` | Whether testing a remote URL |
| `shouldSkipAuth()` | Whether to skip auth-dependent tests |

## Suite as directory

A suite can be a single `.spec.ts` file or a **directory** containing
multiple spec files. Directory suites let you organize tests logically:

```
playwright/suites/my-module/
  pages.spec.ts      # Public page checks
  admin.spec.ts      # Admin pages (requires login)
  api.spec.ts        # API endpoint checks
```

All files in the directory run together as one suite.

## Spec path override

By default, Smoke looks for specs in `playwright/suites/SUITE-ID/` or
`tests/playwright/SUITE-ID/`. You can override this in `smoke.suites.yml`:

```yaml
my_module:
  label: 'My Module'
  spec_path: tests/e2e
```
