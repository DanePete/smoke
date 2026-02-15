<?php

declare(strict_types=1);

namespace Drupal\smoke\Commands;

use Drupal\smoke\Service\ModuleDetector;
use Drupal\smoke\Service\TestRunner;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Runs all enabled smoke test suites.
 */
final class SmokeRunCommand extends DrushCommands {

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

  #[CLI\Command(name: 'smoke:run', aliases: ['smoke'])]
  #[CLI\Help(description: 'Run all enabled smoke test suites.')]
  #[CLI\Usage(name: 'drush smoke', description: 'Run all smoke tests.')]
  public function run(): void {
    if (!$this->testRunner->isSetup()) {
      $this->io()->error('Playwright is not set up. Run: drush smoke:setup');
      return;
    }

    $this->printHeader();
    $this->io()->text('  Running tests...');
    $this->io()->newLine();

    $results = $this->testRunner->run();
    $this->printResults($results);
  }

  /**
   * Prints the branded header.
   */
  private function printHeader(): void {
    $siteConfig = \Drupal::config('system.site');
    $siteName = (string) $siteConfig->get('name');

    $this->io()->newLine();
    $this->io()->text("  <options=bold>Smoke Tests</> — {$siteName}");
    $this->io()->text('  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
    $this->io()->newLine();
  }

  /**
   * Prints a formatted results table.
   */
  private function printResults(array $results): void {
    $suites = $results['suites'] ?? [];
    $summary = $results['summary'] ?? [];

    if (empty($suites)) {
      $this->io()->warning('No test results returned. Check Playwright output.');
      if (!empty($results['raw'])) {
        $this->io()->text(substr($results['raw'], 0, 500));
      }
      return;
    }

    // Table header.
    $this->io()->text('  <fg=gray>Suite            Tests   Pass   Fail   Time</>');
    $this->io()->text('  <fg=gray>───────────────  ─────   ────   ────   ─────</>');

    $labels = ModuleDetector::suiteLabels();
    foreach ($suites as $id => $suite) {
      $label = str_pad($labels[$id] ?? $suite['title'] ?? $id, 15);
      $total = ($suite['passed'] ?? 0) + ($suite['failed'] ?? 0);
      $tests = str_pad((string) $total, 5);
      $pass = str_pad((string) ($suite['passed'] ?? 0), 4);
      $fail = str_pad((string) ($suite['failed'] ?? 0), 4);
      $time = number_format(($suite['duration'] ?? 0) / 1000, 1) . 's';

      $failColor = ($suite['failed'] ?? 0) > 0 ? 'red' : 'gray';
      $this->io()->text("  {$label}  {$tests}   {$pass}   <fg={$failColor}>{$fail}</>   {$time}");
    }

    // Summary.
    $totalPassed = (int) ($summary['passed'] ?? 0);
    $totalFailed = (int) ($summary['failed'] ?? 0);
    $totalDuration = number_format(($summary['duration'] ?? 0) / 1000, 1);

    $this->io()->newLine();
    $this->io()->text('  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

    if ($totalFailed === 0 && $totalPassed > 0) {
      $this->io()->text("  Result: <fg=green>{$totalPassed} passed</>, 0 failed ({$totalDuration}s)");
      $this->io()->text('  Status: <fg=green;options=bold>ALL CLEAR</>');
    }
    elseif ($totalFailed > 0) {
      $this->io()->text("  Result: {$totalPassed} passed, <fg=red>{$totalFailed} failed</> ({$totalDuration}s)");
      $this->io()->text('  Status: <fg=red;options=bold>FAILURES DETECTED</>');

      // Print failure details.
      $this->io()->newLine();
      foreach ($suites as $suite) {
        foreach (($suite['tests'] ?? []) as $test) {
          if (($test['status'] ?? '') === 'failed') {
            $this->io()->text("  <fg=red>✕</> {$test['title']}");
            if (!empty($test['error'])) {
              $this->io()->text('    <fg=gray>' . substr($test['error'], 0, 200) . '</>');
            }
          }
        }
      }
    }
    else {
      $this->io()->text("  Result: No tests ran.");
    }

    $this->io()->newLine();
  }

}
