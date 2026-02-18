<?php

declare(strict_types=1);

namespace Drupal\smoke\Service;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\State\StateInterface;
use Symfony\Component\Process\Process;

/**
 * Executes Playwright tests and parses results.
 */
final class TestRunner {

  public function __construct(
    private readonly ConfigGenerator $configGenerator,
    private readonly StateInterface $state,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly FileSystemInterface $fileSystem,
  ) {}

  /**
   * Checks whether the Playwright environment is set up.
   */
  public function isSetup(): bool {
    $playwrightDir = $this->getPlaywrightDir();
    return is_dir($playwrightDir . '/node_modules');
  }

  /**
   * Runs all enabled test suites.
   *
   * @param string|null $suite
   *   Optional single suite to run.
   * @param string|null $targetUrl
   *   Optional remote URL to test against instead of the local DDEV site.
   * @param array<string, string>|null $remoteCredentials
   *   Optional remote auth credentials from Terminus.
   * @param array<string, mixed> $options
   *   Additional options: parallel, verbose, htmlPath.
   *
   * @return array<string, mixed>
   *   Parsed test results.
   */
  public function run(
    ?string $suite = NULL,
    ?string $targetUrl = NULL,
    ?array $remoteCredentials = NULL,
    array $options = [],
  ): array {
    // Check Node.js version before running tests.
    $nodeVersionError = $this->checkNodeVersion();
    if ($nodeVersionError !== NULL) {
      return [
        'error' => $nodeVersionError,
        'exitCode' => 1,
        'ranAt' => time(),
        'suites' => [],
        'passed' => 0,
        'failed' => 0,
        'skipped' => 0,
        'total' => 0,
      ];
    }

    // Write fresh config for Playwright (optional remote URL and credentials).
    $this->configGenerator->writeConfig($targetUrl, $remoteCredentials);

    $playwrightDir = $this->getPlaywrightDir();
    $resultsFile = $playwrightDir . '/results.json';

    // Remove stale results file.
    if (file_exists($resultsFile)) {
      @unlink($resultsFile);
    }

    // Don't pass --reporter; config writes JSON. CLI would override.
    $args = [
      'npx', 'playwright', 'test',
    ];

    if ($suite) {
      // Suite IDs use underscores (core_pages), filenames use dashes.
      $suiteFile = str_replace('_', '-', $suite);
      $args[] = 'suites/' . $suiteFile . '.spec.ts';
    }

    // Build environment variables for Playwright config.
    $env = [];
    if (!empty($options['parallel'])) {
      $env['SMOKE_PARALLEL'] = '1';
    }
    if (!empty($options['verbose'])) {
      $env['SMOKE_VERBOSE'] = '1';
    }
    if (!empty($options['htmlPath'])) {
      $env['SMOKE_HTML_PATH'] = $options['htmlPath'];
    }

    $process = new Process($args, $playwrightDir, $env + $_ENV);
    $process->setTimeout(300);

    // Output enabled so launch failures show. Results from JSON file.
    $process->run();

    // If process failed and no results, surface stderr (e.g. launch message).
    $resultsFileContent = file_exists($resultsFile)
      ? (file_get_contents($resultsFile) ?: '')
      : '';
    $err = $process->getErrorOutput();
    
    if ($process->getExitCode() !== 0 && $resultsFileContent === '') {
      if ($err !== '') {
        // Check if this is a browser launch failure we can recover from.
        $isLaunchFailure = str_contains($err, 'browserType.launch')
          || str_contains($err, 'Failed to launch')
          || str_contains($err, 'Executable doesn\'t exist')
          || str_contains($err, 'Host system is missing dependencies');

        if ($isLaunchFailure && !($options['_retried'] ?? FALSE)) {
          // Attempt auto-recovery: install browser deps and retry once.
          $this->attemptBrowserRecovery($playwrightDir);
          $options['_retried'] = TRUE;
          return $this->run($suite, $targetUrl, $remoteCredentials, $options);
        }

        $results = $this->parseResults('');
        $results['error'] = 'Playwright failed. ' . trim($err);
        $results['exitCode'] = $process->getExitCode();
        $results['ranAt'] = time();
        $this->state->set('smoke.last_results', $results);
        $this->state->set('smoke.last_run', $results['ranAt']);
        return $results;
      }
    }

    // Read results from the file written by Playwright's JSON reporter.
    $output = file_exists($resultsFile)
      ? (file_get_contents($resultsFile) ?: '')
      : '';
    $results = $this->parseResults($output);
    $results['exitCode'] = $process->getExitCode();
    $results['ranAt'] = time();

    // Browser launch failure: check both stderr and test results.
    $launchErrorFromStderr = $err !== ''
      && ($process->getExitCode() !== 0)
      && (str_contains($err, 'browserType.launch')
        || str_contains($err, 'Failed to launch')
        || str_contains($err, 'Host system is missing dependencies'));
    $launchFailureInTests = $this->hasBrowserLaunchFailureInResults($results);

    if (($launchErrorFromStderr || $launchFailureInTests) && !($options['_retried'] ?? FALSE)) {
      // Attempt auto-recovery and retry once.
      $this->attemptBrowserRecovery($playwrightDir);
      $options['_retried'] = TRUE;
      return $this->run($suite, $targetUrl, $remoteCredentials, $options);
    }

    // Set error message for display if browser launch failed.
    if ($launchErrorFromStderr) {
      $results['error'] = trim($err);
    }
    elseif ($launchFailureInTests) {
      $results['error'] = trim($err) !== ''
        ? trim($err)
        : 'Chromium could not be launched. Install browser and system deps.';
    }

    if ($suite) {
      // Merge with existing results for other suites.
      $existing = $this->state->get('smoke.last_results', []);
      $existing['suites'][$suite] = $results['suites'][$suite] ?? [];
      $existing['ranAt'] = $results['ranAt'];
      $existing['exitCode'] = $results['exitCode'];
      $this->recalculateSummary($existing);
      $this->state->set('smoke.last_results', $existing);
    }
    else {
      $this->state->set('smoke.last_results', $results);
    }

    $this->state->set('smoke.last_run', $results['ranAt']);

    return $results;
  }

