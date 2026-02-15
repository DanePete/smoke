<?php

declare(strict_types=1);

namespace Drupal\smoke\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\Component\Utility\Html;
use Drupal\smoke\Service\ModuleDetector;
use Drupal\smoke\Service\TestRunner;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Dashboard controller for Smoke test results.
 */
final class DashboardController extends ControllerBase {

  public function __construct(
    private readonly TestRunner $testRunner,
    private readonly ModuleDetector $moduleDetector,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('smoke.test_runner'),
      $container->get('smoke.module_detector'),
    );
  }

  /**
   * Renders the smoke test dashboard.
   */
  public function dashboard(): array {
    $isSetup = $this->testRunner->isSetup();
    $lastResults = $this->testRunner->getLastResults();
    $lastRun = $this->testRunner->getLastRunTime();
    $detected = $this->moduleDetector->detect();
    $labels = ModuleDetector::suiteLabels();
    $settings = $this->config('smoke.settings');
    $enabledSuites = $settings->get('suites') ?? [];
    $baseUrl = getenv('DDEV_PRIMARY_URL') ?: $this->getRequest()->getSchemeAndHttpHost();

    // Build suite data for the template.
    $suites = [];
    foreach ($detected as $id => $info) {
      $enabled = $enabledSuites[$id] ?? TRUE;
      $resultData = $lastResults['suites'][$id] ?? NULL;

      $suites[$id] = [
        'id' => $id,
        'label' => Html::escape($labels[$id] ?? $info['label'] ?? $id),
        'description' => Html::escape($info['description'] ?? ''),
        'detected' => (bool) ($info['detected'] ?? FALSE),
        'enabled' => $enabled,
        'status' => $resultData ? ($resultData['status'] ?? 'unknown') : 'not_run',
        'passed' => (int) ($resultData['passed'] ?? 0),
        'failed' => (int) ($resultData['failed'] ?? 0),
        'skipped' => (int) ($resultData['skipped'] ?? 0),
        'duration' => (int) ($resultData['duration'] ?? 0),
        'tests' => $resultData['tests'] ?? [],
        'links' => $this->suiteLinks($id, $baseUrl),
        'details' => $this->suiteDetails($id, $info, $baseUrl),
      ];
    }

    // Suites that are not detected get a 'skipped' status.
    foreach ($suites as $id => &$suite) {
      if (!$suite['detected'] || !$suite['enabled']) {
        $suite['status'] = 'skipped';
      }
    }
    unset($suite);

    $summary = $lastResults['summary'] ?? [
      'total' => 0,
      'passed' => 0,
      'failed' => 0,
      'skipped' => 0,
      'duration' => 0,
    ];

    $csrfToken = \Drupal::csrfToken()->get('smoke');

    $build = [
      '#theme' => 'smoke_dashboard',
      '#suites' => $suites,
      '#summary' => $summary,
      '#last_run' => $lastRun,
      '#is_setup' => $isSetup,
      '#csrf_token' => $csrfToken,
      '#base_url' => $baseUrl,
      '#attached' => [
        'library' => ['smoke/dashboard'],
      ],
      '#cache' => ['max-age' => 0],
    ];

    return $build;
  }

  /**
   * Returns useful links for a given suite.
   */
  private function suiteLinks(string $id, string $baseUrl): array {
    $links = [];

    switch ($id) {
      case 'webform':
        $links[] = [
          'label' => 'View form',
          'url' => $baseUrl . '/webform/smoke_test',
        ];
        $links[] = [
          'label' => 'View submissions',
          'url' => $baseUrl . '/admin/structure/webform/manage/smoke_test/results/submissions',
        ];
        break;

      case 'auth':
        $links[] = [
          'label' => 'Login page',
          'url' => $baseUrl . '/user/login',
        ];
        break;

      case 'commerce':
        $links[] = [
          'label' => 'Products',
          'url' => $baseUrl . '/admin/commerce/products',
        ];
        $links[] = [
          'label' => 'Orders',
          'url' => $baseUrl . '/admin/commerce/orders',
        ];
        break;

      case 'search':
        $links[] = [
          'label' => 'Search config',
          'url' => $baseUrl . '/admin/config/search',
        ];
        break;

      case 'core_pages':
        $links[] = [
          'label' => 'Homepage',
          'url' => $baseUrl . '/',
        ];
        break;

      case 'health':
        $links[] = [
          'label' => 'Status report',
          'url' => $baseUrl . '/admin/reports/status',
        ];
        $links[] = [
          'label' => 'Recent log',
          'url' => $baseUrl . '/admin/reports/dblog',
        ];
        break;
    }

    return $links;
  }

  /**
   * Returns detailed technical info for a suite to display on the dashboard.
   *
   * Includes tested paths, selectors, fields, and Playwright spec filenames.
   */
  private function suiteDetails(string $id, array $info, string $baseUrl): array {
    $details = [];
    $details['spec_file'] = str_replace('_', '-', $id) . '.spec.ts';

    switch ($id) {
      case 'core_pages':
        $pages = $info['pages'] ?? [];
        $details['tested_paths'] = array_map(fn(array $p) => $p['path'], $pages);
        $details['checks'] = [
          'HTTP 200 on each page',
          'No PHP fatal errors in body',
          'No JavaScript console errors',
          'No broken images (img with naturalWidth === 0)',
          'No mixed content (HTTP resources on HTTPS page)',
          'Site title matches in &lt;title&gt; tag',
        ];
        $details['selectors'] = [
          'body' => 'PHP error check',
          'img:visible' => 'Broken image detection',
          'link[rel="stylesheet"]' => 'Stylesheet count',
        ];
        break;

      case 'auth':
        $details['tested_paths'] = ['/user/login', '/user/password'];
        $details['test_user'] = 'smoke_bot';
        $details['test_role'] = 'smoke_bot (Smoke Test Bot)';
        $details['checks'] = [
          'Login page returns 200',
          'Username + Password fields visible',
          'Invalid login shows .messages--error',
          'smoke_bot can log in and redirect to /user/{uid}',
          'Password reset page has "Username or email" field',
        ];
        $details['selectors'] = [
          'getByLabel("Username")' => 'Username field',
          'getByLabel("Password")' => 'Password field',
          'getByRole("button", {name: "Log in"})' => 'Submit button',
          '.messages--error, .messages.error' => 'Error message',
          'getByLabel("Username or email address")' => 'Password reset field',
        ];
        break;

      case 'webform':
        $form = $info['form'] ?? NULL;
        if ($form) {
          $details['tested_paths'] = [$form['path'] ?? '/webform/smoke_test'];
          $details['webform_id'] = $form['id'] ?? 'smoke_test';
          $details['fields'] = [];
          foreach (($form['fields'] ?? []) as $field) {
            $details['fields'][] = [
              'key' => Html::escape($field['key'] ?? ''),
              'type' => Html::escape($field['type'] ?? ''),
              'title' => Html::escape($field['title'] ?? ''),
              'required' => !empty($field['required']),
            ];
          }
          $details['checks'] = [
            'Form page returns 200',
            'All fields are filled with test data',
            'Submit button clicked',
            'Confirmation message or URL change detected',
          ];
          $details['selectors'] = [
            'getByLabel("{field title}")' => 'Each form field by label',
            'getByRole("button", {name: "Submit"})' => 'Submit button',
            'body text content' => 'Confirmation check (submission/received/thank)',
          ];
        }
        break;

      case 'commerce':
        $details['tested_paths'] = [];
        if (!empty($info['hasProducts'])) {
          $details['tested_paths'][] = '/products (or catalog page)';
        }
        if (!empty($info['hasCart'])) {
          $details['tested_paths'][] = '/cart';
        }
        if (!empty($info['hasCheckout'])) {
          $details['tested_paths'][] = '/checkout';
        }
        $details['checks'] = [
          'Product catalog returns 200',
          'Cart endpoint exists',
          'Checkout endpoint exists',
          'At least one published product found',
        ];
        $details['flags'] = [
          'hasProducts' => !empty($info['hasProducts']),
          'hasStores' => !empty($info['hasStores']),
          'hasCart' => !empty($info['hasCart']),
          'hasCheckout' => !empty($info['hasCheckout']),
        ];
        break;

      case 'search':
        $searchPath = $info['searchPath'] ?? '/search';
        $details['tested_paths'] = [$searchPath];
        $details['checks'] = [
          'Search page returns 200',
          'Search form/input is present',
          'No PHP errors',
        ];
        $details['selectors'] = [
          'input[type="search"], input[name*="search"], form[role="search"]' => 'Search input',
        ];
        break;

      case 'health':
        $details['tested_paths'] = [
          '/admin/reports/status',
          '/admin/reports/dblog?type[]=php',
          '/ (homepage for asset check)',
          '/user/login (cache header check)',
        ];
        $details['checks'] = [
          'Status report has no "Fatal error"',
          'Cron has run (not "never run")',
          'CSS/JS assets return 200 (no 404/500)',
          'At least one stylesheet loaded',
          'No PHP errors in dblog',
          'Login page not served from page cache',
        ];
        $details['selectors'] = [
          '.system-status-report__status-title:has-text("Error")' => 'Status report errors',
          'details:has-text("Cron")' => 'Cron status row',
          'link[rel="stylesheet"]' => 'Stylesheet count',
          'x-drupal-cache header' => 'Page cache check',
        ];
        $details['requires_auth'] = TRUE;
        break;
    }

    // Custom URLs.
    $settings = $this->config('smoke.settings');
    $customUrls = $settings->get('custom_urls') ?? [];
    if (!empty($customUrls) && $id === 'core_pages') {
      $details['custom_urls'] = $customUrls;
    }

    return $details;
  }

  /**
   * Runs all enabled test suites.
   */
  public function run(Request $request): RedirectResponse {
    if (!$this->csrfToken($request)) {
      $this->messenger()->addError($this->t('Invalid request. Please try again.'));
      return new RedirectResponse(Url::fromRoute('smoke.dashboard')->toString());
    }

    if (!$this->testRunner->isSetup()) {
      $this->messenger()->addError($this->t('Playwright is not set up. Run: <code>drush smoke:setup</code>'));
      return new RedirectResponse(Url::fromRoute('smoke.dashboard')->toString());
    }

    $results = $this->testRunner->run();
    $summary = $results['summary'] ?? [];
    $failed = (int) ($summary['failed'] ?? 0);
    $passed = (int) ($summary['passed'] ?? 0);
    $duration = number_format(($summary['duration'] ?? 0) / 1000, 1);
    $total = $passed + $failed;

    if ($failed === 0 && $total > 0) {
      $this->messenger()->addStatus($this->t('All @count tests passed in @times.', [
        '@count' => $total,
        '@time' => $duration,
      ]));
    }
    elseif ($failed > 0) {
      $this->messenger()->addWarning($this->t('@passed of @total passed, @failed failed in @times.', [
        '@passed' => $passed,
        '@total' => $total,
        '@failed' => $failed,
        '@time' => $duration,
      ]));
    }
    else {
      $this->messenger()->addError($this->t('No test results. Check that Playwright is set up correctly.'));
    }

    return new RedirectResponse(Url::fromRoute('smoke.dashboard')->toString());
  }

  /**
   * Runs a single test suite.
   */
  public function runSuite(Request $request, string $suite): RedirectResponse {
    if (!$this->csrfToken($request)) {
      $this->messenger()->addError($this->t('Invalid request. Please try again.'));
      return new RedirectResponse(Url::fromRoute('smoke.dashboard')->toString());
    }

    if (!$this->testRunner->isSetup()) {
      $this->messenger()->addError($this->t('Playwright is not set up. Run: <code>drush smoke:setup</code>'));
      return new RedirectResponse(Url::fromRoute('smoke.dashboard')->toString());
    }

    $results = $this->testRunner->run($suite);
    $suiteData = $results['suites'][$suite] ?? NULL;

    if ($suiteData) {
      $failed = (int) ($suiteData['failed'] ?? 0);
      $passed = (int) ($suiteData['passed'] ?? 0);
      $duration = number_format(($suiteData['duration'] ?? 0) / 1000, 1);
      $label = Html::escape($suiteData['title'] ?? $suite);

      if ($failed === 0) {
        $this->messenger()->addStatus($this->t('@label: @count tests passed in @times.', [
          '@label' => $label,
          '@count' => $passed,
          '@time' => $duration,
        ]));
      }
      else {
        $this->messenger()->addWarning($this->t('@label: @failed of @total failed in @times.', [
          '@label' => $label,
          '@failed' => $failed,
          '@total' => $passed + $failed,
          '@time' => $duration,
        ]));
      }
    }

    return new RedirectResponse(Url::fromRoute('smoke.dashboard')->toString());
  }

  /**
   * Validates the CSRF token on POST requests.
   */
  private function csrfToken(Request $request): bool {
    $token = $request->request->get('token', '');
    return \Drupal::csrfToken()->validate((string) $token, 'smoke');
  }

}
