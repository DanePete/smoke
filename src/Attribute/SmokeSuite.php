<?php

declare(strict_types=1);

namespace Drupal\smoke\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines a Smoke Test Suite plugin attribute.
 *
 * Suite plugins allow other modules to contribute test suites to Smoke.
 *
 * Example usage:
 * @code
 * use Drupal\smoke\Attribute\SmokeSuite;
 *
 * #[SmokeSuite(
 *   id: 'agency_custom',
 *   label: new TranslatableMarkup('Agency Custom Tests'),
 *   description: new TranslatableMarkup('Custom tests for our agency standards.'),
 *   weight: 10,
 *   icon: 'star',
 * )]
 * class AgencyCustomSuite extends SuiteBase {
 *   // ...
 * }
 * @endcode
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class SmokeSuite extends Plugin {

  /**
   * Module dependencies for this suite.
   *
   * Stored separately from AttributeBase::$dependencies which
   * uses a different type (array|null with structured keys).
   *
   * @var string[]
   */
  private array $moduleDependencies;

  /**
   * Constructs a SmokeSuite attribute.
   *
   * @param string $id
   *   The plugin ID.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $label
   *   The human-readable name of the suite.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $description
   *   A brief description of what the suite tests.
   * @param int $weight
   *   The weight for ordering suites in the UI.
   * @param string $icon
   *   An optional icon name (from Drupal core icons).
   * @param string[] $dependencies
   *   Module dependencies required for this suite.
   * @param class-string|null $deriver
   *   The deriver class, if any.
   */
  public function __construct(
    public readonly string $id,
    public readonly ?TranslatableMarkup $label = NULL,
    public readonly ?TranslatableMarkup $description = NULL,
    public readonly int $weight = 0,
    public readonly string $icon = 'puzzle',
    array $dependencies = [],
    public readonly ?string $deriver = NULL,
  ) {
    $this->moduleDependencies = $dependencies;
  }

  /**
   * {@inheritdoc}
   */
  public function get(): array|object {
    $values = parent::get();
    if (is_array($values)) {
      unset($values['moduleDependencies']);
      $values['dependencies'] = $this->moduleDependencies;
    }
    return $values;
  }

}
