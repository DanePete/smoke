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
  #[CLI\Option(name: 'quiet', description: 'Suppress output (used by DDEV post-start hook).')]
  public function setup(array $options = ['quiet' => FALSE]): void {
    $quiet = (bool) $options['quiet'];

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

    // Step 2: Install DDEV Playwright addon if not present.
    if (!$quiet) {
      $this->step('Checking for Playwright addon...');
    }
    $hasAddon = is_file($projectRoot . '/.ddev/config.playwright.yml');

    if ($hasAddon) {
      if (!$quiet) {
        $this->ok('Lullabot/ddev-playwright already installed.');
      }
    }
    else {
      if (!$quiet) {
        $this->io()->newLine();
        $this->io()->text('  <fg=yellow;options=bold>Playwright not installed yet.</>');
        $this->io()->text('  Run this single command on your <options=bold>host machine</>:');
        $this->io()->newLine();
        $scriptPath = str_replace($projectRoot . '/', '', $modulePath . '/scripts/host-setup.sh');
        $this->io()->text("    <options=bold>bash {$scriptPath}</>");
        $this->io()->newLine();
        $this->io()->text('  This installs the DDEV addon, browsers, and finishes setup automatically.');
        $this->io()->newLine();
      }
      return;
    }

    // Step 3: Check browsers are installed.
    if (!$quiet) {
      $this->step('Checking Playwright browsers...');
    }
    $versionCheck = new Process(['npx', 'playwright', '--version'], $playwrightDir);
    $versionCheck->setTimeout(15);
    $versionCheck->run();
    if ($versionCheck->isSuccessful() && !$quiet) {
      $version = trim($versionCheck->getOutput());
      $this->ok("Playwright {$version}");
    }
    elseif (!$quiet) {
      $this->warn('Playwright CLI not found. Installing npm deps first...');
    }

    // Step 4: Install npm dependencies.
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

    // Step 5: Generate config.
    if (!$quiet) {
      $this->step('Generating test config...');
    }
    $this->configGenerator->writeConfig();
    if (!$quiet) {
      $this->ok('Config written.');
    }

    // Step 6: Verify test user and ensure permissions.
    if (!$quiet) {
      $this->step('Verifying smoke_bot test user...');
    }
    $password = \Drupal::state()->get('smoke.bot_password');
    if ($password) {
      if (!$quiet) {
        $this->ok('smoke_bot ready.');
      }
      // Ensure content permissions are granted (added in later versions).
      $role = \Drupal\user\Entity\Role::load('smoke_bot');
      if ($role) {
        $contentPerms = ['create page content', 'edit own page content', 'delete own page content'];
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
      $this->warn('smoke_bot not found. Reinstall the module: drush pmu smoke && drush en smoke');
    }

    // Step 7: Sanity check — list tests.
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
      $this->io()->newLine();
      $this->io()->text('  Commands:');
      $this->io()->text('    <options=bold>drush smoke</>               Run all tests');
      $this->io()->text('    <options=bold>drush smoke:list</>          See detected suites');
      $this->io()->text('    <options=bold>drush smoke:suite webform</>  Run one suite');
      $this->io()->newLine();
    }
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
