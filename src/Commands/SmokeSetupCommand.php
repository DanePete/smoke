<?php

declare(strict_types=1);

namespace Drupal\smoke\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\smoke\Service\ConfigGenerator;
use Drupal\smoke\Service\ModuleDetector;
use Drupal\smoke\Service\TestRunner;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Process\Process;

/**
 * Sets up the entire Playwright test environment from scratch.
 *
 * Installs the DDEV addon, npm deps, browsers, generates config,
 * and verifies everything works — one command.
 */
final class SmokeSetupCommand extends DrushCommands {

  /**
   * Constructs the SmokeSetupCommand.
   *
   * @param \Drupal\smoke\Service\TestRunner $testRunner
   *   The test runner service.
   * @param \Drupal\smoke\Service\ConfigGenerator $configGenerator
   *   The config generator service.
   * @param \Drupal\smoke\Service\ModuleDetector $moduleDetector
   *   The module detector service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    private readonly TestRunner $testRunner,
    private readonly ConfigGenerator $configGenerator,
    private readonly ModuleDetector $moduleDetector,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly StateInterface $state,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('smoke.test_runner'),
      $container->get('smoke.config_generator'),
      $container->get('smoke.module_detector'),
      $container->get('module_handler'),
      $container->get('state'),
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
    );
  }

  #[CLI\Command(name: 'smoke:setup')]
  #[CLI\Help(description: 'Set up Playwright from scratch: DDEV addon, browsers, npm deps, test config.')]
  #[CLI\Option(name: 'silent', description: 'Suppress output (used by DDEV post-start hook).')]
  /**
   * Sets up Playwright: npm deps, Chromium, config, smoke_bot user.
   *
   * @param array $options
   *   Options including 'silent' to suppress output.
   */
  public function setup(array $options = ['silent' => FALSE]): void {
    $quiet = (bool) $options['silent'];

    if (!$quiet) {
      $this->io()->newLine();
      $this->io()->text('  <options=bold>Smoke — Setup</>');
      $this->io()->text('  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
      $this->io()->newLine();
    }

    $modulePath = $this->configGenerator->getModulePath();
    $playwrightDir = $modulePath . '/playwright';
    $projectRoot = DRUPAL_ROOT . '/..';

    // Step 1: Check DDEV.
    if (!$quiet) {
      $this->step('Checking environment...');
    }
    $isDdev = getenv('IS_DDEV_PROJECT') === 'true';
    if (!$isDdev) {
      if (!$quiet) {
        $this->io()->error('This command must be run inside DDEV (ddev drush smoke:setup).');
      }
      return;
    }
    if (!$quiet) {
      $this->ok('DDEV detected.');
    }

    // Step 2: Check Node.js is available and version >= 20.
    if (!$quiet) {
      $this->step('Checking Node.js...');
    }
    $nodeCheck = new Process(['node', '--version'], $projectRoot);
    $nodeCheck->setTimeout(10);
    $nodeCheck->run();
    if (!$nodeCheck->isSuccessful()) {
      if (!$quiet) {
        $this->io()->error('Node.js is not installed. Install Node.js 20+ to continue.');
      }
      return;
    }
    $nodeVersion = trim($nodeCheck->getOutput());
    // Parse version like "v22.21.1" or "v20.0.0".
    if (preg_match('/^v?(\d+)\./', $nodeVersion, $matches)) {
      $majorVersion = (int) $matches[1];
      if ($majorVersion < 20) {
        if (!$quiet) {
          $this->io()->error("Node.js $nodeVersion is too old. Smoke requires Node.js 20 or higher.");
          $this->io()->text('    Use Node 20: <options=bold>nvm use 20</> or https://nodejs.org/');
        }
        return;
      }
    }
    if (!$quiet) {
      $this->ok('Node.js ' . $nodeVersion);
    }

    // Step 3: Install npm dependencies.
    if (!$quiet) {
      $this->step('Installing npm dependencies...');
    }
    if (!is_file($playwrightDir . '/package.json')) {
      if (!$quiet) {
        $this->io()->error('package.json not found at: ' . $playwrightDir);
      }
      return;
    }

    $npm = new Process(['npm', 'install'], $playwrightDir);
    $npm->setTimeout(120);
    $npm->run();
    if ($npm->isSuccessful()) {
      if (!$quiet) {
        $this->ok('Dependencies installed.');
      }
    }
    else {
      if (!$quiet) {
        $this->io()->error('npm install failed: ' . $npm->getErrorOutput());
      }
      return;
    }

    // Step 4: Install Chromium browser + system deps (if missing).
    if (!$quiet) {
      $this->step('Checking Chromium browser...');
    }
    $browserInstalled = $this->isBrowserInstalled($playwrightDir);
    if ($browserInstalled) {
      if (!$quiet) {
        $this->ok('Chromium already installed.');
      }
    }
    else {
      if (!$quiet) {
        $this->io()->text('    Installing Chromium (one-time download, ~180 MiB)...');
      }

      // Install Chromium browser binary.
      $installBrowser = new Process(
        ['npx', 'playwright', 'install', 'chromium'],
        $playwrightDir,
      );
      $installBrowser->setTimeout(300);
      $installBrowser->run();
      if (!$installBrowser->isSuccessful()) {
        if (!$quiet) {
          $this->io()->error('Browser install failed: ' . $installBrowser->getErrorOutput());
        }
        return;
      }
      if (!$quiet) {
        $this->ok('Chromium browser installed.');
      }
    }

    // Always ensure system dependencies are installed (idempotent).
    // Required for browser launch; after "Chromium already installed" we
    // used to skip this, so deps were missing after ddev restart.
    if (!$quiet) {
      $this->step('Ensuring system dependencies...');
    }
    if (!$this->installSystemDeps($playwrightDir, $quiet)) {
      if (!$quiet) {
        $this->warn('Could not install system deps automatically.');
        $this->io()->text(
          '    Run manually: <options=bold>ddev exec "sudo npx playwright install-deps chromium"</>'
        );
      }
    }

    // Step 5: Configure webform for smoke tests (interactive if Webform on).
    if (!$quiet) {
      if ($this->moduleHandler->moduleExists('webform')) {
        $this->configureWebformId();
      }
      else {
        $this->step('Webform module...');
        $this->io()->text('    <fg=gray>Not installed — skipping webform configuration.</>');
        $this->io()->text('    <fg=gray>Enable webform module and re-run setup to configure.</>');
      }
    }

    // Step 6: Generate config.
    if (!$quiet) {
      $this->step('Generating test config...');
    }
    $this->configGenerator->writeConfig();
    if (!$quiet) {
      $this->ok('Config written.');
    }

    // Step 7: Verify test user and ensure permissions.
    if (!$quiet) {
      $this->step('Verifying smoke_bot test user...');
    }
    $password = $this->state->get('smoke.bot_password');
    if ($password) {
      if (!$quiet) {
        $this->ok('smoke_bot ready.');
      }
      // Ensure content permissions are granted (added in later versions).
      $role = $this->entityTypeManager->getStorage('user_role')->load('smoke_bot');
      if ($role) {
        $contentPerms = [
          'create page content',
          'edit own page content',
          'delete own page content',
          'administer site configuration',
        ];
        $changed = FALSE;
        foreach ($contentPerms as $perm) {
          if (!$role->hasPermission($perm)) {
            $role->grantPermission($perm);
            $changed = TRUE;
          }
        }
        if ($changed) {
          $role->save();
          if (!$quiet) {
            $this->ok('Content permissions granted to smoke_bot.');
          }
        }
      }
    }
    elseif (!$quiet) {
      $this->warn(
        'smoke_bot not found. Reinstall: drush pmu smoke && drush en smoke'
      );
    }

    // Step 8: DDEV post-start hook (config regens on start).
    $hookFile = $projectRoot . '/.ddev/config.smoke.yaml';
    if ($isDdev && is_dir($projectRoot . '/.ddev') && !is_file($hookFile)) {
      $yaml = <<<'YAML'
# Auto-generated by Smoke module (drush smoke:setup).
# Regenerates Smoke test config on every ddev start/restart.
hooks:
  post-start:
    - exec: drush smoke:setup --silent 2>/dev/null || true
YAML;
      file_put_contents($hookFile, $yaml);
      if (!$quiet) {
        $this->ok('DDEV post-start hook installed — config auto-regenerates on ddev start.');
      }
    }
    elseif ($isDdev && is_file($hookFile) && !$quiet) {
      $this->ok('DDEV hook already present.');
    }

    // Step 9: Offer to add Composer post-update/install scripts.
    if (!$quiet) {
      $this->configureComposerScripts($projectRoot);
    }

    // Step 10: Sanity check — list tests.
    if (!$quiet) {
      $this->step('Verifying test suites...');
      $check = new Process(['npx', 'playwright', 'test', '--list'], $playwrightDir);
      $check->setTimeout(30);
      $check->run();
      if ($check->isSuccessful()) {
        $lines = array_filter(explode("\n", trim($check->getOutput())));
        $count = count($lines);
        $this->ok("{$count} tests found.");
      }
      else {
        $this->warn('Could not list tests: ' . trim($check->getErrorOutput()));
      }
    }

    // Done.
    if (!$quiet) {
      $this->io()->newLine();
      $this->io()->text('  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
      $this->io()->text('  <fg=green;options=bold>Setup complete.</>');      
      // One-time agency tip: mention global installation on first setup.
      $this->showAgencyTip($playwrightDir);
      $this->io()->newLine();
      $this->io()->text('  Commands:');
      $this->io()->text('    <options=bold>drush smoke</>               Run all tests');
      $this->io()->text('    <options=bold>drush smoke:list</>          See detected suites');
      $this->io()->text('    <options=bold>drush smoke:suite webform</>  Run one suite');
      $this->io()->newLine();
    }
  }

  /**
   * Shows a one-time tip about global Playwright installation for agencies.
   *
   * @param string $playwrightDir
   *   Path to the Playwright directory.
   */
  private function showAgencyTip(string $playwrightDir): void {
    // Only show once per project.
    $markerFile = DRUPAL_ROOT . '/../.ddev/.smoke-agency-tip-shown';
    if (is_file($markerFile)) {
      return;
    }

    // Check if using global Playwright (environment variable set).
    $globalPath = getenv('PLAYWRIGHT_BROWSERS_PATH');
    if ($globalPath && is_dir($globalPath)) {
      // Already using global — no tip needed.
      return;
    }

    // Show the tip.
    $this->io()->newLine();
    $this->io()->text('  <fg=cyan;options=bold>Tip: Managing multiple Drupal sites?</>');    
    $this->io()->text('  <fg=gray>Save ~180 MiB per project by installing Playwright globally:</>');
    $modulePath = $this->configGenerator->getModulePath();
    $this->io()->text("  <options=bold>bash {$modulePath}/scripts/global-setup.sh</>");
    $this->io()->text('  <fg=gray>Enables VS Code/Cursor extension support across all sites.</>');

    // Mark as shown.
    @file_put_contents($markerFile, date('c'));
  }

  /**
   * Checks if Chromium is installed in the Playwright cache.
   */
  private function isBrowserInstalled(string $playwrightDir): bool {
    // Quick check: try to launch a browser validation.
    $check = new Process(
      ['npx', 'playwright', 'install', '--dry-run', 'chromium'],
      $playwrightDir,
    );
    $check->setTimeout(15);
    $check->run();
    $output = $check->getOutput() . $check->getErrorOutput();

    // If dry-run says "browser is already installed" or exits 0 with no
    // download needed, the browser is present. Fall back to checking the
    // cache directory directly.
    if ($check->isSuccessful() && str_contains($output, 'already installed')) {
      return TRUE;
    }

    // Direct cache check: look for chromium-* in the Playwright cache.
    $cacheDir = getenv('PLAYWRIGHT_BROWSERS_PATH')
      ?: (getenv('HOME') . '/.cache/ms-playwright');
    if (is_dir($cacheDir)) {
      $entries = @scandir($cacheDir) ?: [];
      foreach ($entries as $entry) {
        if (str_starts_with($entry, 'chromium-') && is_dir($cacheDir . '/' . $entry)) {
          return TRUE;
        }
      }
    }

    return FALSE;
  }

  /**
   * Outputs a step message.
   *
   * @param string $message
   *   The message.
   */
  private function step(string $message): void {
    $this->io()->text("  <fg=blue>▸</> {$message}");
  }

  /**
   * Outputs an OK message.
   *
   * @param string $message
   *   The message.
   */
  private function ok(string $message): void {
    $this->io()->text("    <fg=green>✓</> {$message}");
  }

  /**
   * Outputs a warning message.
   *
   * @param string $message
   *   The message.
   */
  private function warn(string $message): void {
    $this->io()->text("    <fg=yellow>⚠</> {$message}");
  }

  /**
   * Asks for webform machine name, creates it if missing, removes smoke_test.
   */
  private function configureWebformId(): void {
    $this->step('Configuring webform for smoke tests...');

    $config = $this->configFactory->getEditable('smoke.settings');
    $current = (string) ($config->get('webform_id') ?? 'smoke_test');

    $answer = $this->io()->ask(
      '  Webform machine name for smoke tests (e.g. contact_us, smoke_test)',
      $current,
    );
    $raw = is_string($answer) ? trim($answer) : $current;
    $id = $raw !== ''
      ? strtolower((string) preg_replace('/[^a-z0-9_]/', '_', $raw))
      : $current;
    if ($id === '') {
      $id = 'smoke_test';
    }

    $created = $this->moduleDetector->createWebformIfMissing($id);
    if ($created) {
      $this->ok("Webform <options=bold>{$id}</> created (Name, Email, Message).");
    }
    else {
      $storage = $this->entityTypeManager->getStorage('webform');
      if ($storage->load($id)) {
        $this->ok("Webform <options=bold>{$id}</> already exists.");
      }
      else {
        $this->warn("Webform <options=bold>{$id}</> not found and could not be created.");
      }
    }

    if ($id !== 'smoke_test') {
      $this->moduleDetector->removeSmokeTestWebform();
      $this->ok('Legacy smoke_test webform removed.');
    }

    $config->set('webform_id', $id)->save();
    $this->ok("Smoke will use webform: <options=bold>{$id}</>.");
  }

  /**
   * Offers to add Composer post-update/install scripts to the project root.
   *
   * Appends drush cr + drush smoke --run so tests run automatically after
   * composer install or composer update. Skips if already present.
   */
  private function configureComposerScripts(string $projectRoot): void {
    $composerFile = $projectRoot . '/composer.json';
    if (!is_file($composerFile)) {
      return;
    }

    $json = file_get_contents($composerFile);
    if ($json === FALSE) {
      return;
    }

    $data = json_decode($json, TRUE);
    if (!is_array($data)) {
      return;
    }

    $smokeCmd = './vendor/bin/drush smoke --run';
    $crCmd = './vendor/bin/drush cr';

    // Check if smoke is already wired up in either hook.
    $postUpdate = $data['scripts']['post-update-cmd'] ?? [];
    $postInstall = $data['scripts']['post-install-cmd'] ?? [];

    if (is_array($postUpdate) && in_array($smokeCmd, $postUpdate, TRUE)) {
      $this->ok('Composer post-update-cmd already includes smoke tests.');
      return;
    }

    $this->step('Composer scripts...');
    $answer = $this->io()->confirm(
      '  Add smoke tests to composer post-update-cmd and post-install-cmd? (tests must pass after composer install/update)',
      FALSE,
    );

    if (!$answer) {
      $this->ok('Skipped — add manually if needed (see README).');
      return;
    }

    // Ensure scripts key exists.
    if (!isset($data['scripts'])) {
      $data['scripts'] = [];
    }

    // Append to post-update-cmd.
    if (!is_array($postUpdate)) {
      $postUpdate = $postUpdate ? [$postUpdate] : [];
    }
    if (!in_array($crCmd, $postUpdate, TRUE)) {
      $postUpdate[] = $crCmd;
    }
    if (!in_array($smokeCmd, $postUpdate, TRUE)) {
      $postUpdate[] = $smokeCmd;
    }
    $data['scripts']['post-update-cmd'] = $postUpdate;

    // Append to post-install-cmd.
    if (!is_array($postInstall)) {
      $postInstall = $postInstall ? [$postInstall] : [];
    }
    if (!in_array($crCmd, $postInstall, TRUE)) {
      $postInstall[] = $crCmd;
    }
    if (!in_array($smokeCmd, $postInstall, TRUE)) {
      $postInstall[] = $smokeCmd;
    }
    $data['scripts']['post-install-cmd'] = $postInstall;

    $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    file_put_contents($composerFile, $encoded);

    $this->ok('Composer scripts added — smoke tests will run after install/update.');
  }

  /**
   * Installs Chromium system dependencies with fallback strategies.
   *
   * First tries `npx playwright install-deps chromium`. If that fails (common
   * when DDEV's apt repo keys are expired), falls back to installing the
   * required packages directly via apt-get.
   *
   * @return bool
   *   TRUE if dependencies were installed successfully.
   */
  private function installSystemDeps(string $playwrightDir, bool $quiet): bool {
    // Strategy 1: Official Playwright install-deps command.
    $installDeps = new Process(
      ['sudo', 'env', 'DEBIAN_FRONTEND=noninteractive', 'npx', 'playwright', 'install-deps', 'chromium'],
      $playwrightDir,
    );
    $installDeps->setTimeout(120);
    $installDeps->run();
    if ($installDeps->isSuccessful()) {
      if (!$quiet) {
        $this->ok('System dependencies installed.');
      }
      return TRUE;
    }

    // Strategy 2: Direct apt-get install of known Chromium dependencies.
    // This bypasses apt-get update (which fails on expired repo keys).
    if (!$quiet) {
      $this->io()->text('    <fg=yellow>Playwright install-deps failed, trying direct apt install...</>');
    }

    $packages = [
      'libnss3', 'libnspr4', 'libatk1.0-0', 'libatk-bridge2.0-0',
      'libcups2', 'libdrm2', 'libxkbcommon0', 'libxcomposite1',
      'libxdamage1', 'libxrandr2', 'libgbm1', 'libpango-1.0-0',
      'libcairo2', 'libasound2', 'libatspi2.0-0', 'libxshmfence1',
    ];

    $directInstall = new Process(
      array_merge(
        ['sudo', 'env', 'DEBIAN_FRONTEND=noninteractive', 'apt-get', 'install', '-y', '--no-install-recommends'],
        $packages,
      ),
      $playwrightDir,
    );
    $directInstall->setTimeout(120);
    $directInstall->run();
    if ($directInstall->isSuccessful()) {
      if (!$quiet) {
        $this->ok('System dependencies installed (direct apt-get).');
      }
      return TRUE;
    }

    // Strategy 3: Fix expired keys, update, then try again.
    if (!$quiet) {
      $this->io()->text('    <fg=yellow>Direct install failed, refreshing apt keys...</>');
    }

    $aptCmd = 'apt-get update --allow-insecure-repositories 2>/dev/null; '
      . 'apt-get install -y --no-install-recommends --allow-unauthenticated '
      . implode(' ', $packages);
    $fixKeys = new Process(
      ['sudo', 'bash', '-c', $aptCmd],
      $playwrightDir,
    );
    $fixKeys->setTimeout(120);
    $fixKeys->run();
    if ($fixKeys->isSuccessful()) {
      if (!$quiet) {
        $this->ok('System dependencies installed (with key workaround).');
      }
      return TRUE;
    }

    return FALSE;
  }

}
