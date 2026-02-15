<?php

declare(strict_types=1);

namespace Drupal\smoke\Commands;

use Drupal\smoke\Service\ModuleDetector;
use Drupal\smoke\Service\TestRunner;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists all detected smoke test suites and their status.
 */
final class SmokeListCommand extends DrushCommands {

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

  #[CLI\Command(name: 'smoke:list', aliases: ['smoke:ls'])]
  #[CLI\Help(description: 'List all detected smoke test suites.')]
  public function list(): void {
    $detected = $this->moduleDetector->detect();
    $labels = ModuleDetector::suiteLabels();
    $settings = \Drupal::config('smoke.settings');
    $enabledSuites = $settings->get('suites') ?? [];
    $lastResults = $this->testRunner->getLastResults();

    $this->io()->newLine();
    $this->io()->text('  <options=bold>Detected Test Suites</>');
    $this->io()->text('  ───────────────────────────────────────────');
    $this->io()->newLine();

    foreach ($labels as $id => $label) {
      $isDetected = !empty($detected[$id]['detected']);
      $isEnabled = $enabledSuites[$id] ?? TRUE;
      $lastResult = $lastResults['suites'][$id] ?? NULL;

      // Status icon.
      if (!$isDetected) {
        $icon = '<fg=gray>○</>';
        $status = '<fg=gray>not found</>';
      }
      elseif (!$isEnabled) {
        $icon = '<fg=yellow>—</>';
        $status = '<fg=yellow>disabled</>';
      }
      elseif ($lastResult && ($lastResult['failed'] ?? 0) === 0) {
        $icon = '<fg=green>✓</>';
        $passed = (int) ($lastResult['passed'] ?? 0);
        $status = "<fg=green>{$passed} passed</>";
      }
      elseif ($lastResult) {
        $icon = '<fg=red>✕</>';
        $failed = (int) ($lastResult['failed'] ?? 0);
        $status = "<fg=red>{$failed} failed</>";
      }
      else {
        $icon = '<fg=blue>●</>';
        $status = '<fg=blue>ready</>';
      }

      $paddedLabel = str_pad($label, 18);
      $this->io()->text("  {$icon} {$paddedLabel} {$status}");
    }

    $this->io()->newLine();

    // Setup status.
    if ($this->testRunner->isSetup()) {
      $this->io()->text('  <fg=green>✓</> Playwright: installed');
    }
    else {
      $this->io()->text('  <fg=red>✕</> Playwright: not installed — run <options=bold>drush smoke:setup</>');
    }

    $this->io()->newLine();
  }

}
