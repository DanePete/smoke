<?php

declare(strict_types=1);

namespace Drupal\smoke\Commands;

use Drupal\smoke\Service\ModuleDetector;
use Drupal\smoke\Service\TestRunner;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Main smoke command — landing page and test runner.
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
  #[CLI\Help(description: 'Smoke testing for Drupal — run tests or see status.')]
  #[CLI\Usage(name: 'drush smoke', description: 'Show status and available commands.')]
  #[CLI\Usage(name: 'drush smoke --run', description: 'Run all smoke tests.')]
  #[CLI\Usage(name: 'drush smoke --run --target=https://test-mysite.pantheonsite.io', description: 'Run tests against a remote URL.')]
  #[CLI\Option(name: 'run', description: 'Run all enabled test suites.')]
  #[CLI\Option(name: 'target', description: 'Remote URL to test against (e.g. https://test-mysite.pantheonsite.io). Auth/health suites auto-skip on remote.')]
  public function run(array $options = ['run' => FALSE, 'target' => '']): void {
    if ($options['run']) {
      $target = $options['target'] ?: NULL;
      $this->runTests($target);
      return;
    }

    $this->showLanding();
  }

  /**
   * Shows the landing page with status and commands.
   */
  private function showLanding(): void {
    $siteConfig = \Drupal::config('system.site');
    $siteName = (string) $siteConfig->get('name');
    $baseUrl = getenv('DDEV_PRIMARY_URL') ?: 'unknown';
    $isSetup = $this->testRunner->isSetup();
    $detected = $this->moduleDetector->detect();
    $labels = ModuleDetector::suiteLabels();
    $settings = \Drupal::config('smoke.settings');
    $enabledSuites = $settings->get('suites') ?? [];
    $lastResults = $this->testRunner->getLastResults();
    $lastRun = $this->testRunner->getLastRunTime();

    // Header.
    $this->io()->newLine();
    $this->io()->text('  <fg=cyan>  ___  __  __   ___   _  __ ___</>');
    $this->io()->text('  <fg=cyan> / __|/  \/  \ / _ \ | |/ /| __|</>');
    $this->io()->text('  <fg=cyan> \__ \ |\/| || (_) ||   < | _|</>');
    $this->io()->text('  <fg=cyan> |___/_|  |_| \___/ |_|\_\|___|</>');
    $this->io()->newLine();
    $this->io()->text("  <fg=gray>{$siteName} · {$baseUrl}</>");
    $this->io()->text('  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
    $this->io()->newLine();

    // Status.
    if (!$isSetup) {
      $this->io()->text('  <fg=yellow;options=bold>SETUP NEEDED</>');
      $this->io()->text('  Run: <options=bold>bash web/modules/contrib/smoke/scripts/host-setup.sh</>');
      $this->io()->newLine();
      return;
    }

    // Last run results.
    if ($lastResults && $lastRun) {
      $summary = $lastResults['summary'] ?? [];
      $passed = (int) ($summary['passed'] ?? 0);
      $failed = (int) ($summary['failed'] ?? 0);
      $duration = number_format(($summary['duration'] ?? 0) / 1000, 1);
      $date = date('M j, g:ia', $lastRun);

      if ($failed === 0 && $passed > 0) {
        $this->io()->text("  <fg=green;options=bold>✓ ALL CLEAR</>  {$passed} passed in {$duration}s");
      }
      elseif ($failed > 0) {
        $this->io()->text("  <fg=red;options=bold>✕ {$failed} FAILED</>  {$passed} passed, {$failed} failed in {$duration}s");
      }
      $this->io()->text("  <fg=gray>Last run: {$date}</>");
      $this->io()->newLine();
    }
    else {
      $this->io()->text('  <fg=blue>●</> No tests run yet.');
      $this->io()->newLine();
    }

    // Detected suites.
    $this->io()->text('  <options=bold>Suites</>');
    $this->io()->newLine();

    foreach ($labels as $id => $label) {
      $isDetected = !empty($detected[$id]['detected']);
      $isEnabled = $enabledSuites[$id] ?? TRUE;
      $result = $lastResults['suites'][$id] ?? NULL;

      if (!$isDetected) {
        $icon = '<fg=gray>○</>';
        $status = '<fg=gray>not detected</>';
      }
      elseif (!$isEnabled) {
        $icon = '<fg=yellow>—</>';
        $status = '<fg=yellow>disabled</>';
      }
      elseif ($result && ($result['failed'] ?? 0) === 0 && ($result['passed'] ?? 0) > 0) {
        $p = (int) ($result['passed'] ?? 0);
        $t = number_format(($result['duration'] ?? 0) / 1000, 1);
        $icon = '<fg=green>✓</>';
        $status = "<fg=green>{$p} passed</> <fg=gray>{$t}s</>";
      }
      elseif ($result && ($result['failed'] ?? 0) > 0) {
        $f = (int) ($result['failed'] ?? 0);
        $icon = '<fg=red>✕</>';
        $status = "<fg=red>{$f} failed</>";
      }
      else {
        $icon = '<fg=blue>●</>';
        $status = '<fg=blue>ready</>';
      }

      $paddedLabel = str_pad($label, 18);
      $this->io()->text("    {$icon} {$paddedLabel}{$status}");
    }

    // Commands.
    $this->io()->newLine();
    $this->io()->text('  <options=bold>Commands</>');
    $this->io()->newLine();
    $this->io()->text('    <options=bold>ddev drush smoke --run</>         Run all tests');
    $this->io()->text('    <options=bold>ddev drush smoke:suite webform</>  Run one suite');
    $this->io()->text('    <options=bold>ddev drush smoke --run --target=URL</>  Test a remote site');
    $this->io()->text('    <options=bold>ddev drush smoke:setup</>         Regenerate config');
    $this->io()->newLine();

    // Links.
    if ($baseUrl && $baseUrl !== 'unknown') {
      $this->io()->text('  <options=bold>Links</>');
      $this->io()->newLine();
      $this->io()->text("    Dashboard:     {$baseUrl}/admin/reports/smoke");
      $this->io()->text("    Settings:      {$baseUrl}/admin/config/development/smoke");
      $this->io()->text("    Status report: {$baseUrl}/admin/reports/status");
      $hasWebform = !empty($detected['webform']['detected']);
      if ($hasWebform) {
        $this->io()->text("    Submissions:   {$baseUrl}/admin/structure/webform/manage/smoke_test/results/submissions");
      }
      $this->io()->newLine();
    }
  }

  /**
   * Runs all tests and prints results.
   *
   * @param string|null $targetUrl
   *   Optional remote URL to test against.
   */
  private function runTests(?string $targetUrl = NULL): void {
    if (!$this->testRunner->isSetup()) {
      $this->io()->error('Playwright is not set up. Run: drush smoke:setup');
      return;
    }

    $siteConfig = \Drupal::config('system.site');
    $siteName = (string) $siteConfig->get('name');
    $displayUrl = $targetUrl ?: (getenv('DDEV_PRIMARY_URL') ?: 'unknown');

    $this->io()->newLine();
    $this->io()->text("  <options=bold>Smoke Tests</> — {$siteName}");
    if ($targetUrl) {
      $this->io()->text("  <fg=magenta;options=bold>REMOTE</>  {$displayUrl}");
      $this->io()->text('  <fg=gray>Auth & health suites will auto-skip (no smoke_bot on remote).</>');
    }
    else {
      $this->io()->text("  <fg=gray>{$displayUrl}</>");
    }
    $this->io()->text('  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
    $this->io()->newLine();
    $this->io()->text('  <fg=gray>        (  )</>');
    $this->io()->text('  <fg=gray>       ) (</>');
    $this->io()->text('  <fg=gray>      (  )</>');
    $this->io()->text('  <fg=white;options=bold>    ,___,</>');
    $this->io()->text('  <fg=white;options=bold>    |   |</>  <fg=cyan>Running tests...</>');
    $this->io()->newLine();

    $results = $this->testRunner->run(NULL, $targetUrl);
    $this->printResults($results, $targetUrl);
  }

  /**
   * Prints a formatted results report.
   */
  private function printResults(array $results, ?string $targetUrl = NULL): void {
    $suites = $results['suites'] ?? [];
    $summary = $results['summary'] ?? [];

    if (empty($suites)) {
      $this->io()->warning('No test results returned. Check Playwright output.');
      if (!empty($results['raw'])) {
        $this->io()->text(substr($results['raw'], 0, 500));
      }
      return;
    }

    $labels = ModuleDetector::suiteLabels();

    foreach ($suites as $id => $suite) {
      $label = $labels[$id] ?? $suite['title'] ?? $id;
      $failed = (int) ($suite['failed'] ?? 0);
      $passed = (int) ($suite['passed'] ?? 0);
      $time = number_format(($suite['duration'] ?? 0) / 1000, 1);

      $badge = $failed > 0
        ? "<fg=red>✕ {$failed} failed</>"
        : "<fg=green>✓ {$passed} passed</>";

      $this->io()->text("  <options=bold>{$label}</>  {$badge}  <fg=gray>{$time}s</>");

      foreach (($suite['tests'] ?? []) as $test) {
        $icon = ($test['status'] ?? '') === 'passed'
          ? '<fg=green>✓</>'
          : '<fg=red>✕</>';
        $testTime = number_format(($test['duration'] ?? 0) / 1000, 1);
        $this->io()->text("    {$icon} {$test['title']}  <fg=gray>{$testTime}s</>");

        if (($test['status'] ?? '') === 'failed' && !empty($test['error'])) {
          $error = (string) preg_replace('/\x1b\[[0-9;]*m/', '', $test['error']);
          $this->io()->text('      <fg=red>' . substr($error, 0, 200) . '</>');
        }
      }

      $this->io()->newLine();
    }

    // Summary.
    $totalPassed = (int) ($summary['passed'] ?? 0);
    $totalFailed = (int) ($summary['failed'] ?? 0);
    $totalDuration = number_format(($summary['duration'] ?? 0) / 1000, 1);

    $this->io()->text('  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

    if ($totalFailed === 0 && $totalPassed > 0) {
      $this->io()->newLine();
      $this->io()->text('  <fg=green>  *  *  *  *  *  *  *  *  *  *  *</>');
      $this->io()->text("  <fg=green;options=bold>  ✓  ALL CLEAR</>  {$totalPassed} passed in {$totalDuration}s");
      $this->io()->text('  <fg=green>  *  *  *  *  *  *  *  *  *  *  *</>');
    }
    elseif ($totalFailed > 0) {
      $this->io()->newLine();
      $this->io()->text('  <fg=red>  ▓  ▓  ▓  ▓  ▓  ▓  ▓  ▓  ▓  ▓  ▓</>');
      $this->io()->text("  <fg=red;options=bold>  ✕  FAILURES</>   {$totalPassed} passed, {$totalFailed} failed in {$totalDuration}s");
      $this->io()->text('  <fg=red>  ▓  ▓  ▓  ▓  ▓  ▓  ▓  ▓  ▓  ▓  ▓</>');
    }
    else {
      $this->io()->text('  No tests ran.');
    }

    // Links.
    $baseUrl = $targetUrl ?: (getenv('DDEV_PRIMARY_URL') ?: '');
    if ($baseUrl) {
      $this->io()->newLine();
      if ($targetUrl) {
        $this->io()->text("  <fg=gray>Tested:</>          {$targetUrl}");
      }
      $localUrl = getenv('DDEV_PRIMARY_URL') ?: '';
      if ($localUrl) {
        $this->io()->text("  <fg=gray>Dashboard:</>       {$localUrl}/admin/reports/smoke");
      }
      if (!$targetUrl && $this->hasWebformResults($suites)) {
        $this->io()->text("  <fg=gray>Submissions:</>     {$baseUrl}/admin/structure/webform/manage/smoke_test/results/submissions");
      }
    }

    $this->io()->newLine();
  }

  /**
   * Checks if webform results exist.
   */
  private function hasWebformResults(array $suites): bool {
    return isset($suites['webform']) && (($suites['webform']['passed'] ?? 0) + ($suites['webform']['failed'] ?? 0)) > 0;
  }

}
