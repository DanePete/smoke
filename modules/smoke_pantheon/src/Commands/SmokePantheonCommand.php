<?php

declare(strict_types=1);

namespace Drupal\smoke_pantheon\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\smoke\Service\ModuleDetector;
use Drupal\smoke\Service\TestRunner;
use Drupal\smoke_pantheon\Service\PantheonSiteDetector;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\Process\Process;

/**
 * Drush commands for testing Pantheon environments.
 */
final class SmokePantheonCommand extends DrushCommands {

  /**
   * Constructs the SmokePantheonCommand.
   */
  public function __construct(
    private readonly PantheonSiteDetector $siteDetector,
    private readonly TestRunner $testRunner,
    private readonly ModuleDetector $moduleDetector,
    private readonly ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct();
  }

  #[CLI\Command(name: 'smoke:pantheon', aliases: ['sp'])]
  #[CLI\Help(description: 'Show detected Pantheon environments and run tests.')]
  #[CLI\Argument(name: 'env', description: 'Environment to test: dev, test, live, or all.')]
  #[CLI\Option(name: 'quick', description: 'Fast mode — one test per suite.')]
  #[CLI\Option(name: 'suite', description: 'Run only this suite (e.g., core_pages).')]
  #[CLI\Usage(name: 'drush smoke:pantheon', description: 'Show detected Pantheon site and environments.')]
  #[CLI\Usage(name: 'drush smoke:pantheon dev', description: 'Run smoke tests on dev environment.')]
  #[CLI\Usage(name: 'drush smoke:pantheon test', description: 'Run smoke tests on test environment.')]
  #[CLI\Usage(name: 'drush smoke:pantheon live', description: 'Run smoke tests on live environment.')]
  #[CLI\Usage(name: 'drush smoke:pantheon all', description: 'Run smoke tests on all environments sequentially.')]
  #[CLI\Usage(name: 'drush smoke:pantheon dev --quick', description: 'Quick sanity check on dev.')]
  /**
   * Shows Pantheon environments or runs tests against them.
   *
   * @param string|null $env
   *   Environment to test: dev, test, live, or all.
   * @param array $options
   *   Command options.
   */
  public function pantheon(?string $env = NULL, array $options = ['quick' => FALSE, 'suite' => NULL]): void {
    if (!$this->siteDetector->isPantheonProject()) {
      $this->io()->error('This does not appear to be a Pantheon project.');
      $this->io()->text('  No pantheon.yml or Pantheon git remote detected.');
      $this->io()->newLine();
      $this->io()->text('  To configure manually:');
      $this->io()->text('  <options=bold>drush config:set smoke_pantheon.settings site_name your-site-name</>');
      return;
    }

    $siteName = $this->siteDetector->getSiteName();
    if (!$siteName) {
      $this->io()->error('Could not detect Pantheon site name.');
      $this->io()->newLine();
      $this->io()->text('  Configure manually:');
      $this->io()->text('  <options=bold>drush config:set smoke_pantheon.settings site_name your-site-name</>');
      return;
    }

    $urls = $this->siteDetector->getAllEnvironmentUrls();

    // No environment specified — show status.
    if ($env === NULL) {
      $this->showStatus($siteName, $urls);
      return;
    }

    // Validate environment.
    $env = strtolower($env);
    if ($env === 'all') {
      $this->runAllEnvironments($urls, $options);
      return;
    }

    if (!isset($urls[$env])) {
      $this->io()->error("Invalid environment: {$env}");
      $this->io()->text('  Valid options: dev, test, live, all');
      return;
    }

    $this->runEnvironment($env, $urls[$env], $options);
  }

