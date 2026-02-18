<?php

declare(strict_types=1);

namespace Drupal\smoke\Plugin\SmokeSuite;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\smoke\Attribute\SmokeSuite;
use Drupal\smoke\Plugin\SuiteBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Core Pages suite - tests homepage and critical pages.
 */
#[SmokeSuite(
  id: 'core_pages',
  label: new TranslatableMarkup('Core Pages'),
  description: new TranslatableMarkup('Homepage, login, and critical pages return 200 with no PHP errors.'),
  icon: 'browser',
  weight: -100,
)]
class CorePagesSuite extends SuiteBase {

  /**
   * The config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ModuleHandlerInterface $module_handler,
    ConfigFactoryInterface $config_factory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $module_handler);
    $this->configFactory = $config_factory;
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
    );
  }

  /**
   * {@inheritdoc}
   */
  public function isDetected(): bool {
    // Core pages are always available.
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getMetadata(): array {
    $siteConfig = $this->configFactory->get('system.site');
    $pages = [
      ['path' => '/', 'label' => 'Homepage'],
      ['path' => '/user/login', 'label' => 'Login page'],
    ];

    return [
      'detected' => TRUE,
      'label' => $this->getLabel(),
      'description' => $this->getDescription(),
      'pages' => $pages,
      'siteTitle' => (string) $siteConfig->get('name'),
    ];
  }

}
