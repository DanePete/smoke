# Smoke Module - Deep Dive Analysis Report

**Date:** February 17, 2026  
**Module Version:** 1.1.4  
**Analyst:** GitHub Copilot (Claude Opus 4.5)

---

## Table of Contents

1. [Executive Summary](#executive-summary)
2. [Module Overview](#module-overview)
3. [Architecture Analysis](#architecture-analysis)
4. [Current Strengths](#current-strengths)
5. [Areas for Improvement](#areas-for-improvement)
6. [Recommended New Features](#recommended-new-features)
7. [Comparison with Industry Standards](#comparison-with-industry-standards)
8. [Implementation Priority Matrix](#implementation-priority-matrix)
9. [Technical Debt & Code Quality](#technical-debt--code-quality)
10. [Security Considerations](#security-considerations)

---

## Executive Summary

The **Smoke** module is a well-architected automated smoke testing solution for Drupal 10/11 that uses Playwright for browser-based testing. It auto-detects installed modules, provides 9 test suites, offers both CLI (Drush) and UI (Dashboard) interfaces, and integrates cleanly with DDEV.

### Overall Assessment: **B+ (Good, with room for significant enhancements)**

| Category | Score | Notes |
|----------|-------|-------|
| Architecture | 9/10 | Clean separation of concerns, proper DI |
| Test Coverage | 7/10 | Good breadth, could be deeper |
| Documentation | 9/10 | Excellent README and inline docs |
| Error Handling | 6/10 | Basic handling, needs enhancement |
| Extensibility | 6/10 | Limited hooks/plugins for customization |
| Performance | 7/10 | Sequential test runs, no parallelization |
| Security | 7/10 | Uses smoke_bot, password in state |

---

## Module Overview

### Purpose
Automated end-to-end smoke testing to verify Drupal sites work after deployments, updates, or configuration changes.

### Technology Stack
- **Backend:** PHP 8.1+ (Drupal services, Drush commands)
- **Test Engine:** Playwright (TypeScript)
- **Browser:** Chromium (headless)
- **CI/CD Integration:** DDEV hooks, CLI-friendly

### Test Suites (9 total)
| Suite | Purpose | Auto-Detected |
|-------|---------|---------------|
| Core Pages | Homepage, login, 404, 403, JS/broken images | Always |
| Authentication | Login flow, password reset | Always |
| Webform | Form submission | When `webform` module exists |
| Commerce | Products, cart, checkout | When `commerce` module exists |
| Search | Search form functionality | When `search_api` or `search` exists |
| Health | Admin status, cron, assets | Always |
| Sitemap | XML sitemap validation | When `simple_sitemap` or `xmlsitemap` exists |
| Content | Create/view/delete node | Always |
| Accessibility | WCAG 2.1 AA via axe-core | Always (disabled by default) |

---

## Architecture Analysis

### Service Layer
```
┌─────────────────────────────────────────────────────────────┐
│                     Drush Commands                          │
│  (SmokeRunCommand, SmokeSetupCommand, SmokeFixCommand, etc)│
└───────────────────────────┬─────────────────────────────────┘
                            │
┌───────────────────────────▼─────────────────────────────────┐
│                       Services                               │
│  ┌─────────────────┐  ┌──────────────────┐  ┌─────────────┐│
│  │   TestRunner    │  │  ConfigGenerator │  │ModuleDetect ││
│  │                 │  │                  │  │             ││
│  │ • run()         │  │ • generate()     │  │ • detect()  ││
│  │ • parseResults()│  │ • writeConfig()  │  │ • labels()  ││
│  │ • getLastRun()  │  │ • resolveBase()  │  │ • icons()   ││
│  └─────────────────┘  └──────────────────┘  └─────────────┘│
└───────────────────────────┬─────────────────────────────────┘
                            │
┌───────────────────────────▼─────────────────────────────────┐
│               Playwright Test Suites                         │
│  playwright/suites/*.spec.ts                                 │
│  ┌─────────┐ ┌──────┐ ┌────────┐ ┌──────────┐ ┌──────────┐ │
│  │core-page│ │ auth │ │webform │ │ commerce │ │ search   │ │
│  └─────────┘ └──────┘ └────────┘ └──────────┘ └──────────┘ │
│  ┌─────────┐ ┌──────────┐ ┌─────────┐ ┌──────────────────┐ │
│  │ health  │ │ sitemap  │ │ content │ │   accessibility  │ │
│  └─────────┘ └──────────┘ └─────────┘ └──────────────────┘ │
└─────────────────────────────────────────────────────────────┘
```

### Data Flow
```
1. drush smoke --run
      │
      ▼
2. ConfigGenerator.writeConfig()
      │ (writes .smoke-config.json)
      ▼
3. TestRunner.run()
      │ (spawns: npx playwright test)
      ▼
4. Playwright reads .smoke-config.json
      │
      ▼
5. Executes suites/*.spec.ts
      │
      ▼
6. Outputs results.json
      │
      ▼
7. TestRunner.parseResults()
      │
      ▼
8. Stores in Drupal state
      │
      ▼
9. Dashboard/CLI displays results
```

---

## Current Strengths

### 1. **Zero-Config Auto-Detection**
The `ModuleDetector` service elegantly introspects the Drupal installation to enable relevant test suites automatically.

### 2. **Clean Drupal Integration**
- Proper dependency injection throughout
- Uses Drupal config API correctly
- State API for non-exportable data (passwords)
- Config schema properly defined

### 3. **Dual Interface (CLI + UI)**
- Full-featured Drush commands with progress bars
- Clean admin dashboard with real-time results

### 4. **DDEV-First Design**
- Host setup script handles all prerequisites
- DDEV hooks for auto-regeneration
- Self-contained (no external DDEV addons required)

### 5. **Good Documentation**
- Comprehensive README with installation, usage, architecture
- Well-commented code
- CHANGELOG maintained

### 6. **Self-Healing Features**
- `smoke:fix` command auto-repairs common issues
- Auto-setup on first `drush smoke` run

---

## Areas for Improvement

### 1. **Error Handling & Resilience**

**Current Issue:** Minimal error context when Playwright fails.

```php
// TestRunner.php - Line 75-88
if ($process->getExitCode() !== 0 && $resultsFileContent === '') {
    $err = $process->getErrorOutput();
    if ($err !== '') {
        $results = $this->parseResults('');
        $results['error'] = 'Playwright failed. ' . trim($err);
        // ^ Generic error, hard to diagnose
```

**Recommendation:**
- Add structured error codes with actionable messages
- Implement retry logic for flaky tests
- Add debug mode with verbose Playwright output

### 2. **Test Parallelization**

**Current Issue:** Tests run sequentially with `workers: 1` in config.

```typescript
// playwright.config.ts - Line 21
workers: 1,
```

**Recommendation:**
- Enable parallel execution for independent suites
- Add `--parallel` flag to CLI
- Separate suites that modify state from read-only ones

### 3. **No Test Filtering/Tags**

**Current Issue:** Cannot run subsets of tests within a suite.

**Recommendation:**
- Add test tagging (`@critical`, `@quick`, `@slow`)
- Support `--grep` pattern filtering
- Add `--quick` flag for fastest essential checks only

### 4. **Limited Extensibility**

**Current Issue:** No way for other modules to add test suites.

**Recommendation:**
```php
// Add plugin system for custom suites
interface SmokeSuitePluginInterface {
    public function getId(): string;
    public function detect(): bool;
    public function getSuiteConfig(): array;
}
```

### 5. **No Visual Regression Testing**

**Current Issue:** Only functional checks, no visual comparisons.

**Recommendation:**
- Integrate Playwright's screenshot comparison
- Add baseline image management
- Support for responsive viewport testing

### 6. **Missing Performance Metrics**

**Current Issue:** No performance assertions or monitoring.

**Recommendation:**
- Integrate with Drupal's Gander/OpenTelemetry framework
- Track TTFB, LCP, FCP per page
- Add performance budgets (fail if TTFB > 500ms)

### 7. **Test Report Export**

**Current Issue:** Results only in Drupal state/dashboard.

**Recommendation:**
- JUnit XML export for CI integration
- HTML report generation
- JSON export for external tools
- Slack/email notifications on failure

### 8. **No API/Endpoint Testing**

**Current Issue:** Only UI-based tests.

**Recommendation:**
- Add JSON:API endpoint validation
- REST resource smoke tests
- GraphQL query testing (when graphql module detected)

---

## Recommended New Features

### Priority 1: High Impact, Low Effort

#### 1.1 **JUnit XML Export for CI**
```php
// Add to smoke:run command
#[CLI\Option(name: 'junit', description: 'Output JUnit XML for CI.')]
```

**Implementation:** Transform `results.json` to JUnit format.

#### 1.2 **Quick Mode**
```bash
ddev drush smoke --run --quick
# Only: homepage 200, login works, no fatal errors
```

#### 1.3 **Test Retry with Backoff**
```typescript
// playwright.config.ts
retries: process.env.CI ? 2 : 0,
```

#### 1.4 **Email/Slack Notifications**
```php
// Add notification service
if ($results['failed'] > 0) {
    $this->notifier->sendAlert('smoke_tests_failed', $results);
}
```

### Priority 2: High Impact, Medium Effort

#### 2.1 **Visual Regression Suite**
```typescript
// playwright/suites/visual.spec.ts
import { test, expect } from '@playwright/test';

test('homepage visual regression', async ({ page }) => {
    await page.goto('/');
    await expect(page).toHaveScreenshot('homepage.png', {
        maxDiffPixels: 100,
    });
});
```

**Storage:** Baseline images in `playwright/baselines/`

#### 2.2 **Performance Testing Suite**
```typescript
// playwright/suites/performance.spec.ts
test('homepage loads under performance budget', async ({ page }) => {
    const metrics = await page.evaluate(() => ({
        ttfb: performance.timing.responseStart - performance.timing.requestStart,
        fcp: performance.getEntriesByName('first-contentful-paint')[0]?.startTime,
    }));
    
    expect(metrics.ttfb).toBeLessThan(500);
    expect(metrics.fcp).toBeLessThan(2000);
});
```

#### 2.3 **Suite Plugin System**
```php
// smoke.module
function smoke_smoke_suite_info_alter(&$suites) {
    // Allow other modules to add/modify suites
}

// Hook implementation in custom module
function mymodule_smoke_suite_info() {
    return [
        'my_custom_suite' => [
            'label' => 'My Custom Tests',
            'spec_file' => 'custom-tests.spec.ts',
            'detect_callback' => [MyDetector::class, 'detect'],
        ],
    ];
}
```

#### 2.4 **API Testing Suite**
```typescript
// playwright/suites/api.spec.ts
test.describe('JSON:API', () => {
    test('node collection responds', async ({ request }) => {
        const response = await request.get('/jsonapi/node/article');
        expect(response.status()).toBe(200);
        const json = await response.json();
        expect(json.data).toBeDefined();
    });
});
```

### Priority 3: Medium Impact, Higher Effort

#### 3.1 **Multi-Environment Testing**
```yaml
# smoke.settings.yml
environments:
  local:
    base_url: https://mysite.ddev.site
  staging:
    base_url: https://staging.example.com
    auth_via: terminus
  production:
    base_url: https://example.com
    readonly: true  # Skip content creation
```

#### 3.2 **Test Scheduling**
```php
// Implement hook_cron() for scheduled runs
function smoke_cron() {
    $settings = \Drupal::config('smoke.settings');
    if ($settings->get('scheduled_run.enabled')) {
        // Queue test run
    }
}
```

#### 3.3 **Baseline/Snapshot Management UI**
- Dashboard page to view/approve visual baselines
- History of visual changes
- Side-by-side diff viewer

#### 3.4 **Multi-Browser Support**
```typescript
// playwright.config.ts
projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    { name: 'firefox', use: { ...devices['Desktop Firefox'] } },
    { name: 'mobile', use: { ...devices['iPhone 13'] } },
],
```

---

## Comparison with Industry Standards

### vs. Lullabot's playwright-drupal

| Feature | Smoke | playwright-drupal |
|---------|-------|-------------------|
| Auto-detection | ✅ Excellent | ❌ Manual config |
| Isolated test DBs | ❌ No | ✅ Yes (sqlite) |
| Visual diffs | ❌ No | ✅ Built-in |
| Dashboard UI | ✅ Yes | ❌ No |
| Learning curve | ✅ Low | ⚠️ Medium |
| Customization | ⚠️ Limited | ✅ Extensive |

**Recommendation:** Consider adopting playwright-drupal patterns for:
- Visual comparison framework
- Isolated database per test
- `takeAccessibleScreenshot()` pattern

### vs. Cypress for Drupal

| Feature | Smoke | Cypress |
|---------|-------|---------|
| Speed | ⚠️ Slower | ✅ Faster |
| Drupal integration | ✅ Native | ⚠️ Requires setup |
| Real browser | ✅ Yes | ✅ Yes |
| Time-travel debug | ❌ No | ✅ Yes |

### vs. Drupal Core Gander (Performance)

| Feature | Smoke | Gander |
|---------|-------|--------|
| E2E testing | ✅ Yes | ❌ Performance only |
| DB query counts | ❌ No | ✅ Yes |
| Cache metrics | ❌ No | ✅ Yes |
| OpenTelemetry | ❌ No | ✅ Yes |

**Recommendation:** Integrate Gander-style performance metrics.

---

## Implementation Priority Matrix

```
                    ┌────────────────────────────────────────┐
                    │           HIGH IMPACT                   │
                    │                                        │
  LOW EFFORT        │  • JUnit XML export (CI)               │
                    │  • Quick mode flag                     │
                    │  • Retry logic                         │
                    │  • Slack/email notifications           │
                    ├────────────────────────────────────────┤
                    │                                        │
  MEDIUM EFFORT     │  • Visual regression suite             │
                    │  • Performance metrics                 │
                    │  • API testing suite                   │
                    │  • Suite plugin system                 │
                    ├────────────────────────────────────────┤
                    │           MEDIUM IMPACT                │
  HIGH EFFORT       │  • Multi-browser testing               │
                    │  • Multi-environment configs           │
                    │  • Test scheduling                     │
                    │  • Gander integration                  │
                    └────────────────────────────────────────┘
```

---

## Technical Debt & Code Quality

### Current Issues Found

#### 1. Missing Type Hints in Some Places
```php
// TestRunner.php - Line 142
private function parseResults(string $jsonOutput): array  // Good!

// But some methods return mixed arrays
$results['suites'][$suiteId] = [...];  // Could use value objects
```

#### 2. Large Controller Methods
```php
// DashboardController.php - dashboard() is 538 lines
// Recommendation: Extract to smaller helper methods/services
```

#### 3. Magic Strings
```php
// Multiple places use 'smoke_bot', 'smoke.settings' as strings
// Recommendation: Define constants
```

#### 4. Test Coverage Gaps
- No unit tests for ConfigGenerator
- No unit tests for TestRunner
- Kernel tests only cover install hooks

### Recommended Refactoring

1. **Extract Value Objects:**
```php
class SuiteResult {
    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly int $passed,
        public readonly int $failed,
        public readonly int $duration,
    ) {}
}
```

2. **Add Constants Class:**
```php
final class SmokeConstants {
    public const BOT_USERNAME = 'smoke_bot';
    public const BOT_ROLE = 'smoke_bot';
    public const CONFIG_NAME = 'smoke.settings';
    public const STATE_LAST_RESULTS = 'smoke.last_results';
}
```

3. **Increase Test Coverage:**
- Unit tests for all services
- Functional tests for dashboard interactions
- Integration tests for Drush commands

---

## Security Considerations

### Current Security Posture

| Aspect | Status | Notes |
|--------|--------|-------|
| Password storage | ⚠️ State API | Not in git, but cleartext in DB |
| Bot user permissions | ✅ Minimal | Only needed permissions |
| CSRF protection | ✅ Yes | Dashboard actions use tokens |
| Input validation | ✅ Good | Config values validated |
| Remote testing | ⚠️ Careful | Credentials in env vars |

### Recommendations

1. **Consider encrypting bot password:**
```php
// Use Drupal's key module or PHP sodium
$encrypted = sodium_crypto_secretbox($password, $nonce, $key);
```

2. **Add rate limiting for failed logins:**
```typescript
// auth.spec.ts
// Currently risks triggering flood protection
// Add delay between attempts or use flood-exempt approach
```

3. **Audit trail:**
```php
// Log test runs to watchdog
\Drupal::logger('smoke')->info('Smoke tests completed: @passed passed, @failed failed', [...]);
```

---

## Conclusion & Next Steps

### Immediate Actions (Next Sprint)
1. ✅ Add JUnit XML export for CI integration
2. ✅ Implement `--quick` mode for fast sanity checks
3. ✅ Add retry logic (2 retries on CI)
4. ✅ Improve error messages with actionable hints

### Short-term (1-2 months)
1. Visual regression testing foundation
2. Performance metrics collection
3. Suite plugin system for extensibility
4. API endpoint testing suite

### Long-term (3-6 months)
1. Gander/OpenTelemetry integration
2. Multi-browser and mobile testing
3. Scheduled test runs with reporting
4. Advanced baseline management UI

---

## Appendix: File Reference

| File | Purpose | Lines |
|------|---------|-------|
| `src/Service/TestRunner.php` | Executes Playwright, parses results | 369 |
| `src/Service/ModuleDetector.php` | Auto-detects testable features | 453 |
| `src/Service/ConfigGenerator.php` | Generates JSON bridge config | 131 |
| `src/Controller/DashboardController.php` | Admin dashboard UI | 538 |
| `src/Form/SettingsForm.php` | Module settings form | 166 |
| `src/Commands/SmokeRunCommand.php` | Main CLI entry point | 486 |
| `src/Commands/SmokeSetupCommand.php` | Setup wizard | 576 |
| `playwright/playwright.config.ts` | Playwright configuration | 49 |
| `playwright/suites/*.spec.ts` | 9 test suite files | ~700 total |

---

*This report was generated through comprehensive code analysis, comparison with industry best practices from Playwright documentation, Lullabot's playwright-drupal, and Drupal's official testing guidelines.*
