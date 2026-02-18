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
 * Content suite - tests content creation pipeline.
 */
#[SmokeSuite(
  id: 'content',
  label: new TranslatableMarkup('Content'),
  description: new TranslatableMarkup('Creates a test page, verifies it renders, deletes it. Full content pipeline check.'),
  icon: 'content',
  weight: -30,
)]
class ContentSuite extends SuiteBase {

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
    // Check if the 'page' content type exists.
    try {
      $type = $this->entityTypeManager->getStorage('node_type')->load('page');
      return $type !== NULL;
    }
    catch (\Exception) {
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getMetadata(): array {
    return [
      'detected' => $this->isDetected(),
      'label' => $this->getLabel(),
      'description' => $this->getDescription(),
      'contentType' => 'page',
    ];
  }

}
