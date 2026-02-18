<?php

declare(strict_types=1);

namespace Drupal\smoke\Service;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\smoke\Discovery\YamlSuiteDiscovery;

/**
 * Discovers all Smoke test suites (built-in + YAML-defined from any module).
 *
 * Drupal's pluggable approach: any module can provide suites via
 * MODULE/smoke.suites.yml. Labels and icons come from discovery, not static lists.
 */
final class SuiteDiscovery {

  public function __construct(
    private readonly ModuleDetector $moduleDetector,
    private readonly YamlSuiteDiscovery $yamlSuiteDiscovery,
    private readonly ModuleExtensionList $moduleExtensionList,
  ) {}

  /**
   * Returns all detected suites (built-in + YAML from installed modules).
   *
   * @return array<string, array<string, mixed>>
   *   Suite id => [ detected, label, description, icon?, spec_path?, ... ]
   */
  public function getSuites(): array {
    $builtIn = $this->moduleDetector->detect();
    $yaml = $this->yamlSuiteDiscovery->discover();

    // Add YAML-defined suites only when the spec file exists.
    foreach ($yaml as $id => $def) {
      if (isset($builtIn[$id])) {
        continue;
      }
      $specPath = $def['spec_path'] ?? NULL;
      if ($specPath && is_string($specPath) && file_exists($specPath)) {
        $builtIn[$id] = [
          'detected' => TRUE,
          'label' => $def['label'] ?? ucfirst(str_replace('_', ' ', $id)),
          'description' => $def['description'] ?? '',
          'icon' => $def['icon'] ?? 'puzzle',
          'spec_path' => $specPath,
          'provider' => $def['provider'] ?? NULL,
        ];
      }
    }

    return $builtIn;
  }

  /**
   * Returns labels for all discovered suites (dynamic).
   *
   * @return array<string, string>
   */
  public function getLabels(): array {
    $labels = [];
    foreach ($this->getSuites() as $id => $suite) {
      $labels[$id] = (string) ($suite['label'] ?? $id);
    }
    return $labels;
  }

  /**
   * Returns icons for all discovered suites (dynamic).
   *
   * YAML-defined suites use their icon; built-in suites fall back to
   * ModuleDetector::suiteIcons() for backward compatibility.
   *
   * @return array<string, string>
   */
  public function getIcons(): array {
    $icons = [];
    $builtInIcons = ModuleDetector::suiteIcons();
    foreach ($this->getSuites() as $id => $suite) {
      $icons[$id] = (string) ($suite['icon'] ?? $builtInIcons[$id] ?? 'puzzle');
    }
    return $icons;
  }

  /**
   * Returns the absolute path to the spec file for a suite, or NULL.
   *
   * Built-in suites use smoke's playwright/suites/. YAML suites use their
   * definition's spec_path.
   */
  public function getSpecPath(string $suiteId): ?string {
    $suites = $this->getSuites();
    $suite = $suites[$suiteId] ?? NULL;
    if (!$suite) {
      return NULL;
    }
    if (!empty($suite['spec_path']) && file_exists($suite['spec_path'])) {
      return $suite['spec_path'];
    }
    // Built-in: file or directory in smoke's playwright/suites/.
    $smokePath = $this->moduleExtensionList->getPath('smoke');
    $base = DRUPAL_ROOT . '/' . $smokePath . '/playwright/suites';
    $specFile = str_replace('_', '-', $suiteId) . '.spec.ts';
    $specDir = str_replace('_', '-', $suiteId);
    if (file_exists($base . '/' . $specFile)) {
      return $base . '/' . $specFile;
    }
    if (is_dir($base . '/' . $specDir)) {
      return $base . '/' . $specDir;
    }
    return NULL;
  }
}
