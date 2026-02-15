<?php

declare(strict_types=1);

namespace Drupal\smoke\Commands;

use Drupal\smoke\Service\ConfigGenerator;
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

  public function __construct(
    private readonly TestRunner $testRunner,
    private readonly ConfigGenerator $configGenerator,
  ) {
    parent::__construct();
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('smoke.test_runner'),
      $container->get('smoke.config_generator'),
    );
  }

  #[CLI\Command(name: 'smoke:setup')]
  #[CLI\Help(description: 'Set up Playwright from scratch: DDEV addon, browsers, npm deps, test config.')]
  public function setup(): void {
    $this->io()->newLine();
    $this->io()->text('  <options=bold>Smoke — Setup</>');
    $this->io()->text('  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
    $this->io()->newLine();

    $modulePath = $this->configGenerator->getModulePath();
    $playwrightDir = $modulePath . '/playwright';
    $projectRoot = DRUPAL_ROOT . '/..';

    // Step 1: Check DDEV.
    $this->step('Checking environment...');
    $isDdev = getenv('IS_DDEV_PROJECT') === 'true';
    if (!$isDdev) {
      $this->io()->error('This command must be run inside DDEV (ddev drush smoke:setup).');
      return;
    }
    $this->ok('DDEV detected.');

    // Step 2: Install DDEV Playwright addon if not present.
    $this->step('Checking for Playwright addon...');
    $hasAddon = is_file($projectRoot . '/.ddev/config.playwright.yml');

    if ($hasAddon) {
      $this->ok('Lullabot/ddev-playwright already installed.');
    }
    else {
      $this->io()->newLine();
      $this->io()->text('  <fg=yellow;options=bold>Playwright not installed yet.</>');
      $this->io()->text('  Run this single command on your <options=bold>host machine</>:');
      $this->io()->newLine();

      // Find the script path relative to project root.
      $scriptPath = str_replace($projectRoot . '/', '', $modulePath . '/scripts/host-setup.sh');
      $this->io()->text("    <options=bold>bash {$scriptPath}</>");
      $this->io()->newLine();
      $this->io()->text('  This installs the DDEV addon, browsers, and finishes setup automatically.');
      $this->io()->newLine();
      return;
    }

    // Step 3: Check browsers are installed.
    $this->step('Checking Playwright browsers...');
    $browserCheck = new Process(['npx', 'playwright', 'install', '--dry-run'], $playwrightDir);
    $browserCheck->setTimeout(30);
    // If browsers aren't installed, the DDEV addon handles it via install-playwright.
    // Just check if npx playwright works at all.
    $versionCheck = new Process(['npx', 'playwright', '--version'], $playwrightDir);
    $versionCheck->setTimeout(15);
    $versionCheck->run();
    if ($versionCheck->isSuccessful()) {
      $version = trim($versionCheck->getOutput());
      $this->ok("Playwright {$version}");
    }
    else {
      // Need npm install first.
      $this->warn('Playwright CLI not found. Installing npm deps first...');
    }

    // Step 4: Install npm dependencies.
    $this->step('Installing npm dependencies...');
    if (!is_file($playwrightDir . '/package.json')) {
      $this->io()->error('package.json not found at: ' . $playwrightDir);
      return;
    }

    $npm = new Process(['npm', 'install'], $playwrightDir);
    $npm->setTimeout(120);
    $npm->run();
    if ($npm->isSuccessful()) {
      $this->ok('Dependencies installed.');
    }
    else {
      $this->io()->error('npm install failed: ' . $npm->getErrorOutput());
      return;
    }

    // Step 5: Generate config.
    $this->step('Generating test config...');
    $configPath = $this->configGenerator->writeConfig();
    $this->ok('Config written.');

    // Step 6: Verify test user.
    $this->step('Verifying smoke_bot test user...');
    $password = \Drupal::state()->get('smoke.bot_password');
    if ($password) {
      $this->ok('smoke_bot ready.');
    }
    else {
      $this->warn('smoke_bot not found. Reinstall the module: drush pmu smoke && drush en smoke');
    }

    // Step 7: Sanity check — list tests.
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

    // Done.
    $this->io()->newLine();
    $this->io()->text('  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
    $this->io()->text('  <fg=green;options=bold>Setup complete.</>');
    $this->io()->newLine();
    $this->io()->text('  Commands:');
    $this->io()->text('    <options=bold>drush smoke</>               Run all tests');
    $this->io()->text('    <options=bold>drush smoke:list</>          See detected suites');
    $this->io()->text('    <options=bold>drush smoke:suite webform</>  Run one suite');
    $this->io()->newLine();
  }

  private function step(string $message): void {
    $this->io()->text("  <fg=blue>▸</> {$message}");
  }

  private function ok(string $message): void {
    $this->io()->text("    <fg=green>✓</> {$message}");
  }

  private function warn(string $message): void {
    $this->io()->text("    <fg=yellow>⚠</> {$message}");
  }

}
