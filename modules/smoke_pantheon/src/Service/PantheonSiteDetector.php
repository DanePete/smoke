<?php

declare(strict_types=1);

namespace Drupal\smoke_pantheon\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\Process\Process;

/**
 * Detects Pantheon site information from project configuration.
 */
final class PantheonSiteDetector {

  /**
   * Cached site name.
   */
  private ?string $siteName = NULL;

  /**
   * Cached Terminus status.
   */
  private ?array $terminusStatus = NULL;

  /**
   * Constructs the PantheonSiteDetector.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Gets the Pantheon site name.
   *
   * Detection order:
   * 1. Module configuration (smoke_pantheon.settings.site_name)
   * 2. PANTHEON_SITE environment variable (when running on Pantheon)
   * 3. Git remote named 'pantheon'
   * 4. pantheon.yml site name (if present)
   *
   * @return string|null
   *   The site name or NULL if not detected.
   */
  public function getSiteName(): ?string {
    if ($this->siteName !== NULL) {
      return $this->siteName ?: NULL;
    }

    // 1. Check module config first (user override).
    $config = $this->configFactory->get('smoke_pantheon.settings');
    $configuredName = $config->get('site_name');
    if ($configuredName && is_string($configuredName) && $configuredName !== '') {
      $this->siteName = $configuredName;
      return $this->siteName;
    }

    // 2. Check environment variable (running on Pantheon).
    $envSite = getenv('PANTHEON_SITE');
    if ($envSite && is_string($envSite)) {
      $this->siteName = $envSite;
      return $this->siteName;
    }

    // 3. Check git remote.
    $gitName = $this->detectFromGitRemote();
    if ($gitName) {
      $this->siteName = $gitName;
      return $this->siteName;
    }

    // 4. Parse pantheon.yml if it exists.
    $ymlName = $this->detectFromPantheonYml();
    if ($ymlName) {
      $this->siteName = $ymlName;
      return $this->siteName;
    }

    $this->siteName = '';
    return NULL;
  }

  /**
   * Gets the URL for a Pantheon environment.
   *
   * @param string $env
   *   Environment: 'dev', 'test', or 'live'.
   *
   * @return string|null
   *   The URL or NULL if site name not detected.
   */
  public function getEnvironmentUrl(string $env): ?string {
    $siteName = $this->getSiteName();
    if (!$siteName) {
      return NULL;
    }

    // Standard Pantheon URL pattern.
    return "https://{$env}-{$siteName}.pantheonsite.io";
  }

  /**
   * Gets all environment URLs.
   *
   * @return array<string, string>
   *   Keyed by environment name.
   */
  public function getAllEnvironmentUrls(): array {
    $siteName = $this->getSiteName();
    if (!$siteName) {
      return [];
    }

    return [
      'dev' => $this->getEnvironmentUrl('dev'),
      'test' => $this->getEnvironmentUrl('test'),
      'live' => $this->getEnvironmentUrl('live'),
    ];
  }

  /**
   * Checks if the site appears to be a Pantheon project.
   *
   * @return bool
   *   TRUE if Pantheon project detected.
   */
  public function isPantheonProject(): bool {
    // Check for pantheon.yml or pantheon.upstream.yml.
    $projectRoot = DRUPAL_ROOT . '/..';
    if (is_file($projectRoot . '/pantheon.yml') || is_file($projectRoot . '/pantheon.upstream.yml')) {
      return TRUE;
    }

    // Check for Pantheon git remote.
    return $this->detectFromGitRemote() !== NULL;
  }

  /**
   * Detects site name from git remote named 'pantheon'.
   *
   * Pantheon git URLs look like:
   * ssh://codeserver.dev.{uuid}@codeserver.dev.{uuid}.drush.in:2222/~/repository.git
   * or
   * git@github.com:pantheon-systems/{site-name}.git (for some integrated repos)
   *
   * We'll try to extract from the remote URL or use the directory name.
   *
   * @return string|null
   *   The site name or NULL.
   */
  private function detectFromGitRemote(): ?string {
    $projectRoot = DRUPAL_ROOT . '/..';

    // Try to get the pantheon remote URL.
    $gitConfigFile = $projectRoot . '/.git/config';
    if (!is_file($gitConfigFile)) {
      return NULL;
    }

    $gitConfig = file_get_contents($gitConfigFile);
    if ($gitConfig === FALSE) {
      return NULL;
    }

    // Look for [remote "pantheon"] section.
    if (preg_match('/\[remote "pantheon"\][^\[]*url\s*=\s*([^\n]+)/s', $gitConfig, $matches)) {
      $url = trim($matches[1]);

      // Try to extract site name from various Pantheon URL patterns.
      // Pattern: ssh://codeserver.dev.{uuid}@codeserver.dev.{uuid}.drush.in
      // The UUID doesn't help us, but we can use the project directory name.
      if (str_contains($url, 'codeserver.dev') || str_contains($url, 'drush.in')) {
        // Pantheon remote exists - use directory name as site name.
        $dirName = basename(realpath($projectRoot) ?: $projectRoot);
        // Clean up common suffixes.
        $dirName = preg_replace('/[-_](drupal|site|web)$/i', '', $dirName);
        return $dirName ?: NULL;
      }
    }

    return NULL;
  }

