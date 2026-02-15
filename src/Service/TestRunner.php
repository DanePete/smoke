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
   *
   * @return array<string, mixed>
   *   Parsed test results.
   */
  public function run(?string $suite = NULL): array {
    // Write fresh config for Playwright.
    $this->configGenerator->writeConfig();

    $playwrightDir = $this->getPlaywrightDir();
    $resultsFile = $playwrightDir . '/results.json';

    // Remove stale results file.
    if (file_exists($resultsFile)) {
      @unlink($resultsFile);
    }

    // Don't pass --reporter here; the playwright.config.ts already
    // writes JSON to results.json. Passing it on CLI would override
    // the config and send JSON to stdout, causing buffer deadlocks.
    $args = [
      'npx', 'playwright', 'test',
    ];

    if ($suite) {
      $args[] = 'suites/' . $suite . '.spec.ts';
    }

    $process = new Process($args, $playwrightDir);
    $process->setTimeout(300);

    // Disable in-memory output buffering to prevent deadlocks on large output.
    // We read results from the JSON file Playwright writes to disk instead.
    $process->disableOutput();

    // Playwright returns non-zero if tests fail, so we don't throw on failure.
    $process->run();

    // Read results from the file written by Playwright's JSON reporter.
    $output = file_exists($resultsFile) ? (file_get_contents($resultsFile) ?: '') : '';
    $results = $this->parseResults($output);
    $results['exitCode'] = $process->getExitCode();
    $results['ranAt'] = time();

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
   */
  public function getLastResults(): ?array {
    return $this->state->get('smoke.last_results');
  }

  /**
   * Returns the timestamp of the last run.
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

    // Playwright JSON format: { suites: [ { title, specs: [ ... ] } ] }
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
   */
  private function resolveSuiteId(string $title): ?string {
    $map = [
      'core-pages' => 'core_pages',
      'Core Pages' => 'core_pages',
      'auth' => 'auth',
      'Authentication' => 'auth',
      'webform' => 'webform',
      'Webform' => 'webform',
      'commerce' => 'commerce',
      'Commerce' => 'commerce',
      'search' => 'search',
      'Search' => 'search',
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

}
