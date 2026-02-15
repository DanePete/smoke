<?php

declare(strict_types=1);

namespace Drupal\smoke\Commands;

use Drupal\smoke\Service\ModuleDetector;
use Drupal\smoke\Service\TestRunner;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Runs a single smoke test suite by name.
 */
final class SmokeSuiteCommand extends DrushCommands {

  public function __construct(
    private readonly TestRunner $testRunner,
    private readonly ModuleDetector $moduleDetector,
  ) {
    parent::__construct();
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('smoke.test_runner'),
      $container->get('smoke.module_detector'),
    );
  }

  #[CLI\Command(name: 'smoke:suite')]
  #[CLI\Argument(name: 'suite', description: 'Suite to run (core_pages, auth, webform, commerce, search, health).')]
  #[CLI\Help(description: 'Run a single smoke test suite.')]
  #[CLI\Usage(name: 'drush smoke:suite webform', description: 'Run only the webform tests.')]
  #[CLI\Usage(name: 'drush smoke:suite core_pages --target=https://test-mysite.pantheonsite.io', description: 'Test a remote site.')]
  #[CLI\Option(name: 'target', description: 'Remote URL to test against.')]
  public function suite(string $suite, array $options = ['target' => '']): void {
    if (!$this->testRunner->isSetup()) {
      $this->io()->error('Playwright is not set up. Run: drush smoke:setup');
      return;
    }

    $labels = ModuleDetector::suiteLabels();
    if (!isset($labels[$suite])) {
      $this->io()->error("Unknown suite: {$suite}");
      $this->io()->text('Available suites: ' . implode(', ', array_keys($labels)));
      return;
    }

    $target = $options['target'] ?: NULL;
    $baseUrl = $target ?: (getenv('DDEV_PRIMARY_URL') ?: '');

    $this->io()->newLine();
    $this->io()->text("  <options=bold>{$labels[$suite]}</>");
    if ($target) {
      $this->io()->text("  <fg=magenta;options=bold>REMOTE</>  {$target}");
    }
    elseif ($baseUrl) {
      $this->io()->text("  <fg=gray>{$baseUrl}</>");
    }
    $this->io()->text('  ───────────────────────────────────────');
    $this->io()->newLine();

    $this->io()->text('  <fg=cyan>⠿</> Running...');
    $this->io()->newLine();

    $results = $this->testRunner->run($suite, $target);
    $suiteData = $results['suites'][$suite] ?? NULL;

    if (!$suiteData) {
      $this->io()->warning("No results returned for suite: {$suite}");
      return;
    }

    foreach (($suiteData['tests'] ?? []) as $test) {
      $icon = ($test['status'] ?? '') === 'passed' ? '<fg=green>✓</>' : '<fg=red>✕</>';
      $time = number_format(($test['duration'] ?? 0) / 1000, 1) . 's';
      $this->io()->text("  {$icon} {$test['title']}  <fg=gray>{$time}</>");

      if (($test['status'] ?? '') === 'failed' && !empty($test['error'])) {
        $error = (string) preg_replace('/\x1b\[[0-9;]*m/', '', $test['error']);
        $this->io()->text('    <fg=red>' . substr($error, 0, 200) . '</>');
      }
    }

    $passed = (int) ($suiteData['passed'] ?? 0);
    $failed = (int) ($suiteData['failed'] ?? 0);
    $duration = number_format(($suiteData['duration'] ?? 0) / 1000, 1);
    $this->io()->newLine();
    $this->io()->text('  ───────────────────────────────────────');

    if ($failed === 0 && $passed > 0) {
      $this->io()->text("  <fg=green;options=bold>PASSED</>  {$passed} tests in {$duration}s");
    }
    elseif ($failed > 0) {
      $this->io()->text("  <fg=red;options=bold>FAILED</>  {$failed} of " . ($passed + $failed) . " in {$duration}s");
    }

    // Suite-specific links.
    if ($baseUrl) {
      $this->io()->newLine();
      if ($suite === 'webform') {
        $this->io()->text("  <fg=gray>View form:</>       {$baseUrl}/webform/smoke_test");
        $this->io()->text("  <fg=gray>Submissions:</>     {$baseUrl}/admin/structure/webform/manage/smoke_test/results/submissions");
      }
      elseif ($suite === 'auth') {
        $this->io()->text("  <fg=gray>Login page:</>      {$baseUrl}/user/login");
      }
      elseif ($suite === 'commerce') {
        $this->io()->text("  <fg=gray>Products:</>        {$baseUrl}/admin/commerce/products");
        $this->io()->text("  <fg=gray>Orders:</>          {$baseUrl}/admin/commerce/orders");
      }
      elseif ($suite === 'health') {
        $this->io()->text("  <fg=gray>Status report:</>   {$baseUrl}/admin/reports/status");
        $this->io()->text("  <fg=gray>Recent log:</>      {$baseUrl}/admin/reports/dblog");
      }
      $this->io()->text("  <fg=gray>Dashboard:</>       {$baseUrl}/admin/reports/smoke");
    }

    $this->io()->newLine();
  }

}
