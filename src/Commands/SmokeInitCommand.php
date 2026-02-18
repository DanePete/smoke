<?php

declare(strict_types=1);

namespace Drupal\smoke\Commands;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Process\Process;

/**
 * Initializes Smoke Tests for VS Code / Cursor integration.
 *
 * Creates project-level configuration for IDE Playwright extension support.
 */
final class SmokeInitCommand extends DrushCommands {

  /**
   * Constructs the SmokeInitCommand.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler service.
   */
  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('module_handler'),
    );
  }

  /**
   * Initializes Smoke for VS Code / Cursor Playwright extension.
   *
   * Creates:
   * - playwright.config.ts at project root
   * - playwright-smoke/suites/ directory for custom tests
   * - Example custom suite template
   * - .gitignore entries for test artifacts.
   */
  #[CLI\Command(name: 'smoke:init', aliases: ['smoke-init'])]
  #[CLI\Help(description: 'Initialize Smoke for VS Code / Cursor Playwright extension integration.')]
  #[CLI\Usage(name: 'drush smoke:init', description: 'Creates project-level Playwright config for IDE integration.')]
  #[CLI\Option(name: 'force', description: 'Overwrite existing files.')]
  public function init(array $options = ['force' => FALSE]): void {
    $force = (bool) $options['force'];
    $projectRoot = DRUPAL_ROOT . '/..';
    $modulePath = $this->moduleHandler->getModule('smoke')->getPath();
    $templatesPath = DRUPAL_ROOT . '/' . $modulePath . '/playwright/templates';

    $this->io()->title('Smoke Tests - IDE Integration Setup');
    $this->io()->text('Setting up VS Code / Cursor Playwright extension support...');
    $this->io()->newLine();

    $created = [];
    $skipped = [];

    // 1. Create playwright.config.ts at project root.
    $configDest = $projectRoot . '/playwright.config.ts';
    $configTemplate = $templatesPath . '/playwright.config.ts.template';

    if (!file_exists($configDest) || $force) {
      if (file_exists($configTemplate)) {
        $content = file_get_contents($configTemplate);
        file_put_contents($configDest, $content);
        $created[] = 'playwright.config.ts';
      }
    }
    else {
      $skipped[] = 'playwright.config.ts (exists, use --force to overwrite)';
    }

    // 2. Ensure project root has @playwright/test so the IDE extension can run tests.
    $npmInstall = new Process(
      ['npm', 'install', '--save-dev', '@playwright/test'],
      $projectRoot,
    );
    $npmInstall->setTimeout(120);
    $npmInstall->run();
    if ($npmInstall->isSuccessful()) {
      $created[] = 'Installed @playwright/test at project root (IDE extension can discover tests)';
    }
    else {
      $this->io()->warning('Could not run npm install at project root. Install manually: npm i --save-dev @playwright/test');
    }

    // 3. Create playwright-smoke/suites/ directory.
    $customSuitesDir = $projectRoot . '/playwright-smoke/suites';
    if (!is_dir($customSuitesDir)) {
      mkdir($customSuitesDir, 0755, TRUE);
      $created[] = 'playwright-smoke/suites/';
    }

    // 4. Copy example custom suite.
    $exampleDest = $customSuitesDir . '/example-custom.spec.ts';
    $exampleTemplate = $templatesPath . '/example-custom.spec.ts.template';

    if (!file_exists($exampleDest) || $force) {
      if (file_exists($exampleTemplate)) {
        $content = file_get_contents($exampleTemplate);
        file_put_contents($exampleDest, $content);
        $created[] = 'playwright-smoke/suites/example-custom.spec.ts';
      }
    }
    else {
      $skipped[] = 'example-custom.spec.ts (exists)';
    }

    // 5. Create smoke.suites.yml example.
    $yamlDest = $projectRoot . '/playwright-smoke/smoke.suites.yml';
    if (!file_exists($yamlDest) || $force) {
      $yamlContent = <<<YAML
# Custom Smoke Test Suite Definitions
#
# Define your custom test suites here. Each suite corresponds to a
# .spec.ts file in the suites/ directory.
#
# Example:
# agency_seo:
#   label: 'SEO Checks'
#   description: 'Validates meta tags, canonical URLs, and structured data.'
#   icon: search
#   weight: 100
#   dependencies:
#     - metatag

example_custom:
  label: 'Custom Tests'
  description: 'Example custom smoke tests. Modify or remove this.'
  icon: star
  weight: 100
YAML;
      file_put_contents($yamlDest, $yamlContent);
      $created[] = 'playwright-smoke/smoke.suites.yml';
    }
    else {
      $skipped[] = 'smoke.suites.yml (exists)';
    }

    // 6. Create/update .gitignore.
    $gitignoreDest = $projectRoot . '/playwright-smoke/.gitignore';
    if (!file_exists($gitignoreDest)) {
      $gitignoreContent = <<<GITIGNORE
# Playwright test artifacts
test-results/
playwright-report/
*.png
!**/*.spec.ts.png
.smoke-config.json
GITIGNORE;
      file_put_contents($gitignoreDest, $gitignoreContent);
      $created[] = 'playwright-smoke/.gitignore';
    }

    // 7. Update root .gitignore if it exists.
    $rootGitignore = $projectRoot . '/.gitignore';
    if (file_exists($rootGitignore)) {
      $content = file_get_contents($rootGitignore);
      $additions = [];

      if (strpos($content, 'test-results/') === FALSE) {
        $additions[] = 'test-results/';
      }
      if (strpos($content, 'playwright-report/') === FALSE) {
        $additions[] = 'playwright-report/';
      }

      if (!empty($additions)) {
        $addContent = "\n# Playwright test artifacts\n" . implode("\n", $additions) . "\n";
        file_put_contents($rootGitignore, $content . $addContent);
        $created[] = '.gitignore (updated)';
      }
    }

    // Output results.
    if (!empty($created)) {
      $this->io()->success('Created:');
      $this->io()->listing($created);
    }

    if (!empty($skipped)) {
      $this->io()->note('Skipped (already exist):');
      $this->io()->listing($skipped);
    }

    $this->io()->newLine();
    $this->io()->text('<fg=cyan;options=bold>Next steps:</>');
    $this->io()->newLine();
    $this->io()->text('  1. Open your project in VS Code or Cursor');
    $this->io()->text('  2. Install the "Playwright Test for VSCode" extension (if using VS Code)');
    $this->io()->text('  3. Open the Testing sidebar (flask icon)');
    $this->io()->text('  4. Click refresh - tests should appear');
    $this->io()->text('  5. Click play on any test to run it');
    $this->io()->newLine();
    $this->io()->text('<fg=cyan;options=bold>Adding custom tests:</>');
    $this->io()->newLine();
    $this->io()->text('  1. Create new .spec.ts files in playwright-smoke/suites/');
    $this->io()->text('  2. Define them in playwright-smoke/smoke.suites.yml');
    $this->io()->text('  3. Run: ddev drush cr (to detect new suites)');
    $this->io()->newLine();
    $this->io()->text('<fg=gray>See: playwright-smoke/suites/example-custom.spec.ts for patterns</>');
    $this->io()->newLine();
  }

}
