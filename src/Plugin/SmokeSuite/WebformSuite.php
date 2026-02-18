<?php

declare(strict_types=1);

namespace Drupal\smoke\Plugin\SmokeSuite;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\smoke\Attribute\SmokeSuite;
use Drupal\smoke\Plugin\SuiteBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Webform suite - tests form submission.
 */
#[SmokeSuite(
  id: 'webform',
  label: new TranslatableMarkup('Webform'),
  description: new TranslatableMarkup('Submits the configured webform and confirms it works.'),
  icon: 'form',
  weight: -80,
  dependencies: ['webform'],
)]
class WebformSuite extends SuiteBase {

  /**
   * The config factory.
   */
  protected ConfigFactoryInterface $configFactory;

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
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $module_handler);
    $this->configFactory = $config_factory;
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
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function isDetected(): bool {
    if (!$this->moduleHandler->moduleExists('webform')) {
      return FALSE;
    }

    try {
      $settings = $this->configFactory->get('smoke.settings');
      $webformId = (string) ($settings->get('webform_id') ?? 'smoke_test');
      if ($webformId === '') {
        $webformId = 'smoke_test';
      }

      $storage = $this->entityTypeManager->getStorage('webform');
      $webform = $storage->load($webformId);

      return $webform && $webform->isOpen();
    }
    catch (\Exception) {
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getMetadata(): array {
    if (!$this->isDetected()) {
      return [
        'detected' => FALSE,
        'label' => $this->getLabel(),
        'description' => $this->getDescription(),
      ];
    }

    try {
      $settings = $this->configFactory->get('smoke.settings');
      $webformId = (string) ($settings->get('webform_id') ?? 'smoke_test');

      $storage = $this->entityTypeManager->getStorage('webform');
      /** @var \Drupal\webform\WebformInterface $webform */
      $webform = $storage->load($webformId);

      $elements = $webform->getElementsInitializedFlattenedAndHasValue();
      $fields = [];
      foreach ($elements as $key => $element) {
        $fields[] = [
          'key' => $key,
          'type' => $element['#type'] ?? 'unknown',
          'title' => (string) ($element['#title'] ?? $key),
          'required' => !empty($element['#required']),
        ];
      }

      return [
        'detected' => TRUE,
        'label' => $this->getLabel(),
        'description' => $this->getDescription(),
        'form' => [
          'id' => $webformId,
          'title' => $webform->label() ?? $webformId,
          'path' => '/webform/' . $webformId,
          'fields' => $fields,
        ],
      ];
    }
    catch (\Exception) {
      return [
        'detected' => FALSE,
        'label' => $this->getLabel(),
        'description' => $this->getDescription(),
      ];
    }
  }

}