  /**
   * Returns the last stored results.
   *
   * @return array<string, mixed>|null
   *   The last test results, or NULL if none.
   */
  public function getLastResults(): ?array {
    return $this->state->get('smoke.last_results');
  }

  /**
   * Returns the timestamp of the last run.
   *
   * @return int|null
   *   Unix timestamp of last run, or NULL.
   */
  public function getLastRunTime(): ?int {
    return $this->state->get('smoke.last_run');
  }

  /**
   * Parses Playwright JSON reporter output into a structured result.
   */
  private function parseResults(string $jsonOutput): array {
    $data = @json_decode($jsonOutput, TRUE);

    if (!$data) {
      return [
        'suites' => [],
        'summary' => [
          'total' => 0,
          'passed' => 0,
          'failed' => 0,
          'skipped' => 0,
          'duration' => 0,
        ],
        'error' => 'Failed to parse Playwright output.',
        'raw' => mb_substr($jsonOutput, 0, 2000),
      ];
    }

    $suites = [];
    $totalPassed = 0;
    $totalFailed = 0;
    $totalSkipped = 0;
    $totalDuration = 0;

    // Playwright JSON format: { suites: [ { title, specs: [ ... ] } ] }.
    foreach (($data['suites'] ?? []) as $pwSuite) {
      $suiteId = $this->resolveSuiteId($pwSuite['title'] ?? '');
      if (!$suiteId) {
        $suiteId = $this->slugify($pwSuite['title'] ?? 'unknown');
      }

      $tests = [];
      $suitePassed = 0;
      $suiteFailed = 0;
      $suiteSkipped = 0;
      $suiteDuration = 0;

      foreach ($this->flattenSpecs($pwSuite) as $spec) {
        $status = 'passed';
        $duration = 0;
        $errorMessage = '';

        foreach (($spec['tests'] ?? []) as $test) {
          foreach (($test['results'] ?? []) as $result) {
            $duration += (int) ($result['duration'] ?? 0);
            if (($result['status'] ?? '') === 'failed') {
              $status = 'failed';
              $errorMessage = $result['error']['message'] ?? '';
            }
            elseif (($result['status'] ?? '') === 'skipped') {
              $status = 'skipped';
            }
          }
        }

        if ($status === 'passed') {
          $suitePassed++;
        }
        elseif ($status === 'failed') {
          $suiteFailed++;
        }
        else {
          $suiteSkipped++;
        }
        $suiteDuration += $duration;

        $tests[] = [
          'title' => $spec['title'] ?? 'Unknown test',
          'status' => $status,
          'duration' => $duration,
          'error' => $errorMessage,
        ];
      }

      $totalPassed += $suitePassed;
      $totalFailed += $suiteFailed;
      $totalSkipped += $suiteSkipped;
      $totalDuration += $suiteDuration;

      $suites[$suiteId] = [
        'title' => $pwSuite['title'] ?? $suiteId,
        'tests' => $tests,
        'passed' => $suitePassed,
        'failed' => $suiteFailed,
        'skipped' => $suiteSkipped,
        'duration' => $suiteDuration,
        'status' => $suiteFailed > 0 ? 'failed' : 'passed',
      ];
    }

    return [
      'suites' => $suites,
      'summary' => [
        'total' => $totalPassed + $totalFailed + $totalSkipped,
        'passed' => $totalPassed,
        'failed' => $totalFailed,
        'skipped' => $totalSkipped,
        'duration' => $totalDuration,
      ],
    ];
  }

