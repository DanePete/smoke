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
 * Commerce suite - tests product catalog and checkout.
 */
#[SmokeSuite(
  id: 'commerce',
  label: new TranslatableMarkup('Commerce'),
  description: new TranslatableMarkup('Product catalog accessible, cart exists, checkout flow intact.'),
  icon: 'cart',
  weight: -70,
  dependencies: ['commerce'],
)]
class CommerceSuite extends SuiteBase {

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
    if (!$this->moduleHandler->moduleExists('commerce')) {
      return FALSE;
    }

    try {
      $storeStorage = $this->entityTypeManager->getStorage('commerce_store');
      return $storeStorage->getQuery()
        ->accessCheck(FALSE)
        ->count()
        ->execute() > 0;
    }
    catch (\Exception) {
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getMetadata(): array {
    $hasProducts = FALSE;
    $hasStores = FALSE;
    $hasCart = $this->moduleHandler->moduleExists('commerce_cart');
    $hasCheckout = $this->moduleHandler->moduleExists('commerce_checkout');

    try {
      $storeStorage = $this->entityTypeManager->getStorage('commerce_store');
      $hasStores = $storeStorage->getQuery()
        ->accessCheck(FALSE)
        ->count()
        ->execute() > 0;

      $productStorage = $this->entityTypeManager->getStorage('commerce_product');
      $hasProducts = $productStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('status', 1)
        ->count()
        ->execute() > 0;
    }
    catch (\Exception) {
      // Commerce entity types may not exist.
    }

    return [
      'detected' => $hasStores,
      'label' => $this->getLabel(),
      'description' => $this->getDescription(),
      'hasProducts' => $hasProducts,
      'hasStores' => $hasStores,
      'hasCart' => $hasCart,
      'hasCheckout' => $hasCheckout,
    ];
  }

}
