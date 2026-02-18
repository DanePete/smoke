<?php

declare(strict_types=1);

namespace Drupal\Tests\smoke\Unit;

use Drupal\smoke\Service\ModuleDetector;
use PHPUnit\Framework\TestCase;

/**
 * Tests ModuleDetector static helpers.
 *
 * @coversDefaultClass \Drupal\smoke\Service\ModuleDetector
 * @group smoke
 */
final class ModuleDetectorTest extends TestCase {

  /**
   * All suite IDs that should be present.
   */
  private const EXPECTED_SUITES = [
    'core_pages',
    'auth',
    'webform',
    'commerce',
    'search',
    'health',
    'sitemap',
    'content',
    'accessibility',
  ];

  /**
   * @covers ::suiteLabels
   */
  public function testSuiteLabelsReturnsAllSuites(): void {
    $labels = ModuleDetector::suiteLabels();

    $this->assertCount(count(self::EXPECTED_SUITES), $labels);
    foreach (self::EXPECTED_SUITES as $id) {
      $this->assertArrayHasKey($id, $labels, "Missing label for suite '$id'.");
      $this->assertNotEmpty($labels[$id], "Label for suite '$id' is empty.");
    }
  }

  /**
   * @covers ::suiteIcons
   */
  public function testSuiteIconsMatchLabels(): void {
    $labels = ModuleDetector::suiteLabels();
    $icons = ModuleDetector::suiteIcons();

    $this->assertSame(
      array_keys($labels),
      array_keys($icons),
      'Suite icons must have the same keys as suite labels.'
    );

    foreach ($icons as $id => $icon) {
      $this->assertNotEmpty($icon, "Icon for suite '$id' is empty.");
    }
  }

  /**
   * Verifies every suite ID maps to a Playwright spec file via naming.
   *
   * Suite IDs use underscores (core_pages) while spec files use dashes
   * (core-pages.spec.ts). This test ensures the mapping stays consistent.
   *
   * @covers ::suiteLabels
   */
  public function testSuiteIdsMapToSpecFiles(): void {
    $labels = ModuleDetector::suiteLabels();

    foreach (array_keys($labels) as $id) {
      $specFile = str_replace('_', '-', $id) . '.spec.ts';
      $specPath = dirname(__DIR__, 3) . '/playwright/suites/' . $specFile;
      $this->assertFileExists($specPath, "Missing Playwright spec for suite '$id': $specFile");
    }
  }

}
