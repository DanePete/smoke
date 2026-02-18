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

    // Health suite: admin status, cron, asset loading. Always available.
    $suites['health'] = $this->detectHealth();

    // Sitemap: auto-detected when simple_sitemap or xmlsitemap is installed.
    if ($this->moduleHandler->moduleExists('simple_sitemap') || $this->moduleHandler->moduleExists('xmlsitemap')) {
      $suites['sitemap'] = $this->detectSitemap();
    }

    // Content creation round-trip. Always available.
    $suites['content'] = $this->detectContent();

    // Accessibility (axe-core). Always available.
    $suites['accessibility'] = $this->detectAccessibility();

    return $suites;
  }

  /**
   * Returns human-readable labels for each suite.
   *
   * @return array<string, string>
   *   Map of suite id to label.
   */
  public static function suiteLabels(): array {
    return [
      'core_pages' => 'Core Pages',
      'auth' => 'Authentication',
      'webform' => 'Webform',
      'commerce' => 'Commerce',
      'search' => 'Search',
      'health' => 'Health',
      'sitemap' => 'Sitemap',
      'content' => 'Content',
      'accessibility' => 'Accessibility',
    ];
  }

  /**
   * Returns icon names for each suite (Drupal core icons).
   *
   * @return array<string, string>
   *   Map of suite id to icon name.
   */
  public static function suiteIcons(): array {
    return [
      'core_pages' => 'browser',
      'auth' => 'lock',
      'webform' => 'form',
      'commerce' => 'cart',
      'search' => 'search',
      'health' => 'medical',
      'sitemap' => 'sitemap',
      'content' => 'content',
      'accessibility' => 'accessibility',
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
   * Uses webform_id from smoke.settings. If ID is 'smoke_test' and the form
   * does not exist, it is auto-created. For any other ID, only existing
   * webforms are used so tests can target agency/company forms.
   */
  private function detectWebform(): array {
    try {
      $settings = $this->configFactory->get('smoke.settings');
      $webformId = (string) ($settings->get('webform_id') ?? 'smoke_test');
      if ($webformId === '') {
        $webformId = 'smoke_test';
      }

      $storage = $this->entityTypeManager->getStorage('webform');

      if ($webformId === 'smoke_test') {
        $this->ensureSmokeWebform($storage);
      }

      /** @var \Drupal\webform\WebformInterface|null $webform */
      $webform = $storage->load($webformId);
      if (!$webform || !$webform->isOpen()) {
        return [
          'detected' => FALSE,
          'label' => 'Webform',
          'description' => 'Submits the configured webform and confirms it works.',
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

      $title = $webform->label() ?? $webformId;
      return [
        'detected' => TRUE,
        'label' => 'Webform',
        'description' => 'Submits the configured webform and confirms it works.',
        'form' => [
          'id' => $webformId,
          'title' => $title,
          'path' => '/webform/' . $webformId,
          'fields' => $fields,
        ],
      ];
    }
    catch (\Exception) {
      return [
        'detected' => FALSE,
        'label' => 'Webform',
        'description' => 'Submits the configured webform and confirms it works.',
      ];
    }
  }

  /**
   * Creates the smoke_test webform if it doesn't already exist.
   *
   * Only used when webform_id is smoke_test. Gives a known form to test against.
   */
  private function ensureSmokeWebform($storage): void {
    if ($storage->load('smoke_test')) {
      return;
    }

    $this->createWebformWithStandardElements($storage, 'smoke_test', 'Smoke Test');
  }

  /**
   * Creates a webform with standard elements (Name, Email, Message) if missing.
   *
   * Used by smoke:setup when the customer chooses a webform ID. Id and title
   * are normalized; the form is created open with a simple confirmation.
   *
   * @return bool
   *   TRUE if the webform was created, FALSE if it already existed.
   */
  public function createWebformIfMissing(string $id, string $title = ''): bool {
    if (!$this->moduleHandler->moduleExists('webform')) {
      return FALSE;
    }
    $storage = $this->entityTypeManager->getStorage('webform');
    if ($storage->load($id)) {
      return FALSE;
    }
    if ($title === '') {
      $title = ucfirst(str_replace('_', ' ', $id));
    }
    $this->createWebformWithStandardElements($storage, $id, $title);
    return TRUE;
  }

  /**
   * Deletes the legacy smoke_test webform if it exists.
   *
   * Called during setup when the customer configures a different webform ID
   * so the old default is removed.
   */
  public function removeSmokeTestWebform(): void {
    if (!$this->moduleHandler->moduleExists('webform')) {
      return;
    }
    $storage = $this->entityTypeManager->getStorage('webform');
    $webform = $storage->load('smoke_test');
    if ($webform) {
      $webform->delete();
    }
  }

  /**
   * Creates a webform entity with standard Name, Email, Message elements.
   *
   * @param object $storage
   *   Webform entity storage.
   * @param string $id
   *   Webform machine name.
   * @param string $title
   *   Webform label.
   */
  private function createWebformWithStandardElements($storage, string $id, string $title): void {
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
      'id' => $id,
      'title' => $title,
      'status' => 'open',
      'elements' => $elements,
      'settings' => [
        'confirmation_type' => 'page',
        'confirmation_message' => 'Submission received.',
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

  /**
   * Detects health check capabilities.
   *
   * Always available — checks admin status report, cron, CSS/JS assets,
   * recent PHP errors in dblog, and cache behaviour.
   */
  private function detectHealth(): array {
    $hasDblog = $this->moduleHandler->moduleExists('dblog');

    return [
      'detected' => TRUE,
      'label' => 'Health',
      'description' => 'Admin status report, cron, CSS/JS assets, PHP error log, cache headers.',
      'hasDblog' => $hasDblog,
    ];
  }

  /**
   * Detects XML sitemap module.
   */
  private function detectSitemap(): array {
    $module = $this->moduleHandler->moduleExists('simple_sitemap') ? 'simple_sitemap' : 'xmlsitemap';
    return [
      'detected' => TRUE,
      'label' => 'Sitemap',
      'description' => 'XML sitemap exists, returns valid XML, contains URLs.',
      'module' => $module,
    ];
  }

  /**
   * Detects content creation capability.
   *
   * Always available — tests that the full content pipeline works by
   * creating a Basic Page, verifying it renders, then deleting it.
   */
  private function detectContent(): array {
    // Check if the 'page' content type exists.
    $hasPage = FALSE;
    try {
      $type = $this->entityTypeManager->getStorage('node_type')->load('page');
      $hasPage = $type !== NULL;
    }
    catch (\Exception) {
      // Node type storage might not exist.
    }

    return [
      'detected' => $hasPage,
      'label' => 'Content',
      'description' => 'Creates a test page, verifies it renders, deletes it. Full content pipeline check.',
      'contentType' => 'page',
    ];
  }

  /**
   * Detects accessibility scanning capability.
   *
   * Always available — runs axe-core WCAG 2.1 AA scans on key pages.
   */
  private function detectAccessibility(): array {
    return [
      'detected' => TRUE,
      'label' => 'Accessibility',
      'description' => 'WCAG 2.1 AA axe-core scan on homepage and login page.',
    ];
  }

}
