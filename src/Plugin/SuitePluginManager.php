<?php

declare(strict_types=1);

namespace Drupal\smoke\Plugin;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\smoke\Annotation\SmokeSuite;
use Drupal\smoke\Attribute\SmokeSuite as SmokeSuiteAttribute;

/**
 * Plugin manager for Smoke Test Suite plugins.
 *
 * Discovers and manages suite plugins from all modules. Suites can be defined
 * in two ways:
 *
 * 1. PHP plugins in MODULE/src/Plugin/SmokeSuite/ using #[SmokeSuite] attribute
 * 2. YAML definitions in MODULE/smoke.suites.yml
 *
 * Example smoke.suites.yml:
 * @code
 * agency_seo:
 *   label: 'SEO Checks'
 *   description: 'Validates meta tags and structured data.'
 *   icon: search
 *   weight: 20
 *   dependencies:
 *     - metatag
 *   spec_path: tests/playwright/seo.spec.ts
 *
 * agency_performance:
 *   label: 'Performance Tests'
 *   description: 'Validates page load times and Core Web Vitals.'
 *   weight: 30
 * @endcode
 *
 * @see \Drupal\smoke\Plugin\SuiteInterface
 * @see \Drupal\smoke\Plugin\SuiteBase
 * @see \Drupal\smoke\Attribute\SmokeSuite
 */
class SuitePluginManager extends DefaultPluginManager {

  /**
   * Constructs a SuitePluginManager.
   *
   * @param \Traversable $namespaces
   *   An object that implements \Traversable which contains the root paths
   *   keyed by the corresponding namespace to look for plugin implementations.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   The cache backend.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cache_backend,
    ModuleHandlerInterface $module_handler,
  ) {
    parent::__construct(
      'Plugin/SmokeSuite',
      $namespaces,
      $module_handler,
      SuiteInterface::class,
      SmokeSuiteAttribute::class,
      SmokeSuite::class,
    );

    $this->alterInfo('smoke_suite_info');
    $this->setCacheBackend($cache_backend, 'smoke_suite_plugins');
  }

  /**
   * Gets all available suite plugins.
   *
   * @return \Drupal\smoke\Plugin\SuiteInterface[]
   *   Array of suite plugin instances, keyed by plugin ID.
   */
  public function getSuites(): array {
    $suites = [];

    foreach ($this->getDefinitions() as $id => $definition) {
      try {
        $suites[$id] = $this->createInstance($id);
      }
      catch (\Exception $e) {
        // Log but don't fail if a plugin can't be instantiated.
        \Drupal::logger('smoke')->warning('Failed to instantiate suite plugin @id: @message', [
          '@id' => $id,
          '@message' => $e->getMessage(),
        ]);
      }
    }

    // Sort by weight.
    uasort($suites, fn(SuiteInterface $a, SuiteInterface $b) => $a->getWeight() <=> $b->getWeight());

    return $suites;
  }

  /**
   * Gets only detected (available) suite plugins.
   *
   * @return \Drupal\smoke\Plugin\SuiteInterface[]
   *   Array of detected suite plugin instances.
   */
  public function getDetectedSuites(): array {
    return array_filter(
      $this->getSuites(),
      fn(SuiteInterface $suite) => $suite->isDetected(),
    );
  }

  /**
   * Gets a specific suite by ID.
   *
   * @param string $id
   *   The suite ID.
   *
   * @return \Drupal\smoke\Plugin\SuiteInterface|null
   *   The suite plugin, or NULL if not found.
   */
  public function getSuite(string $id): ?SuiteInterface {
    if (!$this->hasDefinition($id)) {
      return NULL;
    }

    try {
      return $this->createInstance($id);
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * Gets suite labels for display.
   *
   * @return array<string, string>
   *   Map of suite ID to label.
   */
  public function getSuiteLabels(): array {
    $labels = [];
    foreach ($this->getDefinitions() as $id => $definition) {
      $labels[$id] = (string) ($definition['label'] ?? $id);
    }
    return $labels;
  }

  /**
   * Gets suite icons for display.
   *
   * @return array<string, string>
   *   Map of suite ID to icon name.
   */
  public function getSuiteIcons(): array {
    $icons = [];
    foreach ($this->getDefinitions() as $id => $definition) {
      $icons[$id] = $definition['icon'] ?? 'puzzle';
    }
    return $icons;
  }

}
