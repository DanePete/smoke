<?php

declare(strict_types=1);

namespace Drupal\smoke\Plugin;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for Smoke Test Suite plugins.
 *
 * Provides sensible defaults for the SuiteInterface methods.
 * Extend this class to create custom suites with minimal boilerplate.
 *
 * Example:
 * @code
 * namespace Drupal\my_module\Plugin\SmokeSuite;
 *
 * use Drupal\smoke\Attribute\SmokeSuite;
 * use Drupal\smoke\Plugin\SuiteBase;
 * use Drupal\Core\StringTranslation\TranslatableMarkup;
 *
 * #[SmokeSuite(
 *   id: 'agency_seo',
 *   label: new TranslatableMarkup('SEO Checks'),
 *   description: new TranslatableMarkup('Validates meta tags, structured data, and canonical URLs.'),
 *   icon: 'search',
 *   weight: 20,
 * )]
 * class AgencySeoSuite extends SuiteBase {
 *
 *   public function isDetected(): bool {
 *     // Only available if metatag module is installed.
 *     return $this->moduleHandler->moduleExists('metatag');
 *   }
 *
 *   public function getMetadata(): array {
 *     return [
 *       'detected' => $this->isDetected(),
 *       'label' => $this->getLabel(),
 *       'description' => $this->getDescription(),
 *       'requiredMetaTags' => ['title', 'description', 'og:title'],
 *     ];
 *   }
 *
 * }
 * @endcode
 */
abstract class SuiteBase extends PluginBase implements SuiteInterface, ContainerFactoryPluginInterface {

  use StringTranslationTrait;

  /**
   * The module handler service.
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ModuleHandlerInterface $module_handler,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->moduleHandler = $module_handler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('module_handler'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return $this->getPluginId();
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): string {
    $definition = $this->getPluginDefinition();
    return (string) ($definition['label'] ?? $this->getId());
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    $definition = $this->getPluginDefinition();
    return (string) ($definition['description'] ?? '');
  }

  /**
   * {@inheritdoc}
   */
  public function getIcon(): string {
    $definition = $this->getPluginDefinition();
    return $definition['icon'] ?? 'puzzle';
  }

  /**
   * {@inheritdoc}
   */
  public function getWeight(): int {
    $definition = $this->getPluginDefinition();
    return (int) ($definition['weight'] ?? 0);
  }

  /**
   * {@inheritdoc}
   */
  public function isDetected(): bool {
    // Check module dependencies.
    $definition = $this->getPluginDefinition();
    $dependencies = $definition['dependencies'] ?? [];

    foreach ($dependencies as $module) {
      if (!$this->moduleHandler->moduleExists($module)) {
        return FALSE;
      }
    }

    // Also verify the spec file exists.
    return $this->getSpecPath() !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getSpecPath(): ?string {
    // Default: look for spec file in the provider module's playwright/suites directory.
    $provider = $this->getProvider();

    if ($provider === 'smoke') {
      // Built-in suites.
      $modulePath = $this->moduleHandler->getModule('smoke')->getPath();
      $specFile = str_replace('_', '-', $this->getId()) . '.spec.ts';
      $path = DRUPAL_ROOT . '/' . $modulePath . '/playwright/suites/' . $specFile;
      return file_exists($path) ? $path : NULL;
    }

    if ($this->moduleHandler->moduleExists($provider)) {
      // Suite provided by another module.
      $modulePath = $this->moduleHandler->getModule($provider)->getPath();
      $specFile = str_replace('_', '-', $this->getId()) . '.spec.ts';

      // Check module's playwright/suites directory.
      $path = DRUPAL_ROOT . '/' . $modulePath . '/playwright/suites/' . $specFile;
      if (file_exists($path)) {
        return $path;
      }

      // Also check module's tests/playwright directory.
      $path = DRUPAL_ROOT . '/' . $modulePath . '/tests/playwright/' . $specFile;
      if (file_exists($path)) {
        return $path;
      }
    }

    // Check custom paths (project root).
    $customPath = DRUPAL_ROOT . '/../playwright-smoke/suites/' . str_replace('_', '-', $this->getId()) . '.spec.ts';
    if (file_exists($customPath)) {
      return $customPath;
    }

    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getMetadata(): array {
    return [
      'detected' => $this->isDetected(),
      'label' => $this->getLabel(),
      'description' => $this->getDescription(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getProvider(): string {
    $definition = $this->getPluginDefinition();
    return $definition['provider'] ?? 'smoke';
  }

}
