<?php

declare(strict_types=1);

namespace Drupal\smoke\Discovery;

use Drupal\Component\Discovery\YamlDirectoryDiscovery;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Discovers smoke suites defined in YAML files.
 *
 * Allows modules to define test suites without any PHP code by creating
 * a smoke.suites.yml file in their module root directory.
 *
 * Example smoke.suites.yml:
 * @code
 * agency_seo:
 *   label: 'SEO Checks'
 *   description: 'Validates meta tags, structured data, and canonical URLs.'
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
 * Spec files can be placed in:
 * - MODULE/playwright/suites/SUITE_ID.spec.ts (default)
 * - MODULE/tests/playwright/SUITE_ID.spec.ts
 * - Custom path specified by spec_path key
 *
 * @see \Drupal\smoke\Plugin\SmokeSuite\YamlDefinedSuite
 */
class YamlSuiteDiscovery {

  /**
   * The module handler.
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * Constructs a YamlSuiteDiscovery.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(ModuleHandlerInterface $module_handler) {
    $this->moduleHandler = $module_handler;
  }

  /**
   * Discovers all YAML-defined suites.
   *
   * @return array<string, array<string, mixed>>
   *   Array of suite definitions keyed by suite ID.
   */
  public function discover(): array {
    $definitions = [];

    // Discover from all installed modules.
    foreach ($this->moduleHandler->getModuleList() as $name => $extension) {
      $modulePath = $extension->getPath();
      $yamlPath = DRUPAL_ROOT . '/' . $modulePath . '/smoke.suites.yml';

      if (!file_exists($yamlPath)) {
        continue;
      }

      $yaml = file_get_contents($yamlPath);
      if (!$yaml) {
        continue;
      }

      try {
        $suites = \Symfony\Component\Yaml\Yaml::parse($yaml);
        if (!is_array($suites)) {
          continue;
        }

        foreach ($suites as $id => $definition) {
          if (!is_array($definition)) {
            continue;
          }

          $definitions[$id] = $this->normalizeDefinition($id, $definition, $name, $modulePath);
        }
      }
      catch (\Exception $e) {
        \Drupal::logger('smoke')->warning('Failed to parse @path: @message', [
          '@path' => $yamlPath,
          '@message' => $e->getMessage(),
        ]);
      }
    }

    // Also discover from project root custom path.
    $customPath = DRUPAL_ROOT . '/../playwright-smoke/smoke.suites.yml';
    if (file_exists($customPath)) {
      try {
        $yaml = file_get_contents($customPath);
        $suites = \Symfony\Component\Yaml\Yaml::parse($yaml);
        if (is_array($suites)) {
          foreach ($suites as $id => $definition) {
            if (!is_array($definition)) {
              continue;
            }
            $definitions[$id] = $this->normalizeDefinition($id, $definition, 'custom', '../playwright-smoke');
          }
        }
      }
      catch (\Exception $e) {
        \Drupal::logger('smoke')->warning('Failed to parse custom suites: @message', [
          '@message' => $e->getMessage(),
        ]);
      }
    }

    return $definitions;
  }

  /**
   * Normalizes a YAML suite definition.
   *
   * @param string $id
   *   The suite ID.
   * @param array $definition
   *   The raw YAML definition.
   * @param string $provider
   *   The provider module name.
   * @param string $modulePath
   *   The module path.
   *
   * @return array<string, mixed>
   *   The normalized definition.
   */
  protected function normalizeDefinition(string $id, array $definition, string $provider, string $modulePath): array {
    $specFile = str_replace('_', '-', $id) . '.spec.ts';

    // Determine spec path.
    $specPath = NULL;
    if (!empty($definition['spec_path'])) {
      // Custom spec path relative to module.
      $specPath = DRUPAL_ROOT . '/' . $modulePath . '/' . $definition['spec_path'];
    }
    else {
      // Default locations.
      $candidates = [
        DRUPAL_ROOT . '/' . $modulePath . '/playwright/suites/' . $specFile,
        DRUPAL_ROOT . '/' . $modulePath . '/tests/playwright/' . $specFile,
      ];
      foreach ($candidates as $candidate) {
        if (file_exists($candidate)) {
          $specPath = $candidate;
          break;
        }
      }
    }

    return [
      'id' => $id,
      'label' => $definition['label'] ?? ucfirst(str_replace('_', ' ', $id)),
      'description' => $definition['description'] ?? '',
      'icon' => $definition['icon'] ?? 'puzzle',
      'weight' => (int) ($definition['weight'] ?? 0),
      'dependencies' => $definition['dependencies'] ?? [],
      'provider' => $provider,
      'spec_path' => $specPath,
      'yaml_defined' => TRUE,
    ];
  }

}
