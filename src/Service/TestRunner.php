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
    private readonly SuiteDiscovery $suiteDiscovery,
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
   *
   * @return array<string, mixed>
   *   Parsed test results.
   */
  public function run(
    ?string $suite = NULL,
    ?string $targetUrl = NULL,
    ?array $remoteCredentials = NULL,
  ): array {
    $playwrightDir = $this->getPlaywrightDir();

    // Require Node 20+ before running Playwright.
    $nodeError = $this->checkNodeVersion($playwrightDir);
    if ($nodeError !== NULL) {
      $results = $this->parseResults('');
      $results['error'] = $nodeError;
      $results['exitCode'] = 1;
      $results['ranAt'] = time();
      $this->state->set('smoke.last_results', $results);
      $this->state->set('smoke.last_run', $results['ranAt']);
      return $results;
    }

    // Write fresh config for Playwright (optional remote URL and credentials).
    $this->configGenerator->writeConfig($targetUrl, $remoteCredentials);

    $resultsFile = $playwrightDir . '/results.json';

    // Remove stale results file.
    if (file_exists($resultsFile)) {
      @unlink($resultsFile);
    }

    // Don't pass --reporter; config writes JSON. CLI would override.
    $args = [
      'npx', 'playwright', 'test',
    ];

    $externalSuiteDir = NULL;
    if ($suite) {
      $specPath = $this->suiteDiscovery->getSpecPath($suite);
      if ($specPath !== NULL) {
        $isExternal = !str_starts_with($specPath, $playwrightDir);
        if ($isExternal) {
          // External suite: copy specs into smoke's suites/ so Playwright
          // discovers them naturally and imports (../../src/helpers) resolve
          // from smoke's tree. Cleaned up after the run.
          $suiteName = str_replace('_', '-', $suite);
          $externalSuiteDir = $playwrightDir . '/suites/' . $suiteName;
          $sourceDir = is_dir($specPath) ? $specPath : dirname($specPath);
          $this->copyDirectory($sourceDir, $externalSuiteDir);
          $args[] = 'suites/' . $suiteName;
        }
        else {
          $args[] = $specPath;
        }
      }
      else {
        $suiteFile = str_replace('_', '-', $suite);
        $args[] = 'suites/' . $suiteFile . '.spec.ts';
      }
    }

    $process = new Process($args, $playwrightDir);
    $process->setTimeout(300);

    // Output enabled so launch failures show. Results from JSON file.
    $process->run();

    // Clean up copied external suite.
    if ($externalSuiteDir !== NULL && is_dir($externalSuiteDir)) {
      $this->removeDirectory($externalSuiteDir);
    }

    // If process failed and no results, surface stderr (e.g. launch message).
    $resultsFileContent = file_exists($resultsFile)
      ? (file_get_contents($resultsFile) ?: '')
      : '';
    if ($process->getExitCode() !== 0 && $resultsFileContent === '') {
      $err = $process->getErrorOutput();
      if ($err !== '') {
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

    // When running a single suite, map parsed suite data to the requested ID.
    // Playwright may use describe-block titles or file names; custom/dir may
    // not match. Merge so results['suites'][$suite] exists.
    if ($suite !== NULL && $suite !== '' && empty($results['suites'][$suite])) {
      $results['suites'] = $this->mergeParsedSuitesIntoOne($results['suites'], $suite);
    }

    // Browser launch failure: set results['error'] so run command shows hint.
    $err = $process->getErrorOutput();
    $launchErrorFromStderr = $err !== ''
      && ($process->getExitCode() !== 0)
      && (str_contains($err, 'browserType.launch') || str_contains($err, 'Failed to launch'));
    if ($launchErrorFromStderr) {
      $results['error'] = trim($err);
    }
    else {
      $launchFailureInTests = $this->hasBrowserLaunchFailureInResults($results);
      if ($launchFailureInTests) {
        $results['error'] = trim($err) !== ''
          ? trim($err)
          : 'Chromium could not be launched. Install browser and system deps.';
      }
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
   * Merges parsed suite results into one (for single-suite runs).
   *
   * When running one suite (file or directory), Playwright may report
   * multiple suite titles (e.g. describe blocks). Merge so the requested
   * suite ID has the combined results.
   *
   * @param array<string, array<string, mixed>> $suites
   *   Parsed suites keyed by resolved/slugified ID.
   * @param string $targetId
   *   The requested suite ID to use as the key.
   *
   * @return array<string, array<string, mixed>>
   *   Merged suite keyed by $targetId.
   */
  private function mergeParsedSuitesIntoOne(array $suites, string $targetId): array {
    $merged = [
      'title' => $targetId,
      'tests' => [],
      'passed' => 0,
      'failed' => 0,
      'skipped' => 0,
      'duration' => 0,
      'status' => 'passed',
    ];
    foreach ($suites as $data) {
      $merged['passed'] += (int) ($data['passed'] ?? 0);
      $merged['failed'] += (int) ($data['failed'] ?? 0);
      $merged['skipped'] += (int) ($data['skipped'] ?? 0);
      $merged['duration'] += (int) ($data['duration'] ?? 0);
      foreach (($data['tests'] ?? []) as $test) {
        $merged['tests'][] = $test;
      }
    }
    if ($merged['failed'] > 0) {
      $merged['status'] = 'failed';
    }
    return [$targetId => $merged];
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
   * Checks that Node.js 20+ is available.
   *
   * @param string $playwrightDir
   *   Playwright directory (used as process cwd).
   *
   * @return string|null
   *   NULL if OK, or an error message to show the user.
   */
  private function checkNodeVersion(string $playwrightDir): ?string {
    $process = new Process(['node', '--version'], $playwrightDir);
    $process->setTimeout(10);
    $process->run();
    if (!$process->isSuccessful()) {
      return 'Node.js is not installed. Smoke requires Node.js 20+ (e.g. nvm use 20).';
    }
    $output = trim($process->getOutput());
    if (preg_match('/^v?(\d+)\./', $output, $matches)) {
      $major = (int) $matches[1];
      if ($major < 20) {
        return "Node.js {$output} is too old. Use Node 20 or newer (e.g. nvm use 20).";
      }
    }
    return NULL;
  }

  /**
   * Returns the Playwright directory path inside this module.
   */
  private function getPlaywrightDir(): string {
    return $this->configGenerator->getModulePath() . '/playwright';
  }

  /**
   * Recursively copies a directory.
   */
  private function copyDirectory(string $source, string $dest): void {
    if (!is_dir($dest)) {
      mkdir($dest, 0755, TRUE);
    }
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($iterator as $item) {
      $target = $dest . '/' . $iterator->getSubPathname();
      if ($item->isDir()) {
        if (!is_dir($target)) {
          mkdir($target, 0755, TRUE);
        }
      }
      else {
        copy($item->getPathname(), $target);
      }
    }
  }

  /**
   * Recursively removes a directory.
   */
  private function removeDirectory(string $dir): void {
    if (!is_dir($dir)) {
      return;
    }
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
      if ($item->isDir()) {
        @rmdir($item->getPathname());
      }
      else {
        @unlink($item->getPathname());
      }
    }
    @rmdir($dir);
  }

}