  #[CLI\Command(name: 'smoke:pantheon:set', aliases: ['sps'])]
  #[CLI\Help(description: 'Set the Pantheon site name for smoke tests.')]
  #[CLI\Argument(name: 'siteName', description: 'The Pantheon site machine name.')]
  #[CLI\Usage(name: 'drush smoke:pantheon:set my-site', description: 'Set site name to my-site.')]
  /**
   * Sets the Pantheon site name.
   *
   * @param string $siteName
   *   The site name.
   */
  public function setSiteName(string $siteName): void {
    $config = $this->configFactory->getEditable('smoke_pantheon.settings');
    $config->set('site_name', $siteName)->save();
    $this->siteDetector->clearCache();

    $this->io()->success("Pantheon site name set to: {$siteName}");
    $this->io()->newLine();

    $urls = $this->siteDetector->getAllEnvironmentUrls();
    $this->io()->text('  Detected environments:');
    foreach ($urls as $env => $url) {
      $this->io()->text("    <fg=cyan>{$env}</>  {$url}");
    }
    $this->io()->newLine();
    $this->io()->text('  Test with: <options=bold>drush smoke:pantheon dev</>');
  }

  #[CLI\Command(name: 'smoke:pantheon:check', aliases: ['spc'])]
  #[CLI\Help(description: 'Validate Pantheon site and environment status via Terminus.')]
  #[CLI\Usage(name: 'drush smoke:pantheon:check', description: 'Check site and all environments.')]
  /**
   * Validates the Pantheon site configuration using Terminus.
   */
  public function check(): void {
    $this->io()->newLine();
    $this->io()->text('  <options=bold>Smoke — Pantheon Validation</>');
    $this->io()->text('  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
    $this->io()->newLine();

    // Check Terminus.
    $terminusStatus = $this->siteDetector->getTerminusStatus();

    if (!$terminusStatus['installed']) {
      $this->io()->text('  <fg=red>✕</> Terminus not installed');
      $this->io()->newLine();
      $this->io()->text('  Install Terminus:');
      $this->io()->text('  <options=bold>brew install pantheon-systems/external/terminus</>');
      $this->io()->newLine();
      $this->io()->text('  Or see: https://pantheon.io/docs/terminus/install');
      $this->io()->newLine();
      return;
    }

    $version = $terminusStatus['version'] ?? 'unknown';
    $this->io()->text("  <fg=green>✓</> Terminus installed (v{$version})");

    if (!$terminusStatus['authenticated']) {
      $this->io()->text('  <fg=red>✕</> Terminus not authenticated');
      $this->io()->newLine();
      $this->io()->text('  Authenticate with:');
      $this->io()->text('  <options=bold>terminus auth:login</>');
      $this->io()->newLine();
      return;
    }

    $user = $terminusStatus['user'] ?? 'unknown';
    $this->io()->text("  <fg=green>✓</> Authenticated as: <fg=cyan>{$user}</>");
    $this->io()->newLine();

    // Check site.
    $siteName = $this->siteDetector->getSiteName();
    if (!$siteName) {
      $this->io()->text('  <fg=red>✕</> Could not detect site name');
      $this->io()->text('  Set manually: <options=bold>drush smoke:pantheon:set site-name</>');
      $this->io()->newLine();
      return;
    }

    $this->io()->text("  Validating site: <options=bold>{$siteName}</>");
    $this->io()->newLine();

    // Get site info from Terminus.
    $siteInfo = $this->siteDetector->getSiteInfoFromTerminus($siteName);
    if (!$siteInfo) {
      $this->io()->text("  <fg=red>✕</> Site '{$siteName}' not found on Pantheon");
      $this->io()->text("     You may not have access, or the name is incorrect.");
      $this->io()->newLine();
      $this->io()->text('  List your accessible sites:');
      $this->io()->text('  <options=bold>terminus site:list</>');
      $this->io()->newLine();
      return;
    }

    $this->io()->text("  <fg=green>✓</> Site found: <options=bold>{$siteInfo['name']}</>");
    if (!empty($siteInfo['label'])) {
      $this->io()->text("     Label: {$siteInfo['label']}");
    }
    if (!empty($siteInfo['framework'])) {
      $this->io()->text("     Framework: {$siteInfo['framework']}");
    }
    if (!empty($siteInfo['plan_name'])) {
      $this->io()->text("     Plan: {$siteInfo['plan_name']}");
    }
    $this->io()->newLine();

    // Check each environment.
    $this->io()->text('  <options=bold>Environment Status</>');
    $this->io()->newLine();

    foreach (['dev', 'test', 'live'] as $env) {
      $envInfo = $this->siteDetector->getEnvironmentInfo($siteName, $env);
      $url = $this->siteDetector->getEnvironmentUrl($env);

      if ($envInfo) {
        $initialized = !empty($envInfo['initialized']) ? 'yes' : 'no';
        $locked = !empty($envInfo['lock']) ? ' <fg=yellow>[locked]</>' : '';
        $this->io()->text("     <fg=green>✓</> <fg=cyan>{$env}</>{$locked}  {$url}");
      }
      else {
        $this->io()->text("     <fg=gray>○</> <fg=cyan>{$env}</>  {$url}  <fg=gray>(not initialized or no access)</>");
      }
    }

    $this->io()->newLine();
    $this->io()->text('  <fg=green;options=bold>Validation complete.</>');
    $this->io()->text('  Run tests: <options=bold>drush smoke:pantheon dev</>');
    $this->io()->newLine();
  }

  #[CLI\Command(name: 'smoke:pantheon:sites')]
  #[CLI\Help(description: 'List all Pantheon sites you have access to.')]
  #[CLI\Usage(name: 'drush smoke:pantheon:sites', description: 'List accessible Pantheon sites.')]
  /**
   * Lists all Pantheon sites accessible via Terminus.
   */
  public function listSites(): void {
    if (!$this->siteDetector->isTerminusInstalled()) {
      $this->io()->error('Terminus is not installed.');
      $this->io()->text('  Install: <options=bold>brew install pantheon-systems/external/terminus</>');
      return;
    }

    if (!$this->siteDetector->isTerminusAuthenticated()) {
      $this->io()->error('Terminus is not authenticated.');
      $this->io()->text('  Run: <options=bold>terminus auth:login</>');
      return;
    }

    $this->io()->newLine();
    $this->io()->text('  <options=bold>Your Pantheon Sites</>');
    $this->io()->text('  ━━━━━━━━━━━━━━━━━━━━━');
    $this->io()->newLine();

    $sites = $this->siteDetector->listTerminusSites();

    if (empty($sites)) {
      $this->io()->text('  No sites found.');
      $this->io()->newLine();
      return;
    }

    foreach ($sites as $site) {
      $name = $site['name'] ?? 'unknown';
      $label = $site['label'] ?? '';
      $framework = $site['framework'] ?? '';
      $frozen = !empty($site['frozen']) ? ' <fg=blue>[frozen]</>' : '';

      $this->io()->text("     <fg=cyan>{$name}</>{$frozen}");
      if ($label && $label !== $name) {
        $this->io()->text("       {$label}");
      }
    }

    $this->io()->newLine();
    $this->io()->text('  Use a site: <options=bold>drush smoke:pantheon:set site-name</>');
    $this->io()->newLine();
  }

  /**
   * Shows the current Pantheon status and available commands.
   *
   * @param string $siteName
   *   The detected site name.
   * @param array<string, string> $urls
   *   Environment URLs.
   */
  private function showStatus(string $siteName, array $urls): void {
    $this->io()->newLine();
    $this->io()->text('  <options=bold>Smoke — Pantheon</>');
    $this->io()->text('  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
    $this->io()->newLine();

    $this->io()->text("  <fg=green>✓</> Site detected: <options=bold>{$siteName}</>");

    // Terminus status.
    $terminusStatus = $this->siteDetector->getTerminusStatus();
    if ($terminusStatus['installed']) {
      if ($terminusStatus['authenticated']) {
        $user = $terminusStatus['user'] ?? 'unknown';
        $this->io()->text("  <fg=green>✓</> Terminus: authenticated as <fg=cyan>{$user}</>");
      }
      else {
        $this->io()->text('  <fg=yellow>○</> Terminus: installed but not authenticated');
      }
    }
    else {
      $this->io()->text('  <fg=gray>○</> Terminus: not installed');
    }
    $this->io()->newLine();

    $this->io()->text('  <options=bold>Environments</>');
    $this->io()->newLine();

    foreach ($urls as $env => $url) {
      $envLabel = str_pad($env, 5);
      $this->io()->text("     <fg=cyan>{$envLabel}</>  {$url}");
    }

    $this->io()->newLine();
    $this->io()->text('  <options=bold>Commands</>');
    $this->io()->newLine();
    $this->io()->text('     <options=bold>drush smoke:pantheon dev</>     Test dev environment');
    $this->io()->text('     <options=bold>drush smoke:pantheon test</>    Test test environment');
    $this->io()->text('     <options=bold>drush smoke:pantheon live</>    Test live environment');
    $this->io()->text('     <options=bold>drush smoke:pantheon all</>     Test all environments');
    $this->io()->newLine();
    $this->io()->text('  <options=bold>Options</>');
    $this->io()->newLine();
    $this->io()->text('     <fg=gray>--quick</>               Fast mode (one test per suite)');
    $this->io()->text('     <fg=gray>--suite=core_pages</>    Run only one suite');
    $this->io()->newLine();

    // Terminus help if not set up.
    if (!$terminusStatus['installed']) {
      $this->io()->text('  <fg=yellow;options=bold>Tip: Install Terminus for enhanced Pantheon integration</>');
      $this->io()->text('  <fg=gray>brew install pantheon-systems/external/terminus</>');
      $this->io()->text('  <fg=gray>https://pantheon.io/docs/terminus/install</>');
      $this->io()->newLine();
    }
    elseif (!$terminusStatus['authenticated']) {
      $this->io()->text('  <fg=yellow;options=bold>Tip: Authenticate Terminus for site validation</>');
      $this->io()->text('  <fg=gray>terminus auth:login</>');
      $this->io()->newLine();
    }

    // Check if site name was auto-detected or configured.
    $config = $this->configFactory->get('smoke_pantheon.settings');
    $configuredName = $config->get('site_name');
    if (!$configuredName) {
      $this->io()->text('  <fg=gray>Note: Site name auto-detected from project directory.</>');
      $this->io()->text('  <fg=gray>If incorrect: drush smoke:pantheon:set correct-site-name</>');
      $this->io()->newLine();
    }
  }

  /**
   * Runs tests against a single environment.
   *
   * @param string $env
   *   Environment name.
   * @param string $url
   *   Environment URL.
   * @param array $options
   *   Command options.
   */
  private function runEnvironment(string $env, string $url, array $options): void {
    $this->io()->newLine();
    $envUpper = strtoupper($env);
    $this->io()->text("  <fg=magenta;options=bold>◆ PANTHEON {$envUpper}</>");
    $this->io()->text("  <fg=gray>{$url}</>");
    $this->io()->newLine();

    // Build the drush smoke --run command.
    $cmd = ['drush', 'smoke', '--run', "--target={$url}"];

    if ($options['quick']) {
      $cmd[] = '--quick';
    }

    if ($options['suite']) {
      $cmd[] = "--suite={$options['suite']}";
    }

    $process = new Process($cmd, DRUPAL_ROOT . '/..');
    $process->setTimeout(300);
    $process->run(function ($type, $buffer): void {
      $this->io()->write($buffer);
    });
  }

  /**
   * Runs tests against all environments sequentially.
   *
   * @param array<string, string> $urls
   *   Environment URLs.
   * @param array $options
   *   Command options.
   */
  private function runAllEnvironments(array $urls, array $options): void {
    $this->io()->newLine();
    $this->io()->text('  <options=bold>Smoke — Testing All Pantheon Environments</>');
    $this->io()->text('  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
    $this->io()->newLine();

    $results = [];
    foreach (['dev', 'test', 'live'] as $env) {
      if (!isset($urls[$env])) {
        continue;
      }

      $this->runEnvironment($env, $urls[$env], $options);
      // Could capture results here for summary.
    }

    $this->io()->newLine();
    $this->io()->text('  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
    $this->io()->text('  <fg=green;options=bold>All environments tested.</>');
    $this->io()->newLine();
  }

}
