<?php

declare(strict_types=1);

namespace Drupal\smoke\Plugin;

use Drupal\Component\Plugin\PluginInspectionInterface;

/**
 * Interface for Smoke Test Suite plugins.
 *
 * Suite plugins allow modules to contribute test suites to Smoke.
 * Each suite corresponds to a Playwright spec file that runs tests.
 *
 * To create a custom suite:
 * 1. Create a class implementing this interface (or extending SuiteBase)
 * 2. Add the #[SmokeSuite] attribute with metadata
 * 3. Place your spec file in the expected location
 *
 * @see \Drupal\smoke\Plugin\SuiteBase
 * @see \Drupal\smoke\Attribute\SmokeSuite
 */
interface SuiteInterface extends PluginInspectionInterface {

  /**
   * Returns the suite ID.
   *
   * This should match the spec filename (without extension).
   * E.g., 'agency_custom' expects 'agency-custom.spec.ts'.
   *
   * @return string
   *   The suite ID.
   */
  public function getId(): string;

  /**
   * Returns the human-readable label.
   *
   * @return string
   *   The label.
   */
  public function getLabel(): string;

  /**
   * Returns a description of what the suite tests.
   *
   * @return string
   *   The description.
   */
  public function getDescription(): string;

  /**
   * Returns the icon name.
   *
   * @return string
   *   Icon name from Drupal core icons.
   */
  public function getIcon(): string;

  /**
   * Returns the weight for ordering.
   *
   * @return int
   *   The weight.
   */
  public function getWeight(): int;

  /**
   * Determines if this suite is detected/available.
   *
   * Override this to add custom detection logic, e.g., checking if
   * certain modules are installed or configuration exists.
   *
   * @return bool
   *   TRUE if the suite should be available.
   */
  public function isDetected(): bool;

  /**
   * Returns the absolute path to the spec file.
   *
   * Override this if your spec file is in a non-standard location.
   *
   * @return string|null
   *   The absolute path to the spec file, or NULL if not found.
   */
  public function getSpecPath(): ?string;

  /**
   * Returns detection metadata for the suite.
   *
   * This can include any suite-specific data that should be passed
   * to the Playwright tests via the config file.
   *
   * @return array<string, mixed>
   *   Detection metadata.
   */
  public function getMetadata(): array;

  /**
   * Returns the provider module or source of this suite.
   *
   * @return string
   *   The provider (module name or 'custom').
   */
  public function getProvider(): string;

}
