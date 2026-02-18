<?php

declare(strict_types=1);

namespace Drupal\smoke\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\smoke\Service\JunitReporter;
use Drupal\smoke\Service\ModuleDetector;
use Drupal\smoke\Service\TestRunner;
use Drupal\smoke\SmokeConstants;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Process\Process;

/**
 * Main smoke command — landing page and test runner.
 */
final class SmokeRunCommand extends DrushCommands {

  /**
   * Constructs the SmokeRunCommand.
   *
   * @param \Drupal\smoke\Service\TestRunner $testRunner
   *   The test runner service.
   * @param \Drupal\smoke\Service\ModuleDetector $moduleDetector
   *   The module detector service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\smoke\Service\JunitReporter $junitReporter
   *   The JUnit reporter service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   */
  public function __construct(
    private readonly TestRunner $testRunner,
    private readonly ModuleDetector $moduleDetector,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly StateInterface $state,
    private readonly JunitReporter $junitReporter,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('smoke.test_runner'),
      $container->get('smoke.module_detector'),
      $container->get('config.factory'),
      $container->get('state'),
      $container->get('smoke.junit_reporter'),
      $container->get('module_handler'),
    );
  }

  /**
   * Main entry: show landing or run all tests.
   *
   * @param array $options
   *   The 'run' and 'target' options.
   */
  #[CLI\Command(name: 'smoke:run', aliases: ['smoke'])]
  #[CLI\Help(description: 'Smoke tests — run or see status.')]
  #[CLI\Usage(name: 'drush smoke', description: 'Show status and commands.')]
  #[CLI\Usage(name: 'drush smoke --run', description: 'Run all smoke tests.')]
  #[CLI\Usage(name: 'drush smoke --run --quick', description: 'Run only core_pages and auth (fast sanity check).')]
  #[CLI\Usage(name: 'drush smoke --run --junit=/path/to/results.xml', description: 'Output JUnit XML for CI.')]
  #[CLI\Usage(name: 'drush smoke --run --html=/path/to/report', description: 'Generate HTML report.')]
  #[CLI\Usage(name: 'drush smoke --run --parallel', description: 'Run suites in parallel (faster).')]
  #[CLI\Usage(name: 'drush smoke --run --detailed', description: 'Show detailed test output.')]
  #[CLI\Usage(name: 'drush smoke --run --suite=auth,webform', description: 'Run only specific suites.')]
  #[CLI\Usage(name: 'drush smoke --run --watch', description: 'Watch mode: re-run on file changes.')]
  #[CLI\Usage(name: 'drush smoke --run --target=URL', description: 'Test remote URL.')]
  #[CLI\Option(name: 'run', description: 'Run all enabled test suites.')]
  #[CLI\Option(name: 'target', description: 'Remote URL. Auth/health skip on remote.')]
  #[CLI\Option(name: 'quick', description: 'Quick mode: only run core_pages and auth suites.')]
  #[CLI\Option(name: 'junit', description: 'Output JUnit XML to this file path for CI integration.')]
  #[CLI\Option(name: 'html', description: 'Output HTML report to this directory path.')]
  #[CLI\Option(name: 'parallel', description: 'Run test suites in parallel (uses multiple workers).')]
  #[CLI\Option(name: 'detailed', description: 'Show detailed test output including individual test steps.')]
  #[CLI\Option(name: 'suite', description: 'Comma-separated list of specific suites to run (e.g., auth,webform,core_pages).')]
  #[CLI\Option(name: 'watch', description: 'Watch mode: re-run tests when spec files change.')]
  public function run(array $options = ['run' => FALSE, 'target' => '', 'quick' => FALSE, 'junit' => '', 'html' => '', 'parallel' => FALSE, 'detailed' => FALSE, 'suite' => '', 'watch' => FALSE]): void {
    if ($options['run']) {
      $target = $options['target'] ?: NULL;
      $quickMode = (bool) $options['quick'];
      $junitPath = $options['junit'] ?: NULL;
      $htmlPath = $options['html'] ?: NULL;
      $parallel = (bool) $options['parallel'];
      $verbose = (bool) $options['detailed'];
      $suiteFilter = $options['suite'] ?: NULL;
      $watchMode = (bool) $options['watch'];
      $remoteCredentials = $this->getRemoteCredentials();

      if ($watchMode) {
        $this->runWatchMode($target, $remoteCredentials, $quickMode, $junitPath, $htmlPath, $parallel, $verbose, $suiteFilter);
        return;
      }

      $this->runTests($target, $remoteCredentials, $quickMode, $junitPath, $htmlPath, $parallel, $verbose, $suiteFilter);
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
   *   Credentials array or NULL.
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
    $siteConfig = $this->configFactory->get('system.site');
    $siteName = (string) $siteConfig->get('name');
    $baseUrl = getenv('DDEV_PRIMARY_URL') ?: 'unknown';
    $isSetup = $this->testRunner->isSetup();
    $detected = $this->moduleDetector->detect();
    $labels = ModuleDetector::suiteLabels();
    $settings = $this->configFactory->get('smoke.settings');
    $enabledSuites = $settings->get('suites') ?? [];
    $lastResults = $this->testRunner->getLastResults();
    $lastRun = $this->testRunner->getLastRunTime();

    // Header.
    $this->io()->newLine();
    $this->printLogo();
    $this->io()->text("   <fg=white;options=bold>{$siteName}</>");
    $this->io()->text("   <fg=gray>{$baseUrl}</>");
    $this->io()->text('   <fg=#555>─────────────────────────────────────────────────</>');
    $this->io()->newLine();

    // Status — auto-run setup if possible.
    if (!$isSetup) {
      $isDdev = getenv('IS_DDEV_PROJECT') === 'true';

      if ($isDdev) {
        $this->io()->text('  <fg=cyan;options=bold>AUTO-SETUP</>  First run detected — setting up...');
        $this->io()->newLine();

        $process = new Process(
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
        $this->io()->text("   <fg=#98D8AA;options=bold>✓ ALL CLEAR</>  {$passed} passed in {$duration}s");
      }
      elseif ($failed > 0) {
        $this->io()->text("   <fg=#FF6B6B;options=bold>✕ {$failed} FAILED</>  {$passed} passed, {$failed} failed in {$duration}s");
      }
      $this->io()->text("   <fg=#666>Last run: {$date}</>");
      $this->io()->newLine();
    }
    else {
      $this->io()->text('   <fg=#FFB347>●</> No tests run yet.');
      $this->io()->newLine();
    }

    // Detected suites.
    $this->io()->text('   <fg=white;options=bold>Suites</>');
    $this->io()->newLine();

    foreach ($labels as $id => $label) {
      $isDetected = !empty($detected[$id]['detected']);
      $isEnabled = $enabledSuites[$id] ?? TRUE;
      $result = $lastResults['suites'][$id] ?? NULL;

      if (!$isDetected) {
        $icon = '<fg=#555>○</>';
        $status = '<fg=#555>not detected</>';
      }
      elseif (!$isEnabled) {
        $icon = '<fg=#FFD700>─</>';
        $status = '<fg=#FFD700>disabled</>';
      }
      elseif ($result && ($result['failed'] ?? 0) === 0 && ($result['passed'] ?? 0) > 0) {
        $p = (int) ($result['passed'] ?? 0);
        $t = number_format(($result['duration'] ?? 0) / 1000, 1);
        $icon = '<fg=#98D8AA>✓</>';
        $status = "<fg=#98D8AA>{$p} passed</> <fg=#666>{$t}s</>";
      }
      elseif ($result && ($result['failed'] ?? 0) > 0) {
        $f = (int) ($result['failed'] ?? 0);
        $icon = '<fg=#FF6B6B>✕</>';
        $status = "<fg=#FF6B6B>{$f} failed</>";
      }
      else {
        $icon = '<fg=#FFB347>●</>';
        $status = '<fg=#FFB347>ready</>';
      }

      $paddedLabel = str_pad($label, 18);
      $this->io()->text("      {$icon} {$paddedLabel}{$status}");
    }

    // Commands.
    $this->io()->newLine();
    $this->io()->text('   <fg=white;options=bold>Commands</>');
    $this->io()->newLine();
    $this->io()->text('      <fg=#FFB347>▸</> <fg=white>drush smoke --run</>                  <fg=#666>Run all tests</>');
    $this->io()->text('      <fg=#FFB347>▸</> <fg=white>drush smoke:list</>                   <fg=#666>See detected suites</>');
    $this->io()->text('      <fg=#FFB347>▸</> <fg=white>drush smoke:suite [name]</>             <fg=#666>Run one suite</>');
    $this->io()->text('      <fg=#FFB347>▸</> <fg=white>drush smoke --run --quick</>          <fg=#666>Fast sanity check</>');
    $this->io()->text('      <fg=#FFB347>▸</> <fg=white>drush smoke:setup</>                  <fg=#666>Set up Playwright</>');
    $this->io()->text('      <fg=#FFB347>▸</> <fg=white>drush smoke:copy-to-project</>         <fg=#666>Copy to project for IDE</>');
    $this->io()->text('      <fg=#FFB347>▸</> <fg=white>drush smoke:init</>                   <fg=#666>Initialize for VS Code/Cursor</>');
    $this->io()->text('      <fg=#FFB347>▸</> <fg=white>drush smoke:fix</>                    <fg=#666>Auto-fix common issues</>');
    $this->io()->newLine();

    // Agency tip (one-time).
    $this->showAgencyTipIfNeeded();

    // Links.
    if ($baseUrl && $baseUrl !== 'unknown') {
      $this->io()->text('   <fg=white;options=bold>Links</>');    
      $this->io()->newLine();
      $this->io()->text("      <fg=#888>Dashboard</>     <fg=#5C9EE8>{$baseUrl}/admin/reports/smoke</>");
      $this->io()->text("      <fg=#888>Settings</>      <fg=#5C9EE8>{$baseUrl}/admin/config/development/smoke</>");
      $hasWebform = !empty($detected['webform']['detected']);
      if ($hasWebform) {
        $webformId = (string) ($detected['webform']['form']['id'] ?? $this->configFactory->get('smoke.settings')->get('webform_id') ?? 'smoke_test');
        $this->io()->text("      <fg=#888>Submissions</>   <fg=#5C9EE8>{$baseUrl}/admin/structure/webform/manage/{$webformId}/results/submissions</>");
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
   * @param bool $quickMode
   *   If TRUE, only run quick mode suites (core_pages, auth).
   * @param string|null $junitPath
   *   If set, write JUnit XML to this file path.
   * @param string|null $htmlPath
   *   If set, write HTML report to this directory.
   * @param bool $parallel
   *   If TRUE, run test suites in parallel.
   * @param bool $verbose
   *   If TRUE, show detailed test output.
   * @param string|null $suiteFilter
   *   Comma-separated list of suites to run.
   */
  private function runTests(?string $targetUrl = NULL, ?array $remoteCredentials = NULL, bool $quickMode = FALSE, ?string $junitPath = NULL, ?string $htmlPath = NULL, bool $parallel = FALSE, bool $verbose = FALSE, ?string $suiteFilter = NULL): void {
    if (!$this->testRunner->isSetup()) {
      $isDdev = getenv('IS_DDEV_PROJECT') === 'true';

      if ($isDdev) {
        $this->io()->text('  <fg=cyan>Setting up Playwright (first run)...</>');
        $this->io()->newLine();
        $process = new Process(
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
    $siteConfig = $this->configFactory->get('system.site');
    $siteName = (string) $siteConfig->get('name');
    $displayUrl = $targetUrl ?: (getenv('DDEV_PRIMARY_URL') ?: 'unknown');
    $hasTerminus = $remoteCredentials !== NULL;

    $this->io()->newLine();
    $this->printLogo();
    $this->io()->text("   <fg=white;options=bold>{$siteName}</>");
    $this->io()->text("   <fg=gray>{$displayUrl}</>");
    if ($targetUrl && $hasTerminus) {
      $this->io()->text("   <fg=magenta>◆</> <fg=magenta;options=bold>REMOTE + TERMINUS</>");
    }
    elseif ($targetUrl) {
      $this->io()->text("   <fg=magenta>◆</> <fg=magenta;options=bold>REMOTE</>");
    }
    $this->io()->text('   <fg=#555>─────────────────────────────────────────────────</>');
    $this->io()->newLine();

    // Determine which suites to run.
    $detected = $this->moduleDetector->detect();
    $settings = $this->configFactory->get('smoke.settings');
    $enabledSuites = $settings->get('suites') ?? [];
    $labels = ModuleDetector::suiteLabels();

    $suitesToRun = [];
    foreach ($detected as $id => $info) {
      $enabled = $enabledSuites[$id] ?? TRUE;
      if ($enabled && ($info['detected'] ?? FALSE)) {
        $suitesToRun[] = $id;
      }
    }

    // Quick mode: filter to only essential suites.
    if ($quickMode) {
      $suitesToRun = array_filter($suitesToRun, fn($id) => in_array($id, SmokeConstants::QUICK_MODE_SUITES, TRUE));
      $suitesToRun = array_values($suitesToRun);
      $this->io()->text('  <fg=yellow;options=bold>QUICK MODE</> — Running only core_pages and auth suites.');
      $this->io()->newLine();
    }

    // Suite filter: run only specified suites.
    if ($suiteFilter) {
      $requestedSuites = array_map('trim', explode(',', $suiteFilter));
      $validSuites = array_keys($labels);
      $invalidSuites = array_diff($requestedSuites, $validSuites);
      if (!empty($invalidSuites)) {
        $this->io()->warning('Unknown suites: ' . implode(', ', $invalidSuites));
        $this->io()->text('  Available: ' . implode(', ', $validSuites));
        $this->io()->newLine();
      }
      $suitesToRun = array_filter($suitesToRun, fn($id) => in_array($id, $requestedSuites, TRUE));
      $suitesToRun = array_values($suitesToRun);
      $this->io()->text('  <fg=cyan;options=bold>SUITE FILTER</> — Running: ' . implode(', ', $suitesToRun));
      $this->io()->newLine();
    }

    // Show mode indicators.
    $modeIndicators = [];
    if ($parallel) {
      $modeIndicators[] = '<fg=cyan;options=bold>PARALLEL</>';
    }
    if ($verbose) {
      $modeIndicators[] = '<fg=cyan;options=bold>VERBOSE</>';
    }
    if ($htmlPath) {
      $modeIndicators[] = '<fg=cyan;options=bold>HTML</>';
    }
    if (!empty($modeIndicators)) {
      $this->io()->text('  ' . implode(' + ', $modeIndicators) . ' mode enabled');
      $this->io()->newLine();
    }

    $totalSuites = count($suitesToRun);
    if ($totalSuites === 0) {
      $this->io()->warning('No test suites detected.');
      return;
    }

    // Clear previous results for a clean full run.
    $this->state->set('smoke.last_results', []);

    // Configure progress bar.
    $format = "   <fg=#FFB347>▸</> %message:-18s%  %bar%  <fg=#888>%current%/%max%</>";
    ProgressBar::setFormatDefinition('smoke', $format);
    $progress = new ProgressBar($this->io(), $totalSuites);
    $progress->setFormat('smoke');
    $progress->setBarCharacter('<fg=#98D8AA>█</>');
    $progress->setEmptyBarCharacter('<fg=#333>░</>');
    $progress->setProgressCharacter('<fg=#FFB347>▸</>');
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
      $results = $this->testRunner->run(
        $suiteId,
        $targetUrl,
        $remoteCredentials,
        [
          'parallel' => $parallel,
          'verbose' => $verbose,
          'htmlPath' => $htmlPath,
        ],
      );

      // If Playwright failed to launch (e.g. missing Chromium deps), stop.
      if (!empty($results['error'])) {
        $progress->clear();
        $this->io()->newLine();
        $this->io()->error($results['error']);
        $this->io()->text('  Run <options=bold>ddev drush smoke:setup</> or install browser deps:');
        $this->io()->text('  <options=bold>ddev exec "sudo npx playwright install-deps chromium"</>');
        $this->io()->newLine();
        return;
      }

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

      if ($failed > 0) {
        $badge = "<fg=#FF6B6B>✕ {$failed} failed</>";
      }
      elseif ($passed > 0) {
        $badge = "<fg=#98D8AA>✓ {$passed} passed</>";
      }
      else {
        $badge = "<fg=#FFD700>○ skipped</>";
      }
      $paddedLabel = str_pad($label, 18);
      $this->io()->text("   {$paddedLabel}{$badge}  <fg=#666>{$time}s</>");

      // Show individual failed tests inline.
      if ($failed > 0) {
        foreach (($suiteResult['tests'] ?? []) as $test) {
          if (($test['status'] ?? '') === 'failed') {
            $testTime = number_format(($test['duration'] ?? 0) / 1000, 1);
            $this->io()->text("      <fg=#FF6B6B>└──</> <fg=#FF8C8C>{$test['title']}</>  <fg=#666>{$testTime}s</>");
            if (!empty($test['error'])) {
              $error = (string) preg_replace('/\x1b\[[0-9;]*m/', '', $test['error']);
              $errorLines = explode("\n", substr($error, 0, 300));
              foreach (array_slice($errorLines, 0, 2) as $line) {
                if (trim($line)) {
                  $this->io()->text("          <fg=#888>" . trim($line) . "</>");
                }
              }
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
    $this->io()->text('   <fg=#555>─────────────────────────────────────────────────</>');

    if ($totalFailed === 0 && $totalPassed > 0) {
      $this->printSuccessBanner($totalPassed, $totalDuration);
    }
    elseif ($totalFailed > 0) {
      $this->printFailureBanner($totalPassed, $totalFailed, $totalDuration);
    }
    else {
      $this->io()->newLine();
      $this->io()->text('   <fg=yellow>⚠  No tests ran.</>');
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
        $webformId = (string) ($this->configFactory->get('smoke.settings')->get('webform_id') ?? 'smoke_test');
        $this->io()->text("  <fg=gray>Submissions:</>     {$baseUrl}/admin/structure/webform/manage/{$webformId}/results/submissions");
      }
    }

    // JUnit XML output for CI integration.
    if ($junitPath) {
      $allResults = $this->testRunner->getLastResults();
      $siteConfig = $this->configFactory->get('system.site');
      $siteName = (string) $siteConfig->get('name');
      $suiteName = 'Smoke Tests - ' . $siteName;

      if ($this->junitReporter->writeToFile($allResults, $junitPath, $suiteName)) {
        $this->io()->newLine();
        $this->io()->text("  <fg=cyan>JUnit XML:</> {$junitPath}");
      }
      else {
        $this->io()->newLine();
        $this->io()->text("  <fg=red>Failed to write JUnit XML to:</> {$junitPath}");
      }
    }

    // HTML report output.
    if ($htmlPath) {
      $this->io()->newLine();
      $this->io()->text("  <fg=cyan>HTML Report:</> {$htmlPath}/index.html");
      $this->io()->text("  <fg=gray>Open:</> npx playwright show-report {$htmlPath}");
    }

    $this->io()->newLine();
  }

  /**
   * Runs tests in watch mode, re-running when files change.
   *
   * @param string|null $targetUrl
   *   Optional remote URL to test against.
   * @param array<string, string>|null $remoteCredentials
   *   Optional remote auth credentials from Terminus.
   * @param bool $quickMode
   *   If TRUE, only run quick mode suites.
   * @param string|null $junitPath
   *   If set, write JUnit XML to this file path.
   * @param string|null $htmlPath
   *   If set, write HTML report to this directory.
   * @param bool $parallel
   *   If TRUE, run tests in parallel.
   * @param bool $verbose
   *   If TRUE, show detailed output.
   * @param string|null $suiteFilter
   *   Comma-separated list of suites to run.
   */
  private function runWatchMode(?string $targetUrl, ?array $remoteCredentials, bool $quickMode, ?string $junitPath, ?string $htmlPath, bool $parallel, bool $verbose, ?string $suiteFilter): void {
    $this->io()->newLine();
    $this->io()->text('  <fg=cyan;options=bold>WATCH MODE</> — Watching for file changes...');
    $this->io()->text('  <fg=gray>Press Ctrl+C to stop.</>');
    $this->io()->newLine();

    // Build the playwright test command with watch mode.
    $playwrightDir = $this->getPlaywrightDir();
    if (!$playwrightDir) {
      $this->io()->error('Cannot locate Playwright directory.');
      return;
    }

    $args = ['npx', 'playwright', 'test', '--ui'];

    // Build environment variables.
    $env = $_ENV;
    if ($parallel) {
      $env['SMOKE_PARALLEL'] = '1';
    }
    if ($verbose) {
      $env['SMOKE_VERBOSE'] = '1';
    }
    if ($htmlPath) {
      $env['SMOKE_HTML_PATH'] = $htmlPath;
    }

    // Filter to specific spec files if suite filter provided.
    if ($suiteFilter) {
      $requestedSuites = array_map('trim', explode(',', $suiteFilter));
      foreach ($requestedSuites as $suite) {
        $suiteFile = str_replace('_', '-', $suite);
        $args[] = 'suites/' . $suiteFile . '.spec.ts';
      }
    }
    elseif ($quickMode) {
      foreach (SmokeConstants::QUICK_MODE_SUITES as $suite) {
        $suiteFile = str_replace('_', '-', $suite);
        $args[] = 'suites/' . $suiteFile . '.spec.ts';
      }
    }

    $this->io()->text('  <fg=gray>Running: ' . implode(' ', $args) . '</>');
    $this->io()->newLine();

    $process = new Process($args, $playwrightDir, $env);
    $process->setTimeout(0); // No timeout for interactive mode.
    $process->setTty(Process::isTtySupported());
    $process->run(function ($type, $buffer): void {
      $this->io()->write($buffer);
    });
  }

  /**
   * Gets the Playwright directory path.
   */
  private function getPlaywrightDir(): ?string {
    $modulePath = $this->moduleHandler->getModule('smoke')->getPath();
    $playwrightDir = DRUPAL_ROOT . '/' . $modulePath . '/playwright';
    return is_dir($playwrightDir) ? $playwrightDir : NULL;
  }

  /**
   * Checks if webform results exist.
   */
  private function hasWebformResults(array $suites): bool {
    return isset($suites['webform']) && (($suites['webform']['passed'] ?? 0) + ($suites['webform']['failed'] ?? 0)) > 0;
  }

  /**
   * Shows a one-time tip about global Playwright installation for agencies.
   */
  private function showAgencyTipIfNeeded(): void {
    // Only show once per project.
    $markerFile = DRUPAL_ROOT . '/../.ddev/.smoke-agency-tip-shown';
    if (is_file($markerFile)) {
      return;
    }

    // Check if using global Playwright (environment variable set).
    $globalPath = getenv('PLAYWRIGHT_BROWSERS_PATH');
    if ($globalPath && is_dir($globalPath)) {
      // Already using global — no tip needed.
      return;
    }

    // Show the tip.
    $this->io()->newLine();
    $this->io()->text('  <fg=cyan;options=bold>Tip: Managing multiple Drupal sites?</>');
    $this->io()->text('  <fg=gray>Save ~180 MiB per project by installing Playwright globally:</>');
    $modulePath = $this->moduleHandler->getModule('smoke')->getPath();
    $this->io()->text("  <options=bold>bash web/{$modulePath}/scripts/global-setup.sh</>");
    $this->io()->text('  <fg=gray>Enables VS Code/Cursor extension support across all sites.</>');

    // Mark as shown.
    @file_put_contents($markerFile, date('c'));
  }

  /**
   * Prints the Smoke ASCII logo.
   */
  private function printLogo(): void {
    $this->io()->text('<fg=#888>   ┌─────────────────────────────────────────────────┐</>');
    $this->io()->text('<fg=#888>   │</>  <fg=#FF6B35>███████╗</><fg=#FF8C42>███╗   ███╗</><fg=#FFB347> ██████╗ </><fg=#FFD700>██╗  ██╗</><fg=#98D8AA>███████╗</>  <fg=#888>│</>');
    $this->io()->text('<fg=#888>   │</>  <fg=#FF6B35>██╔════╝</><fg=#FF8C42>████╗ ████║</><fg=#FFB347>██╔═══██╗</><fg=#FFD700>██║ ██╔╝</><fg=#98D8AA>██╔════╝</>  <fg=#888>│</>');
    $this->io()->text('<fg=#888>   │</>  <fg=#FF6B35>███████╗</><fg=#FF8C42>██╔████╔██║</><fg=#FFB347>██║   ██║</><fg=#FFD700>█████╔╝ </><fg=#98D8AA>█████╗</>    <fg=#888>│</>');
    $this->io()->text('<fg=#888>   │</>  <fg=#FF6B35>╚════██║</><fg=#FF8C42>██║╚██╔╝██║</><fg=#FFB347>██║   ██║</><fg=#FFD700>██╔═██╗ </><fg=#98D8AA>██╔══╝</>    <fg=#888>│</>');
    $this->io()->text('<fg=#888>   │</>  <fg=#FF6B35>███████║</><fg=#FF8C42>██║ ╚═╝ ██║</><fg=#FFB347>╚██████╔╝</><fg=#FFD700>██║  ██╗</><fg=#98D8AA>███████╗</>  <fg=#888>│</>');
    $this->io()->text('<fg=#888>   │</>  <fg=#FF6B35>╚══════╝</><fg=#FF8C42>╚═╝     ╚═╝</><fg=#FFB347> ╚═════╝ </><fg=#FFD700>╚═╝  ╚═╝</><fg=#98D8AA>╚══════╝</>  <fg=#888>│</>');
    $this->io()->text('<fg=#888>   └─────────────────────────────────────────────────┘</>');
  }

  /**
   * Prints the success banner.
   */
  private function printSuccessBanner(int $passed, string $duration): void {
    $this->io()->newLine();
    $this->io()->text('   <fg=#98D8AA>╔═══════════════════════════════════════════════╗</>');
    $this->io()->text('   <fg=#98D8AA>║</>                                               <fg=#98D8AA>║</>');
    $this->io()->text("   <fg=#98D8AA>║</>   <fg=#98D8AA;options=bold>✓  ALL TESTS PASSED</>                         <fg=#98D8AA>║</>");
    $this->io()->text("   <fg=#98D8AA>║</>   <fg=white>{$passed} passed</> in <fg=white>{$duration}s</>                         <fg=#98D8AA>║</>");
    $this->io()->text('   <fg=#98D8AA>║</>                                               <fg=#98D8AA>║</>');
    $this->io()->text('   <fg=#98D8AA>╚═══════════════════════════════════════════════╝</>');
  }

  /**
   * Prints the failure banner.
   */
  private function printFailureBanner(int $passed, int $failed, string $duration): void {
    $this->io()->newLine();
    $this->io()->text('   <fg=#FF6B6B>╔═══════════════════════════════════════════════╗</>');
    $this->io()->text('   <fg=#FF6B6B>║</>                                               <fg=#FF6B6B>║</>');
    $this->io()->text("   <fg=#FF6B6B>║</>   <fg=#FF6B6B;options=bold>✕  TESTS FAILED</>                             <fg=#FF6B6B>║</>");
    $this->io()->text("   <fg=#FF6B6B>║</>   <fg=white>{$passed} passed</>, <fg=#FF6B6B>{$failed} failed</> in <fg=white>{$duration}s</>               <fg=#FF6B6B>║</>");
    $this->io()->text('   <fg=#FF6B6B>║</>                                               <fg=#FF6B6B>║</>');
    $this->io()->text('   <fg=#FF6B6B>╚═══════════════════════════════════════════════╝</>');
  }

}
