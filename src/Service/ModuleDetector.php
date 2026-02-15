<?php

declare(strict_types=1);

namespace Drupal\smoke\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Detects installed modules and builds a map of testable features.
 */
final class ModuleDetector {

  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Returns all detected test suites and their metadata.
   *
   * @return array<string, array<string, mixed>>
   *   Keyed by suite id.
   */
  public function detect(): array {
    $suites = [];

    $suites['core_pages'] = $this->detectCorePages();
    $suites['auth'] = $this->detectAuth();

    if ($this->moduleHandler->moduleExists('webform')) {
      $suites['webform'] = $this->detectWebform();
    }

    if ($this->moduleHandler->moduleExists('commerce')) {
      $suites['commerce'] = $this->detectCommerce();
    }

    if ($this->moduleHandler->moduleExists('search_api')) {
      $suites['search'] = $this->detectSearch();
    }
    elseif ($this->moduleHandler->moduleExists('search')) {
      $suites['search'] = $this->detectCoreSearch();
    }

    return $suites;
  }

  /**
   * Returns human-readable labels for each suite.
   *
   * @return array<string, string>
   */
  public static function suiteLabels(): array {
    return [
      'core_pages' => 'Core Pages',
      'auth' => 'Authentication',
      'webform' => 'Webform',
      'commerce' => 'Commerce',
      'search' => 'Search',
    ];
  }

  /**
   * Returns icon names for each suite (Drupal core icons).
   *
   * @return array<string, string>
   */
  public static function suiteIcons(): array {
    return [
      'core_pages' => 'browser',
      'auth' => 'lock',
      'webform' => 'form',
      'commerce' => 'cart',
      'search' => 'search',
    ];
  }

  /**
   * Detects core pages to test.
   */
  private function detectCorePages(): array {
    $siteConfig = $this->configFactory->get('system.site');
    $pages = [
      ['path' => '/', 'label' => 'Homepage'],
      ['path' => '/user/login', 'label' => 'Login page'],
    ];

    return [
      'detected' => TRUE,
      'label' => 'Core Pages',
      'description' => 'Homepage, login, and critical pages return 200 with no PHP errors.',
      'pages' => $pages,
      'siteTitle' => (string) $siteConfig->get('name'),
    ];
  }

  /**
   * Detects authentication features.
   */
  private function detectAuth(): array {
    return [
      'detected' => TRUE,
      'label' => 'Authentication',
      'description' => 'Login form works, invalid credentials show errors, password reset exists.',
    ];
  }

  /**
   * Detects webform entities and their fields.
   *
   * Looks for a dedicated 'smoke_test' webform first. If one doesn't exist,
   * it is created automatically so there's always a known form to test against.
   */
  private function detectWebform(): array {
    try {
      $storage = $this->entityTypeManager->getStorage('webform');

      // Ensure the smoke_test webform exists.
      $this->ensureSmokeWebform($storage);

      /** @var \Drupal\webform\WebformInterface|null $webform */
      $webform = $storage->load('smoke_test');
      if (!$webform || !$webform->isOpen()) {
        return [
          'detected' => FALSE,
          'label' => 'Webform',
          'description' => 'Submits the smoke_test form and confirms it works.',
        ];
      }

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
        'label' => 'Webform',
        'description' => 'Submits the smoke_test form and confirms it works.',
        'form' => [
          'id' => 'smoke_test',
          'title' => 'Smoke Test',
          'path' => '/webform/smoke_test',
          'fields' => $fields,
        ],
      ];
    }
    catch (\Exception) {
      return [
        'detected' => FALSE,
        'label' => 'Webform',
        'description' => 'Submits the smoke_test form and confirms it works.',
      ];
    }
  }

  /**
   * Creates the smoke_test webform if it doesn't already exist.
   *
   * This gives the test suite a known, predictable form to run against
   * regardless of what other webforms exist on the site.
   */
  private function ensureSmokeWebform($storage): void {
    if ($storage->load('smoke_test')) {
      return;
    }

    $elements = <<<YAML
name:
  '#type': textfield
  '#title': Name
  '#required': true
email:
  '#type': email
  '#title': Email
  '#required': true
message:
  '#type': textarea
  '#title': Message
  '#required': true
YAML;

    $webform = $storage->create([
      'id' => 'smoke_test',
      'title' => 'Smoke Test',
      'status' => 'open',
      'elements' => $elements,
      'settings' => [
        'confirmation_type' => 'page',
        'confirmation_message' => 'Smoke test submission received.',
      ],
    ]);
    $webform->save();
  }

  /**
   * Detects Drupal Commerce features.
   */
  private function detectCommerce(): array {
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
      'label' => 'Commerce',
      'description' => 'Product catalog accessible, cart exists, checkout flow intact.',
      'hasProducts' => $hasProducts,
      'hasStores' => $hasStores,
      'hasCart' => $hasCart,
      'hasCheckout' => $hasCheckout,
    ];
  }

  /**
   * Detects Search API features.
   */
  private function detectSearch(): array {
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
      'detected' => TRUE,
      'label' => 'Search',
      'description' => 'Search page loads and contains a search form.',
      'searchPath' => $searchPath,
    ];
  }

  /**
   * Detects core search module features.
   */
  private function detectCoreSearch(): array {
    return [
      'detected' => TRUE,
      'label' => 'Search',
      'description' => 'Search page loads and contains a search form.',
      'searchPath' => '/search',
    ];
  }

}