  /**
   * Returns TRUE if any failed test in results has a browserType.launch error.
   */
  private function hasBrowserLaunchFailureInResults(array $results): bool {
    foreach ($results['suites'] ?? [] as $suite) {
      foreach ($suite['tests'] ?? [] as $test) {
        if (($test['status'] ?? '') === 'failed' && isset($test['error'])) {
          if (str_contains((string) $test['error'], 'browserType.launch')) {
            return TRUE;
          }
        }
      }
    }
    return FALSE;
  }

  /**
   * Recursively flatten specs from nested suites.
   */
  private function flattenSpecs(array $suite): array {
    $specs = $suite['specs'] ?? [];
    foreach (($suite['suites'] ?? []) as $child) {
      $specs = array_merge($specs, $this->flattenSpecs($child));
    }
    return $specs;
  }

  /**
   * Maps a Playwright suite title back to a known suite ID.
   *
   * Handles both file-based titles ("core-pages.spec.ts") and
   * describe-block titles ("Core Pages").
   */
  private function resolveSuiteId(string $title): ?string {
    $map = [
      'core-pages' => 'core_pages',
      'Core Pages' => 'core_pages',
      'core-pages.spec.ts' => 'core_pages',
      'auth' => 'auth',
      'Authentication' => 'auth',
      'auth.spec.ts' => 'auth',
      'webform' => 'webform',
      'Webform' => 'webform',
      'webform.spec.ts' => 'webform',
      'commerce' => 'commerce',
      'Commerce' => 'commerce',
      'commerce.spec.ts' => 'commerce',
      'search' => 'search',
      'Search' => 'search',
      'search.spec.ts' => 'search',
      'health' => 'health',
      'Health' => 'health',
      'health.spec.ts' => 'health',
      'sitemap' => 'sitemap',
      'Sitemap' => 'sitemap',
      'sitemap.spec.ts' => 'sitemap',
      'content' => 'content',
      'Content' => 'content',
      'content.spec.ts' => 'content',
      'accessibility' => 'accessibility',
      'Accessibility' => 'accessibility',
      'accessibility.spec.ts' => 'accessibility',
    ];
    return $map[$title] ?? NULL;
  }

