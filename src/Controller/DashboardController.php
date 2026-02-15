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
      '#attached' => [
        'library' => ['smoke/dashboard'],
      ],
    ];

    return $build;
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
    $total = $passed + $failed;

    if ($failed === 0 && $total > 0) {
      $this->messenger()->addStatus($this->t('All @count tests passed.', ['@count' => $total]));
    }
    elseif ($failed > 0) {
      $this->messenger()->addWarning($this->t('@passed of @total tests passed. @failed failed.', [
        '@passed' => $passed,
        '@total' => $total,
        '@failed' => $failed,
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
      $label = Html::escape($suiteData['title'] ?? $suite);

      if ($failed === 0) {
        $this->messenger()->addStatus($this->t('@label: @count tests passed.', [
          '@label' => $label,
          '@count' => $passed,
        ]));
      }
      else {
        $this->messenger()->addWarning($this->t('@label: @failed of @total tests failed.', [
          '@label' => $label,
          '@failed' => $failed,
          '@total' => $passed + $failed,
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
