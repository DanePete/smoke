<?php

declare(strict_types=1);

namespace Drupal\smoke\Plugin\SmokeSuite;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\smoke\Attribute\SmokeSuite;
use Drupal\smoke\Plugin\SuiteBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Search suite - tests search functionality.
 */
#[SmokeSuite(
  id: 'search',
  label: new TranslatableMarkup('Search'),
  description: new TranslatableMarkup('Search page loads and contains a search form.'),
  icon: 'search',
  weight: -60,
)]
class SearchSuite extends SuiteBase {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ModuleHandlerInterface $module_handler,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $module_handler);
    $this->entityTypeManager = $entity_type_manager;
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
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function isDetected(): bool {
    // Requires either search_api or core search module.
    return $this->moduleHandler->moduleExists('search_api')
      || $this->moduleHandler->moduleExists('search');
  }

  /**
   * {@inheritdoc}
   */
  public function getMetadata(): array {
    $searchPath = '/search';

    // Try to find a search view page.
    try {
      if ($this->moduleHandler->moduleExists('views')) {
        $viewStorage = $this->entityTypeManager->getStorage('view');
        /** @var \Drupal\views\ViewEntityInterface[] $views */
        $views = $viewStorage->loadMultiple();
        foreach ($views as $view) {
          if (!$view->status()) {
            continue;
          }
          $displays = $view->get('display');
          foreach ($displays as $display) {
            if (($display['display_plugin'] ?? '') === 'page') {
              $path = $display['display_options']['path'] ?? '';
              if ($path && stripos($path, 'search') !== FALSE && stripos($path, 'admin') === FALSE) {
                $searchPath = '/' . ltrim($path, '/');
                break 2;
              }
            }
          }
        }
      }
    }
    catch (\Exception) {
      // Views may not be available.
    }

    return [
      'detected' => $this->isDetected(),
      'label' => $this->getLabel(),
      'description' => $this->getDescription(),
      'searchPath' => $searchPath,
    ];
  }

}
