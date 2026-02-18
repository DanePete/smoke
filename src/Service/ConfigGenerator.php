<?php

declare(strict_types=1);

namespace Drupal\smoke\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\State\StateInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Generates the JSON config bridge file that Playwright reads.
 */
final class ConfigGenerator {

  public function __construct(
    private readonly ModuleDetector $moduleDetector,
    private readonly StateInterface $state,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly RequestStack $requestStack,
    private readonly ModuleExtensionList $moduleExtensionList,
  ) {}

  /**
   * Generates the config array for Playwright.
   *
   * @param string|null $targetUrl
   *   Optional remote URL override. When set, tests run against this URL
   *   instead of the local DDEV site, and auth-dependent suites are flagged.
   * @param array<string, string>|null $remoteCredentials
   *   Optional remote auth credentials ['user' => ..., 'password' => ...].
   *   When provided (via Terminus), auth tests run on the remote target.
   *
   * @return array<string, mixed>
   */
  public function generate(?string $targetUrl = NULL, ?array $remoteCredentials = NULL): array {
    $settings = $this->configFactory->get('smoke.settings');
    $enabledSuites = $settings->get('suites') ?? [];
    $customUrls = $settings->get('custom_urls') ?? [];
    $timeout = (int) ($settings->get('timeout') ?? 30000);

    $detected = $this->moduleDetector->detect();

    // Use the target URL if provided, otherwise resolve from DDEV / request.
    $isRemote = $targetUrl !== NULL && $targetUrl !== '';
    $baseUrl = $isRemote ? rtrim($targetUrl, '/') : $this->resolveBaseUrl();
    $hasRemoteAuth = $remoteCredentials !== NULL && !empty($remoteCredentials['password']);

    // Get the site title.
    $siteConfig = $this->configFactory->get('system.site');
    $siteTitle = (string) $siteConfig->get('name');

    // Build suites config — only include suites that are both detected and enabled.
    $suites = [];
    foreach ($detected as $id => $suite) {
      $enabled = $enabledSuites[$id] ?? TRUE;
      if ($enabled && ($suite['detected'] ?? FALSE)) {
        $suites[$id] = $suite;
        $suites[$id]['enabled'] = TRUE;
      }
    }

    // Add auth credentials for test user.
    if ($hasRemoteAuth) {
      // Remote credentials from Terminus — use them for auth on the remote.
      if (!empty($suites['auth'])) {
        $suites['auth']['testUser'] = $remoteCredentials['user'] ?? 'smoke_bot';
        $suites['auth']['testPassword'] = $remoteCredentials['password'];
      }
    }
    else {
      // Local credentials from state.
      $botPassword = (string) $this->state->get('smoke.bot_password', '');
      if (!empty($suites['auth'])) {
        $suites['auth']['testUser'] = 'smoke_bot';
        $suites['auth']['testPassword'] = $botPassword;
      }
    }

    return [
      'baseUrl' => $baseUrl,
      'remote' => $isRemote,
      'remoteAuth' => $hasRemoteAuth,
      'siteTitle' => $siteTitle,
      'timeout' => $timeout,
      'customUrls' => $customUrls,
      'suites' => $suites,
    ];
  }

  /**
   * Writes the config to the module's playwright directory.
   *
   * @param string|null $targetUrl
   *   Optional remote URL override passed through to generate().
   *
   * @return string
   *   Path to the written config file.
   */
  public function writeConfig(?string $targetUrl = NULL, ?array $remoteCredentials = NULL): string {
    $config = $this->generate($targetUrl, $remoteCredentials);
    $modulePath = $this->getModulePath();
    $configPath = $modulePath . '/playwright/.smoke-config.json';

    $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    file_put_contents($configPath, $json);

    return $configPath;
  }

  /**
   * Returns the absolute path to this module.
   */
  public function getModulePath(): string {
    return DRUPAL_ROOT . '/' . $this->moduleExtensionList->getPath('smoke');
  }

  /**
   * Resolves the base URL for testing.
   */
  private function resolveBaseUrl(): string {
    // Prefer DDEV_PRIMARY_URL environment variable.
    $ddevUrl = getenv('DDEV_PRIMARY_URL');
    if ($ddevUrl) {
      return rtrim($ddevUrl, '/');
    }

    // Fall back to current request.
    $request = $this->requestStack->getCurrentRequest();
    if ($request) {
      return $request->getSchemeAndHttpHost();
    }

    return 'https://localhost';
  }

}