  /**
   * Converts a string to a slug.
   */
  private function slugify(string $text): string {
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '_', $text) ?? $text;
    return trim($text, '_');
  }

  /**
   * Recalculates summary from suite data.
   */
  private function recalculateSummary(array &$results): void {
    $passed = 0;
    $failed = 0;
    $skipped = 0;
    $duration = 0;
    foreach (($results['suites'] ?? []) as $suite) {
      $passed += (int) ($suite['passed'] ?? 0);
      $failed += (int) ($suite['failed'] ?? 0);
      $skipped += (int) ($suite['skipped'] ?? 0);
      $duration += (int) ($suite['duration'] ?? 0);
    }
    $results['summary'] = [
      'total' => $passed + $failed + $skipped,
      'passed' => $passed,
      'failed' => $failed,
      'skipped' => $skipped,
      'duration' => $duration,
    ];
  }

  /**
   * Returns the Playwright directory path inside this module.
   */
  private function getPlaywrightDir(): string {
    return $this->configGenerator->getModulePath() . '/playwright';
  }

  /**
   * Attempts to recover from browser launch failure by installing deps.
   *
   * @param string $playwrightDir
   *   Path to the Playwright directory.
   *
   * @return bool
   *   TRUE if recovery was attempted and may have succeeded.
   */
  private function attemptBrowserRecovery(string $playwrightDir): bool {
    // Try installing Chromium browser first.
    $installBrowser = new Process(
      ['npx', 'playwright', 'install', 'chromium'],
      $playwrightDir,
    );
    $installBrowser->setTimeout(180);
    $installBrowser->run();

    // Then try to install system dependencies.
    // Use sudo with DEBIAN_FRONTEND=noninteractive to avoid prompts.
    $installDeps = new Process(
      ['sudo', '-n', 'env', 'DEBIAN_FRONTEND=noninteractive', 'npx', 'playwright', 'install-deps', 'chromium'],
      $playwrightDir,
    );
    $installDeps->setTimeout(120);
    $installDeps->run();

    // If that failed, try direct apt-get as fallback.
    if (!$installDeps->isSuccessful()) {
      // Comprehensive list of Chromium dependencies.
      $aptInstall = new Process(
        ['sudo', '-n', 'apt-get', 'install', '-y',
          'libnss3', 'libnspr4', 'libatk1.0-0', 'libatk-bridge2.0-0',
          'libcups2', 'libdrm2', 'libxkbcommon0', 'libxcomposite1',
          'libxdamage1', 'libxfixes3', 'libxrandr2', 'libgbm1', 'libasound2',
          'libpangocairo-1.0-0', 'libpango-1.0-0', 'libcairo2',
          'libatspi2.0-0', 'libgtk-3-0', 'libgdk-pixbuf2.0-0',
          'libx11-xcb1', 'libxcb-dri3-0', 'libxcb1', 'libxshmfence1',
          'libglib2.0-0', 'libnss3-tools',
        ],
        $playwrightDir,
      );
      $aptInstall->setTimeout(120);
      $aptInstall->run();
    }

    // Return true to signal retry should be attempted.
    return TRUE;
  }

  /**
   * Checks if Node.js version is >= 18.
   *
   * @return string|null
   *   Error message if Node.js is missing or too old, NULL if OK.
   */
  private function checkNodeVersion(): ?string {
    $nodeCheck = new Process(['node', '--version']);
    $nodeCheck->setTimeout(10);
    $nodeCheck->run();

    if (!$nodeCheck->isSuccessful()) {
      return 'Node.js is not installed. Install Node.js 18+ to run Playwright tests.';
    }

    $nodeVersion = trim($nodeCheck->getOutput());
    if (preg_match('/^v?(\d+)\./', $nodeVersion, $matches)) {
      $majorVersion = (int) $matches[1];
      if ($majorVersion < 18) {
        return "Node.js $nodeVersion is too old. Playwright requires Node.js 18+. Upgrade at https://nodejs.org/";
      }
    }

    return NULL;
  }

}
