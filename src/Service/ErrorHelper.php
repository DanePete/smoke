<?php

declare(strict_types=1);

namespace Drupal\smoke\Service;

use Drupal\smoke\SmokeConstants;

/**
 * Provides structured error messages with actionable hints.
 */
final class ErrorHelper {

  /**
   * Error patterns and their solutions.
   *
   * @var array<string, array{message: string, hint: string, code: string}>
   */
  private const ERROR_PATTERNS = [
    'browserType.launch' => [
      'message' => 'Chromium browser could not be launched.',
      'hint' => 'Install browser dependencies with: ddev exec "sudo npx playwright install-deps chromium"',
      'code' => SmokeConstants::ERROR_BROWSER_LAUNCH_FAILED,
    ],
    'Failed to launch' => [
      'message' => 'Chromium browser failed to launch.',
      'hint' => 'Run: ddev drush smoke:setup to reinstall the browser.',
      'code' => SmokeConstants::ERROR_BROWSER_LAUNCH_FAILED,
    ],
    'ENOENT' => [
      'message' => 'Playwright or Node.js executable not found.',
      'hint' => 'Ensure Node.js is installed and run: ddev drush smoke:setup',
      'code' => SmokeConstants::ERROR_PLAYWRIGHT_NOT_SETUP,
    ],
    'Cannot find module' => [
      'message' => 'Playwright dependencies are not installed.',
      'hint' => 'Run: ddev drush smoke:setup to install npm dependencies.',
      'code' => SmokeConstants::ERROR_PLAYWRIGHT_NOT_SETUP,
    ],
    'ETIMEDOUT' => [
      'message' => 'Test timed out waiting for the page.',
      'hint' => 'The site may be slow or unresponsive. Check if DDEV is running: ddev describe',
      'code' => SmokeConstants::ERROR_TIMEOUT,
    ],
    'Timeout' => [
      'message' => 'Test exceeded the configured timeout.',
      'hint' => 'Increase timeout in Settings or check site performance.',
      'code' => SmokeConstants::ERROR_TIMEOUT,
    ],
    'ECONNREFUSED' => [
      'message' => 'Could not connect to the site.',
      'hint' => 'Ensure DDEV is running: ddev start',
      'code' => SmokeConstants::ERROR_TIMEOUT,
    ],
    'net::ERR_CONNECTION_REFUSED' => [
      'message' => 'Browser could not reach the site.',
      'hint' => 'Ensure DDEV is running and the site is accessible: ddev launch',
      'code' => SmokeConstants::ERROR_TIMEOUT,
    ],
    '.smoke-config.json' => [
      'message' => 'Smoke test configuration file is missing.',
      'hint' => 'Run: ddev drush smoke:setup to generate the config.',
      'code' => SmokeConstants::ERROR_CONFIG_MISSING,
    ],
    'libnss3' => [
      'message' => 'System library libnss3 is missing.',
      'hint' => 'Run: ddev exec "sudo npx playwright install-deps chromium"',
      'code' => SmokeConstants::ERROR_BROWSER_LAUNCH_FAILED,
    ],
    'libatk' => [
      'message' => 'System library libatk is missing.',
      'hint' => 'Run: ddev exec "sudo npx playwright install-deps chromium"',
      'code' => SmokeConstants::ERROR_BROWSER_LAUNCH_FAILED,
    ],
  ];

  /**
   * Analyzes an error message and returns a structured error response.
   *
   * @param string $rawError
   *   The raw error message from Playwright or the process.
   * @param int $exitCode
   *   The process exit code.
   *
   * @return array{message: string, hint: string, code: string, raw: string}
   *   Structured error with message, hint, code, and raw error.
   */
  public function analyze(string $rawError, int $exitCode = 1): array {
    // Clean ANSI codes from the error.
    $cleanError = (string) preg_replace('/\x1b\[[0-9;]*m/', '', $rawError);

    foreach (self::ERROR_PATTERNS as $pattern => $info) {
      if (str_contains($cleanError, $pattern)) {
        return [
          'message' => $info['message'],
          'hint' => $info['hint'],
          'code' => $info['code'],
          'raw' => $this->truncate($cleanError, 500),
        ];
      }
    }

    // Default error for unrecognized patterns.
    return [
      'message' => $this->extractFirstLine($cleanError) ?: 'Playwright test execution failed.',
      'hint' => 'Check the raw error below. Run with verbose output: npx playwright test --debug',
      'code' => 'UNKNOWN_ERROR',
      'raw' => $this->truncate($cleanError, 500),
    ];
  }

  /**
   * Formats an error for CLI display.
   *
   * @param array{message: string, hint: string, code: string, raw?: string} $error
   *   The structured error.
   * @param bool $includeRaw
   *   Whether to include the raw error.
   *
   * @return string
   *   Formatted error string.
   */
  public function formatForCli(array $error, bool $includeRaw = FALSE): string {
    $lines = [];
    $lines[] = "[{$error['code']}] {$error['message']}";
    $lines[] = '';
    $lines[] = "Hint: {$error['hint']}";

    if ($includeRaw && !empty($error['raw'])) {
      $lines[] = '';
      $lines[] = 'Details:';
      $lines[] = $error['raw'];
    }

    return implode("\n", $lines);
  }

  /**
   * Extracts the first meaningful line from an error.
   *
   * @param string $error
   *   The raw error.
   *
   * @return string
   *   The first non-empty line.
   */
  private function extractFirstLine(string $error): string {
    $lines = explode("\n", $error);
    foreach ($lines as $line) {
      $line = trim($line);
      if ($line !== '' && !str_starts_with($line, 'at ')) {
        return $line;
      }
    }
    return '';
  }

  /**
   * Truncates a string to a maximum length.
   *
   * @param string $text
   *   The text to truncate.
   * @param int $maxLength
   *   Maximum length.
   *
   * @return string
   *   Truncated text.
   */
  private function truncate(string $text, int $maxLength): string {
    if (strlen($text) <= $maxLength) {
      return $text;
    }
    return substr($text, 0, $maxLength) . '...';
  }

  /**
   * Checks if an error indicates a setup problem.
   *
   * @param string $errorCode
   *   The error code.
   *
   * @return bool
   *   TRUE if this is a setup-related error.
   */
  public function isSetupError(string $errorCode): bool {
    return in_array($errorCode, [
      SmokeConstants::ERROR_PLAYWRIGHT_NOT_SETUP,
      SmokeConstants::ERROR_BROWSER_LAUNCH_FAILED,
      SmokeConstants::ERROR_CONFIG_MISSING,
    ], TRUE);
  }

}
