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
  #[CLI\Argument(name: 'suite', description: 'Suite to run (core_pages, auth, webform, commerce, search).')]
  #[CLI\Help(description: 'Run a single smoke test suite.')]
  #[CLI\Usage(name: 'drush smoke:suite webform', description: 'Run only the webform tests.')]
  public function suite(string $suite): void {
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

    $this->io()->newLine();
    $this->io()->text("  Running <options=bold>{$labels[$suite]}</> suite...");
    $this->io()->newLine();

    $results = $this->testRunner->run($suite);
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
        $this->io()->text('    <fg=red>' . substr($test['error'], 0, 200) . '</>');
      }
    }

    $passed = (int) ($suiteData['passed'] ?? 0);
    $failed = (int) ($suiteData['failed'] ?? 0);
    $this->io()->newLine();

    if ($failed === 0) {
      $this->io()->success("{$labels[$suite]}: {$passed} tests passed.");
    }
    else {
      $this->io()->error("{$labels[$suite]}: {$failed} of " . ($passed + $failed) . " tests failed.");
    }
  }

}
