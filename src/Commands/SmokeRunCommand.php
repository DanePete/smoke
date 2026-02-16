<?php

declare(strict_types=1);

namespace Drupal\smoke\Commands;

use Drupal\smoke\Service\ModuleDetector;
use Drupal\smoke\Service\TestRunner;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Helper\ProgressBar;
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
      $remoteCredentials = $this->getRemoteCredentials();
      $this->runTests($target, $remoteCredentials);
      return;
    }

    $this->showLanding();
  }

  /**
   * Reads remote auth credentials from environment variables.
   *
   * Set by terminus-test.sh before invoking drush smoke.
   *
   * @return array<string, string>|null
   */
  private function getRemoteCredentials(): ?array {
    $user = getenv('SMOKE_REMOTE_USER') ?: '';
    $pass = getenv('SMOKE_REMOTE_PASS') ?: '';
    if ($user && $pass) {
      return ['user' => $user, 'password' => $pass];
    }
    return NULL;
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

    // Status — auto-run setup if possible.
    if (!$isSetup) {
      $isDdev = getenv('IS_DDEV_PROJECT') === 'true';

      if ($isDdev) {
        $this->io()->text('  <fg=cyan;options=bold>AUTO-SETUP</>  First run detected — setting up...');
        $this->io()->newLine();

        $process = new \Symfony\Component\Process\Process(
          ['drush', 'smoke:setup'],
          DRUPAL_ROOT . '/..',
        );
        $process->setTimeout(300);
        $process->run(function ($type, $buffer): void {
          $this->io()->write($buffer);
        });

        // Re-check after setup.
        if (!$this->testRunner->isSetup()) {
          $this->io()->text('  <fg=red>Setup did not complete. Run manually:</>');
          $this->io()->text('  <options=bold>ddev drush smoke:setup</>');
          $this->io()->newLine();
          return;
        }

        // Refresh state after setup.
        $lastResults = $this->testRunner->getLastResults();
        $lastRun = $this->testRunner->getLastRunTime();
      }
      else {
        $this->io()->text('  <fg=yellow;options=bold>SETUP NEEDED</>');
        $this->io()->text('  Run: <options=bold>ddev drush smoke:setup</>');
        $this->io()->newLine();
        return;
      }
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
        $webformId = (string) ($detected['webform']['form']['id'] ?? \Drupal::config('smoke.settings')->get('webform_id') ?? 'smoke_test');
        $this->io()->text("    Submissions:   {$baseUrl}/admin/structure/webform/manage/{$webformId}/results/submissions");
      }
      $this->io()->newLine();
    }
  }

  /**
   * Runs all tests with a live progress bar.
   *
   * Executes suites sequentially so results stream in real-time
   * instead of blocking until all tests finish.
   *
   * @param string|null $targetUrl
   *   Optional remote URL to test against.
   * @param array<string, string>|null $remoteCredentials
   *   Optional remote auth credentials from Terminus.
   */
  private function runTests(?string $targetUrl = NULL, ?array $remoteCredentials = NULL): void {
    if (!$this->testRunner->isSetup()) {
      $isDdev = getenv('IS_DDEV_PROJECT') === 'true';

      if ($isDdev) {
        $this->io()->text('  <fg=cyan>Setting up Playwright (first run)...</>');
        $this->io()->newLine();
        $process = new \Symfony\Component\Process\Process(
          ['drush', 'smoke:setup'],
          DRUPAL_ROOT . '/..',
        );
        $process->setTimeout(300);
        $process->run(function ($type, $buffer): void {
          $this->io()->write($buffer);
        });
      }

      if (!$this->testRunner->isSetup()) {
        $this->io()->error('Playwright is not set up. Run: ddev drush smoke:setup');
        return;
      }
    }

    // Header.
    $siteConfig = \Drupal::config('system.site');
    $siteName = (string) $siteConfig->get('name');
    $displayUrl = $targetUrl ?: (getenv('DDEV_PRIMARY_URL') ?: 'unknown');
    $hasTerminus = $remoteCredentials !== NULL;

    $this->io()->newLine();
    $this->io()->text("  <options=bold>Smoke Tests</> — {$siteName}");
    if ($targetUrl && $hasTerminus) {
      $this->io()->text("  <fg=magenta;options=bold>REMOTE + TERMINUS</>  {$displayUrl}");
      $this->io()->text('  <fg=gray>Auth enabled via Terminus — all suites will run.</>');
    }
    elseif ($targetUrl) {
      $this->io()->text("  <fg=magenta;options=bold>REMOTE</>  {$displayUrl}");
      $this->io()->text('  <fg=gray>Auth & health suites will auto-skip (no smoke_bot on remote).</>');
    }
    else {
      $this->io()->text("  <fg=gray>{$displayUrl}</>");
    }
    $this->io()->text('  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
    $this->io()->newLine();

    // Determine which suites to run.
    $detected = $this->moduleDetector->detect();
    $settings = \Drupal::config('smoke.settings');
    $enabledSuites = $settings->get('suites') ?? [];
    $labels = ModuleDetector::suiteLabels();

    $suitesToRun = [];
    foreach ($detected as $id => $info) {
      $enabled = $enabledSuites[$id] ?? TRUE;
      if ($enabled && ($info['detected'] ?? FALSE)) {
        $suitesToRun[] = $id;
      }
    }

    $totalSuites = count($suitesToRun);
    if ($totalSuites === 0) {
      $this->io()->warning('No test suites detected.');
      return;
    }

    // Clear previous results for a clean full run.
    \Drupal::state()->set('smoke.last_results', []);

    // Configure progress bar.
    ProgressBar::setFormatDefinition('smoke', "  <fg=cyan>▸</> %message:-18s%  %bar%  %current%/%max% suites  <fg=gray>%elapsed:6s%</>");
    $progress = new ProgressBar($this->io(), $totalSuites);
    $progress->setFormat('smoke');
    $progress->setBarCharacter('<fg=green>━</>');
    $progress->setEmptyBarCharacter('<fg=gray>─</>');
    $progress->setProgressCharacter('<fg=cyan>▸</>');
    $progress->setBarWidth(20);

    $totalPassed = 0;
    $totalFailed = 0;
    $totalSkipped = 0;
    $startTime = microtime(TRUE);

    // Show initial progress bar with first suite name.
    $progress->setMessage($labels[$suitesToRun[0]] ?? $suitesToRun[0]);
    $progress->start();

    foreach ($suitesToRun as $i => $suiteId) {
      $label = $labels[$suiteId] ?? $suiteId;

      // Run this suite (progress bar visible while waiting).
      $results = $this->testRunner->run($suiteId, $targetUrl, $remoteCredentials);
      $suiteResult = $results['suites'][$suiteId] ?? [];

      $passed = (int) ($suiteResult['passed'] ?? 0);
      $failed = (int) ($suiteResult['failed'] ?? 0);
      $skipped = (int) ($suiteResult['skipped'] ?? 0);
      $time = number_format(($suiteResult['duration'] ?? 0) / 1000, 1);

      $totalPassed += $passed;
      $totalFailed += $failed;
      $totalSkipped += $skipped;

      // Clear progress bar, print suite result.
      $progress->clear();

      $badge = $failed > 0
        ? "<fg=red>✕ {$failed} failed</>"
        : "<fg=green>✓ {$passed} passed</>";
      $paddedLabel = str_pad($label, 18);
      $this->io()->text("  {$paddedLabel}{$badge}  <fg=gray>{$time}s</>");

      // Show individual failed tests inline.
      if ($failed > 0) {
        foreach (($suiteResult['tests'] ?? []) as $test) {
          if (($test['status'] ?? '') === 'failed') {
            $testTime = number_format(($test['duration'] ?? 0) / 1000, 1);
            $this->io()->text("    <fg=red>✕</> {$test['title']}  <fg=gray>{$testTime}s</>");
            if (!empty($test['error'])) {
              $error = (string) preg_replace('/\x1b\[[0-9;]*m/', '', $test['error']);
              $this->io()->text('      <fg=red>' . substr($error, 0, 200) . '</>');
            }
          }
        }
      }

      // Set next suite name, then advance (which auto-displays the bar).
      if ($i + 1 < $totalSuites) {
        $progress->setMessage($labels[$suitesToRun[$i + 1]] ?? $suitesToRun[$i + 1]);
      }
      else {
        $progress->setMessage('Finishing...');
      }
      $progress->advance();
    }

    // Remove progress bar from output.
    $progress->clear();

    // Summary.
    $totalDuration = number_format(microtime(TRUE) - $startTime, 1);

    $this->io()->newLine();
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

    // Remote explanation.
    if ($targetUrl && $hasTerminus) {
      $this->io()->newLine();
      $this->io()->text('  <fg=cyan;options=bold>Terminus remote notes:</>');
      $this->io()->text('  <fg=gray>Auth enabled:</>     smoke_bot created on remote via Terminus');
      $this->io()->text('  <fg=gray>All suites ran:</>   Auth, Health (admin), Content, plus anonymous tests');
      $this->io()->text('  <fg=gray>Webform:</>          Tried to load — skips on 404 (deploy config to enable)');
      $this->io()->text('  <fg=gray>Cleanup:</>          smoke_bot removed from remote after tests');
    }
    elseif ($targetUrl) {
      $this->io()->newLine();
      $this->io()->text('  <fg=cyan;options=bold>Remote test notes:</>');
      $this->io()->text('  <fg=gray>Ran normally:</>     Core Pages, Commerce, Search, Accessibility, Health (assets)');
      $this->io()->text('  <fg=gray>Auto-skipped:</>     Auth (login), Health (admin/cron/dblog), Content (no smoke_bot)');
      $this->io()->text('  <fg=gray>Webform:</>          Tried to load — skips on 404 (deploy config to enable)');
      $this->io()->text('  <fg=gray>Sitemap:</>          Only if simple_sitemap or xmlsitemap is installed');
      $this->io()->newLine();
      $this->io()->text('  <fg=gray>Tip: Use terminus-test.sh to enable auth tests on remote:</>');
      $this->io()->text('  <fg=gray>  bash web/modules/contrib/smoke/scripts/terminus-test.sh SITE.ENV</>');
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
        $this->io()->text("  <fg=gray>Settings:</>        {$localUrl}/admin/config/development/smoke");
        $this->io()->text("  <fg=gray>Status report:</>   {$localUrl}/admin/reports/status");
        $this->io()->text("  <fg=gray>Recent log:</>      {$localUrl}/admin/reports/dblog");
      }
      $allSuiteResults = $this->testRunner->getLastResults();
      $allSuites = $allSuiteResults['suites'] ?? [];
      if (!$targetUrl && $this->hasWebformResults($allSuites)) {
        $webformId = (string) (\Drupal::config('smoke.settings')->get('webform_id') ?? 'smoke_test');
        $this->io()->text("  <fg=gray>Submissions:</>     {$baseUrl}/admin/structure/webform/manage/{$webformId}/results/submissions");
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
