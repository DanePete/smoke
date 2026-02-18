<?php

declare(strict_types=1);

namespace Drupal\smoke\Service;

/**
 * Generates JUnit XML reports from smoke test results.
 *
 * JUnit XML format is widely supported by CI systems like:
 * - GitHub Actions
 * - GitLab CI
 * - Jenkins
 * - CircleCI
 * - Azure DevOps.
 */
final class JunitReporter {

  /**
   * Generates JUnit XML from smoke test results.
   *
   * @param array<string, mixed> $results
   *   The test results from TestRunner.
   * @param string $suiteName
   *   Optional overall test suite name.
   *
   * @return string
   *   JUnit XML string.
   */
  public function generate(array $results, string $suiteName = 'Smoke Tests'): string {
    $dom = new \DOMDocument('1.0', 'UTF-8');
    $dom->formatOutput = TRUE;

    // Root <testsuites> element.
    $testsuites = $dom->createElement('testsuites');
    $testsuites->setAttribute('name', $suiteName);
    $testsuites->setAttribute('tests', (string) ($results['summary']['total'] ?? 0));
    $testsuites->setAttribute('failures', (string) ($results['summary']['failed'] ?? 0));
    $testsuites->setAttribute('errors', '0');
    $testsuites->setAttribute('skipped', (string) ($results['summary']['skipped'] ?? 0));
    $testsuites->setAttribute('time', $this->formatTime($results['summary']['duration'] ?? 0));
    $testsuites->setAttribute('timestamp', date('c', $results['ranAt'] ?? time()));
    $dom->appendChild($testsuites);

    // Add each suite as a <testsuite> element.
    foreach (($results['suites'] ?? []) as $suiteId => $suiteData) {
      $testsuite = $this->createTestSuite($dom, $suiteId, $suiteData);
      $testsuites->appendChild($testsuite);
    }

    return $dom->saveXML() ?: '';
  }

  /**
   * Creates a <testsuite> element for a single suite.
   *
   * @param \DOMDocument $dom
   *   The DOM document.
   * @param string $suiteId
   *   The suite identifier.
   * @param array<string, mixed> $suiteData
   *   The suite data.
   *
   * @return \DOMElement
   *   The testsuite element.
   */
  private function createTestSuite(\DOMDocument $dom, string $suiteId, array $suiteData): \DOMElement {
    $testsuite = $dom->createElement('testsuite');
    $testsuite->setAttribute('name', $suiteData['title'] ?? $suiteId);
    $testsuite->setAttribute('tests', (string) (
      ($suiteData['passed'] ?? 0) +
      ($suiteData['failed'] ?? 0) +
      ($suiteData['skipped'] ?? 0)
    ));
    $testsuite->setAttribute('failures', (string) ($suiteData['failed'] ?? 0));
    $testsuite->setAttribute('errors', '0');
    $testsuite->setAttribute('skipped', (string) ($suiteData['skipped'] ?? 0));
    $testsuite->setAttribute('time', $this->formatTime($suiteData['duration'] ?? 0));

    // Add each test as a <testcase> element.
    foreach (($suiteData['tests'] ?? []) as $test) {
      $testcase = $this->createTestCase($dom, $suiteId, $test);
      $testsuite->appendChild($testcase);
    }

    return $testsuite;
  }

  /**
   * Creates a <testcase> element for a single test.
   *
   * @param \DOMDocument $dom
   *   The DOM document.
   * @param string $suiteId
   *   The suite identifier (used as classname).
   * @param array<string, mixed> $test
   *   The test data.
   *
   * @return \DOMElement
   *   The testcase element.
   */
  private function createTestCase(\DOMDocument $dom, string $suiteId, array $test): \DOMElement {
    $testcase = $dom->createElement('testcase');
    $testcase->setAttribute('name', $test['title'] ?? 'Unknown test');
    $testcase->setAttribute('classname', 'smoke.' . $suiteId);
    $testcase->setAttribute('time', $this->formatTime($test['duration'] ?? 0));

    $status = $test['status'] ?? 'passed';

    if ($status === 'failed') {
      $failure = $dom->createElement('failure');
      $failure->setAttribute('message', $this->sanitizeMessage($test['error'] ?? 'Test failed'));
      $failure->setAttribute('type', 'AssertionError');

      // Add full error as CDATA content.
      if (!empty($test['error'])) {
        $cdata = $dom->createCDATASection($this->sanitizeMessage($test['error']));
        $failure->appendChild($cdata);
      }

      $testcase->appendChild($failure);
    }
    elseif ($status === 'skipped') {
      $skipped = $dom->createElement('skipped');
      $skipped->setAttribute('message', 'Test was skipped');
      $testcase->appendChild($skipped);
    }

    return $testcase;
  }

  /**
   * Formats duration from milliseconds to seconds.
   *
   * @param int $milliseconds
   *   Duration in milliseconds.
   *
   * @return string
   *   Duration in seconds, formatted to 3 decimal places.
   */
  private function formatTime(int $milliseconds): string {
    return number_format($milliseconds / 1000, 3, '.', '');
  }

  /**
   * Sanitizes error messages for XML.
   *
   * Removes ANSI escape codes and invalid XML characters.
   *
   * @param string $message
   *   The raw message.
   *
   * @return string
   *   The sanitized message.
   */
  private function sanitizeMessage(string $message): string {
    // Remove ANSI escape codes.
    $message = (string) preg_replace('/\x1b\[[0-9;]*m/', '', $message);

    // Remove invalid XML characters.
    $message = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $message);

    return $message;
  }

  /**
   * Writes JUnit XML to a file.
   *
   * @param array<string, mixed> $results
   *   The test results.
   * @param string $filePath
   *   The absolute path to write to.
   * @param string $suiteName
   *   Optional suite name.
   *
   * @return bool
   *   TRUE if write succeeded, FALSE otherwise.
   */
  public function writeToFile(array $results, string $filePath, string $suiteName = 'Smoke Tests'): bool {
    $xml = $this->generate($results, $suiteName);

    // Ensure directory exists.
    $dir = dirname($filePath);
    if (!is_dir($dir)) {
      if (!mkdir($dir, 0755, TRUE)) {
        return FALSE;
      }
    }

    return file_put_contents($filePath, $xml) !== FALSE;
  }

}