  /**
   * Detects site name from pantheon.yml.
   *
   * @return string|null
   *   The site name or NULL.
   */
  private function detectFromPantheonYml(): ?string {
    $projectRoot = DRUPAL_ROOT . '/..';
    $ymlFile = $projectRoot . '/pantheon.yml';

    if (!is_file($ymlFile)) {
      return NULL;
    }

    // pantheon.yml doesn't typically contain site name.
    // Fall back to directory name if pantheon.yml exists.
    $dirName = basename(realpath($projectRoot) ?: $projectRoot);
    $dirName = preg_replace('/[-_](drupal|site|web)$/i', '', $dirName);
    return $dirName ?: NULL;
  }

  /**
   * Clears the cached site name (useful after config changes).
   */
  public function clearCache(): void {
    $this->siteName = NULL;
    $this->terminusStatus = NULL;
  }

  /**
   * Checks if Terminus CLI is installed.
   *
   * @return bool
   *   TRUE if Terminus is available.
   */
  public function isTerminusInstalled(): bool {
    $status = $this->getTerminusStatus();
    return $status['installed'];
  }

  /**
   * Checks if user is authenticated with Terminus.
   *
   * @return bool
   *   TRUE if authenticated.
   */
  public function isTerminusAuthenticated(): bool {
    $status = $this->getTerminusStatus();
    return $status['authenticated'];
  }

  /**
   * Gets the authenticated Terminus user email.
   *
   * @return string|null
   *   The email or NULL if not authenticated.
   */
  public function getTerminusUser(): ?string {
    $status = $this->getTerminusStatus();
    return $status['user'];
  }

  /**
   * Gets Terminus status including installation and auth.
   *
   * @return array{installed: bool, authenticated: bool, user: string|null, version: string|null}
   *   Status array.
   */
  public function getTerminusStatus(): array {
    if ($this->terminusStatus !== NULL) {
      return $this->terminusStatus;
    }

    $this->terminusStatus = [
      'installed' => FALSE,
      'authenticated' => FALSE,
      'user' => NULL,
      'version' => NULL,
    ];

    // Check if terminus is installed.
    $which = new Process(['which', 'terminus']);
    $which->setTimeout(5);
    $which->run();

    if (!$which->isSuccessful()) {
      return $this->terminusStatus;
    }

    $this->terminusStatus['installed'] = TRUE;

    // Get version.
    $version = new Process(['terminus', '--version']);
    $version->setTimeout(5);
    $version->run();
    if ($version->isSuccessful()) {
      $output = trim($version->getOutput());
      // Output like "Terminus 3.2.1"
      if (preg_match('/(\d+\.\d+\.\d+)/', $output, $matches)) {
        $this->terminusStatus['version'] = $matches[1];
      }
    }

    // Check auth status.
    $auth = new Process(['terminus', 'auth:whoami']);
    $auth->setTimeout(10);
    $auth->run();

    if ($auth->isSuccessful()) {
      $output = trim($auth->getOutput());
      // Output looks like: "You are authenticated as: user@example.com"
      // or just the email on newer versions.
      if (preg_match('/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/', $output, $matches)) {
        $this->terminusStatus['authenticated'] = TRUE;
        $this->terminusStatus['user'] = $matches[1];
      }
      elseif (!str_contains($output, 'not logged in') && $output !== '') {
        // Some output that's not "not logged in" - assume authenticated.
        $this->terminusStatus['authenticated'] = TRUE;
        $this->terminusStatus['user'] = $output;
      }
    }

    return $this->terminusStatus;
  }

  /**
   * Gets site information from Terminus (if authenticated).
   *
   * @param string $siteName
   *   The site name to look up.
   *
   * @return array|null
   *   Site info array or NULL if not available.
   */
  public function getSiteInfoFromTerminus(string $siteName): ?array {
    if (!$this->isTerminusAuthenticated()) {
      return NULL;
    }

    $process = new Process(['terminus', 'site:info', $siteName, '--format=json']);
    $process->setTimeout(30);
    $process->run();

    if (!$process->isSuccessful()) {
      return NULL;
    }

    $output = trim($process->getOutput());
    $data = json_decode($output, TRUE);

    return is_array($data) ? $data : NULL;
  }

  /**
   * Gets environment status from Terminus.
   *
   * @param string $siteName
   *   The Pantheon site name.
   * @param string $env
   *   The environment (dev, test, live).
   *
   * @return array|null
   *   Environment info or NULL.
   */
  public function getEnvironmentInfo(string $siteName, string $env): ?array {
    if (!$this->isTerminusAuthenticated()) {
      return NULL;
    }

    $process = new Process(['terminus', 'env:info', "{$siteName}.{$env}", '--format=json']);
    $process->setTimeout(30);
    $process->run();

    if (!$process->isSuccessful()) {
      return NULL;
    }

    $output = trim($process->getOutput());
    $data = json_decode($output, TRUE);

    return is_array($data) ? $data : NULL;
  }

  /**
   * Lists all sites the user has access to via Terminus.
   *
   * @return array<string, array>
   *   Array of sites keyed by name.
   */
  public function listTerminusSites(): array {
    if (!$this->isTerminusAuthenticated()) {
      return [];
    }

    $process = new Process(['terminus', 'site:list', '--format=json']);
    $process->setTimeout(60);
    $process->run();

    if (!$process->isSuccessful()) {
      return [];
    }

    $output = trim($process->getOutput());
    $data = json_decode($output, TRUE);

    return is_array($data) ? $data : [];
  }

}
