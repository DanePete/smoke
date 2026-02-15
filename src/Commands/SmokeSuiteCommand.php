<?php

declare(strict_types=1);

namespace Drupal\smoke\Commands;

use Drupal\smoke\Service\ModuleDetector;
use Drupal\smoke\Service\TestRunner;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Process\Process;

/**
 * Runs a single smoke test suite by name.
 */
final class SmokeSuiteCommand extends DrushCommands {

  public function __construct(
    private readonly TestRunner $testRunner,
    private readonly ModuleDetector $moduleDetector,
  ) {
    parent::__construct();
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('smoke.test_runner'),
      $container->get('smoke.module_detector'),
    );
  }

  #[CLI\Command(name: 'smoke:suite')]
  #[CLI\Argument(name: 'suite', description: 'Suite to run (core_pages, auth, webform, commerce, search, health, sitemap, content, accessibility).')]
  #[CLI\Help(description: 'Run a single smoke test suite.')]
  #[CLI\Usage(name: 'drush smoke:suite webform', description: 'Run only the webform tests.')]
  #[CLI\Usage(name: 'drush smoke:suite core_pages --target=https://test-mysite.pantheonsite.io', description: 'Test a remote site.')]
  #[CLI\Option(name: 'target', description: 'Remote URL to test against.')]
  public function suite(string $suite, array $options = ['target' => '']): void {
    if (!$this->testRunner->isSetup()) {
      $projectRoot = DRUPAL_ROOT . '/..';
      $isDdev = getenv('IS_DDEV_PROJECT') === 'true';
      $hasAddon = is_file($projectRoot . '/.ddev/config.playwright.yml');

      if ($isDdev && $hasAddon) {
        $this->io()->text('  <fg=cyan>Setting up Playwright (first run)...</>');
        $this->io()->newLine();
        $process = new \Symfony\Component\Process\Process(
          ['drush', 'smoke:setup'],
          $projectRoot,
        );
        $process->setTimeout(180);
        $process->run(function ($type, $buffer): void {
          $this->io()->write($buffer);
        });
      }

      if (!$this->testRunner->isSetup()) {
        $this->io()->error('Playwright is not set up. Run: bash web/modules/contrib/smoke/scripts/host-setup.sh');
        return;
      }
    }

    $labels = ModuleDetector::suiteLabels();
    if (!isset($labels[$suite])) {
      $this->io()->error("Unknown suite: {$suite}");
      $this->io()->text('Available suites: ' . implode(', ', array_keys($labels)));
      return;
    }

    $target = $options['target'] ?: NULL;
    $baseUrl = $target ?: (getenv('DDEV_PRIMARY_URL') ?: '');

    $this->io()->newLine();
    $this->io()->text("  <options=bold>{$labels[$suite]}</>");
    if ($target) {
      $this->io()->text("  <fg=magenta;options=bold>REMOTE</>  {$target}");
    }
    elseif ($baseUrl) {
      $this->io()->text("  <fg=gray>{$baseUrl}</>");
    }
    $this->io()->text('  ───────────────────────────────────────');
    $this->io()->newLine();

    $this->io()->text('  <fg=cyan>⠿</> Running...');
    $this->io()->newLine();

    $results = $this->testRunner->run($suite, $target);
    $suiteData = $results['suites'][$suite] ?? NULL;

    if (!$suiteData) {
      $this->io()->warning("No results returned for suite: {$suite}");
      return;
    }

    foreach (($suiteData['tests'] ?? []) as $test) {
      $icon = ($test['status'] ?? '') === 'passed' ? '<fg=green>✓</>' : '<fg=red>✕</>';
      $time = number_format(($test['duration'] ?? 0) / 1000, 1) . 's';
      $this->io()->text("  {$icon} {$test['title']}  <fg=gray>{$time}</>");

      if (($test['status'] ?? '') === 'failed' && !empty($test['error'])) {
        $error = (string) preg_replace('/\x1b\[[0-9;]*m/', '', $test['error']);
        $this->io()->text('    <fg=red>' . substr($error, 0, 200) . '</>');
      }
    }

    $passed = (int) ($suiteData['passed'] ?? 0);
    $failed = (int) ($suiteData['failed'] ?? 0);
    $duration = number_format(($suiteData['duration'] ?? 0) / 1000, 1);
    $this->io()->newLine();
    $this->io()->text('  ───────────────────────────────────────');

    if ($failed === 0 && $passed > 0) {
      $this->io()->text("  <fg=green;options=bold>PASSED</>  {$passed} tests in {$duration}s");
    }
    elseif ($failed > 0) {
      $this->io()->text("  <fg=red;options=bold>FAILED</>  {$failed} of " . ($passed + $failed) . " in {$duration}s");
    }

    // Suite-specific links.
    if ($baseUrl) {
      $this->io()->newLine();
      if ($suite === 'webform') {
        $this->io()->text("  <fg=gray>View form:</>       {$baseUrl}/webform/smoke_test");
        $this->io()->text("  <fg=gray>Submissions:</>     {$baseUrl}/admin/structure/webform/manage/smoke_test/results/submissions");
      }
      elseif ($suite === 'auth') {
        $this->io()->text("  <fg=gray>Login page:</>      {$baseUrl}/user/login");
      }
      elseif ($suite === 'commerce') {
        $this->io()->text("  <fg=gray>Products:</>        {$baseUrl}/admin/commerce/products");
        $this->io()->text("  <fg=gray>Orders:</>          {$baseUrl}/admin/commerce/orders");
      }
      elseif ($suite === 'health') {
        $this->io()->text("  <fg=gray>Status report:</>   {$baseUrl}/admin/reports/status");
        $this->io()->text("  <fg=gray>Recent log:</>      {$baseUrl}/admin/reports/dblog");
      }
      elseif ($suite === 'sitemap') {
        $this->io()->text("  <fg=gray>Sitemap:</>         {$baseUrl}/sitemap.xml");
      }
      elseif ($suite === 'content') {
        $this->io()->text("  <fg=gray>Add content:</>     {$baseUrl}/node/add/page");
      }
      $this->io()->text("  <fg=gray>Dashboard:</>       {$baseUrl}/admin/reports/smoke");
    }

    // Remote explanation for this suite.
    $target = $options['target'] ?? '';
    if ($target) {
      $skippedReason = match ($suite) {
        'auth' => 'smoke_bot login tests auto-skip (no test user on remote)',
        'health' => 'Admin checks (status report, cron, dblog) auto-skip (no smoke_bot on remote)',
        'content' => 'Content creation auto-skips (no smoke_bot on remote)',
        'webform' => 'Tries to load smoke_test form — skips on 404. Deploy config to enable.',
        default => '',
      };
      if ($skippedReason) {
        $this->io()->newLine();
        $this->io()->text("  <fg=cyan>Remote note:</> {$skippedReason}");
      }
    }

    $this->io()->newLine();
  }

}
