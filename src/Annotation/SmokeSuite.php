<?php

declare(strict_types=1);

namespace Drupal\smoke\Annotation;

use Drupal\Component\Annotation\Plugin;

/**
 * Defines a Smoke Test Suite plugin annotation.
 *
 * Suite plugins allow other modules to contribute test suites to Smoke.
 *
 * Example annotation:
 * @code
 * @SmokeSuite(
 *   id = "agency_custom",
 *   label = @Translation("Agency Custom Tests"),
 *   description = @Translation("Custom tests specific to our agency."),
 *   weight = 10,
 * )
 * @endcode
 *
 * @Annotation
 */
class SmokeSuite extends Plugin {

  /**
   * The plugin ID.
   *
   * @var string
   */
  public string $id;

  /**
   * The human-readable name of the suite.
   *
   * @var \Drupal\Core\Annotation\Translation
   *
   * @ingroup plugin_translatable
   */
  public $label;

  /**
   * A brief description of what the suite tests.
   *
   * @var \Drupal\Core\Annotation\Translation
   *
   * @ingroup plugin_translatable
   */
  public $description;

  /**
   * The weight for ordering suites in the UI.
   *
   * Lower weights appear first.
   *
   * @var int
   */
  public int $weight = 0;

  /**
   * An optional icon name (from Drupal core icons).
   *
   * @var string
   */
  public string $icon = 'puzzle';

  /**
   * Module dependencies required for this suite to be available.
   *
   * @var string[]
   */
  public array $dependencies = [];

}
